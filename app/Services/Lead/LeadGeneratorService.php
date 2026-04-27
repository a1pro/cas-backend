<?php

namespace App\Services\Lead;

use App\Models\LeadCapture;
use App\Models\Merchant;
use App\Models\User;
use App\Support\OfferRules;
use Illuminate\Database\Eloquent\Builder;

class LeadGeneratorService
{
    public function create(?User $user, array $payload): LeadCapture
    {
        $postcode = isset($payload['postcode']) ? strtoupper(trim((string) $payload['postcode'])) : null;
        $city = isset($payload['city']) && trim((string) $payload['city']) !== ''
            ? trim((string) $payload['city'])
            : null;

        $lead = LeadCapture::create([
            'user_id' => $user?->id,
            'customer_name' => trim((string) ($payload['customer_name'] ?? '')),
            'customer_email' => $this->nullableTrim($payload['customer_email'] ?? null),
            'customer_phone' => $this->nullableTrim($payload['customer_phone'] ?? null),
            'postcode' => $postcode,
            'city' => $city,
            'journey_type' => $payload['journey_type'],
            'desired_venue_name' => $this->nullableTrim($payload['desired_venue_name'] ?? null),
            'desired_category' => $this->nullableTrim($payload['desired_category'] ?? null),
            'source' => $payload['source'] ?? 'manual',
            'status' => 'new',
            'notes' => $this->nullableTrim($payload['notes'] ?? null),
            'contact_consent' => (bool) ($payload['contact_consent'] ?? false),
            'metadata' => [
                'flow_type' => $payload['flow_type'] ?? null,
                'utm_source' => $payload['utm_source'] ?? null,
                'submitted_from' => $payload['submitted_from'] ?? 'web',
            ],
        ]);

        $matchedMerchant = $this->bestMerchantMatchForLead($lead);
        if ($matchedMerchant) {
            $lead->forceFill([
                'matched_merchant_id' => $matchedMerchant->id,
                'notified_at' => now(),
            ])->save();
        }

        return $lead->fresh(['matchedMerchant']);
    }

    public function payload(LeadCapture $lead): array
    {
        return [
            'id' => $lead->id,
            'customer_name' => $lead->customer_name,
            'customer_email' => $lead->customer_email,
            'customer_phone' => $lead->customer_phone,
            'postcode' => $lead->postcode,
            'city' => $lead->city,
            'journey_type' => $lead->journey_type,
            'desired_venue_name' => $lead->desired_venue_name,
            'desired_category' => $lead->desired_category,
            'source' => $lead->source,
            'status' => $lead->status,
            'notes' => $lead->notes,
            'contact_consent' => (bool) $lead->contact_consent,
            'matched_merchant' => $lead->matchedMerchant ? [
                'id' => $lead->matchedMerchant->id,
                'business_name' => $lead->matchedMerchant->business_name,
                'business_type' => $lead->matchedMerchant->business_type,
            ] : null,
            'created_at' => optional($lead->created_at)?->toIso8601String(),
            'notified_at' => optional($lead->notified_at)?->toIso8601String(),
        ];
    }

    public function summaryForMerchant(Merchant $merchant, int $limit = 5): array
    {
        $venue = $merchant->venues()->orderBy('id')->first();
        $journeyType = OfferRules::isFoodBusiness((string) $merchant->business_type) ? 'food' : 'nightlife';

        $query = LeadCapture::query()
            ->where('journey_type', $journeyType)
            ->where(function (Builder $builder) use ($merchant, $venue) {
                $matched = false;

                if ($venue?->city) {
                    $builder->where('city', $venue->city);
                    $matched = true;
                }

                $prefix = $this->postcodePrefix($venue?->postcode);
                if ($prefix) {
                    $method = $matched ? 'orWhere' : 'where';
                    $builder->{$method}('postcode', 'like', $prefix . '%');
                    $matched = true;
                }

                if (! $matched) {
                    $builder->where('matched_merchant_id', $merchant->id);
                }
            });

        $recentWindow = now()->subDays((int) config('talktocas.lead_generator.recent_days', 14));
        $openQuery = (clone $query)->whereIn('status', ['new', 'contacted']);

        $recentLeads = (clone $query)
            ->latest()
            ->take(max(1, $limit))
            ->get()
            ->map(fn (LeadCapture $lead) => $this->payload($lead))
            ->values()
            ->all();

        return [
            'enabled' => (bool) config('talktocas.lead_generator.enabled', true),
            'journey_type' => $journeyType,
            'summary' => [
                'recent_count' => (clone $query)->where('created_at', '>=', $recentWindow)->count(),
                'open_count' => (clone $openQuery)->count(),
                'matched_count' => (clone $query)->whereNotNull('matched_merchant_id')->count(),
                'contacted_count' => (clone $query)->where('status', 'contacted')->count(),
            ],
            'coverage' => [
                'city' => $venue?->city,
                'postcode_prefix' => $this->postcodePrefix($venue?->postcode),
            ],
            'recent_leads' => $recentLeads,
        ];
    }

    public function bestMerchantMatchForLead(LeadCapture $lead): ?Merchant
    {
        $journeyType = $lead->journey_type === 'food' ? 'food' : 'nightlife';
        $postcodePrefix = $this->postcodePrefix($lead->postcode);

        return Merchant::query()
            ->where('status', 'active')
            ->with('venues')
            ->get()
            ->filter(function (Merchant $merchant) use ($lead, $journeyType, $postcodePrefix) {
                $isFood = OfferRules::isFoodBusiness((string) $merchant->business_type);
                if (($journeyType === 'food') !== $isFood) {
                    return false;
                }

                $venue = $merchant->venues->sortBy('id')->first();
                if (! $venue) {
                    return false;
                }

                if ($lead->city && $venue->city && strcasecmp((string) $lead->city, (string) $venue->city) === 0) {
                    return true;
                }

                $venuePrefix = $this->postcodePrefix($venue->postcode);
                return $postcodePrefix && $venuePrefix && strcasecmp($postcodePrefix, $venuePrefix) === 0;
            })
            ->sortByDesc(fn (Merchant $merchant) => (float) ($merchant->wallet?->balance ?? 0))
            ->first();
    }

    private function postcodePrefix(?string $postcode): ?string
    {
        if (! $postcode) {
            return null;
        }

        $clean = strtoupper(trim($postcode));
        if ($clean === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $clean);
        return $parts[0] ?? $clean;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
