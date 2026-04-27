<?php

namespace App\Services\Notifications;

use App\Models\AreaLaunchAlertRun;
use App\Models\LeadCapture;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Venue;
use App\Support\OfferRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class AreaLaunchAlertService
{
    public function previewForMerchant(Merchant $merchant): array
    {
        $merchant->loadMissing(['venues', 'wallet']);

        $venue = $this->primaryVenue($merchant);
        $postcodePrefix = $this->postcodePrefix($venue?->postcode);
        $journeyType = OfferRules::isFoodBusiness((string) $merchant->business_type) ? 'food' : 'nightlife';

        $userRecipients = $this->userAudience($postcodePrefix);
        $leadRecipients = $this->leadAudience($postcodePrefix, $venue?->city, $journeyType);

        $merged = collect()
            ->merge($userRecipients)
            ->merge($leadRecipients)
            ->keyBy(fn (array $recipient) => strtolower((string) $recipient['email']))
            ->values();

        return [
            'merchant_id' => $merchant->id,
            'merchant_name' => $merchant->business_name,
            'journey_type' => $journeyType,
            'coverage' => [
                'venue_id' => $venue?->id,
                'venue_name' => $venue?->name,
                'city' => $venue?->city,
                'postcode' => $venue?->postcode,
                'postcode_prefix' => $postcodePrefix,
            ],
            'offer' => [
                'offer_type' => $venue?->offer_type,
                'ride_trip_type' => $venue?->ride_trip_type,
                'offer_value' => $venue?->offer_value !== null ? number_format((float) $venue->offer_value, 2, '.', '') : null,
                'minimum_order' => $venue?->minimum_order !== null ? number_format((float) $venue->minimum_order, 2, '.', '') : null,
            ],
            'audience' => [
                'registered_users' => $userRecipients->count(),
                'waiting_list_leads' => $leadRecipients->count(),
                'unique_email_count' => $merged->count(),
                'sample_recipients' => $merged->take(5)->map(fn (array $item) => [
                    'email' => $this->maskEmail((string) $item['email']),
                    'source' => $item['source'],
                    'postcode' => $item['postcode'] ?? null,
                ])->values()->all(),
            ],
            'recent_runs' => AreaLaunchAlertRun::query()
                ->where('merchant_id', $merchant->id)
                ->latest()
                ->take(3)
                ->get()
                ->map(fn (AreaLaunchAlertRun $run) => $this->runPayload($run))
                ->values()
                ->all(),
        ];
    }

    public function triggerForMerchant(Merchant $merchant, ?User $actor = null, string $source = 'manual_admin', ?string $notes = null): array
    {
        $preview = $this->previewForMerchant($merchant);
        $venue = $this->primaryVenue($merchant);
        $postcodePrefix = $preview['coverage']['postcode_prefix'] ?? null;
        $journeyType = $preview['journey_type'];

        $recipients = collect()
            ->merge($this->userAudience($postcodePrefix))
            ->merge($this->leadAudience($postcodePrefix, $venue?->city, $journeyType))
            ->keyBy(fn (array $recipient) => strtolower((string) $recipient['email']))
            ->values();

        $attempted = 0;
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $email = strtolower((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $attempted++;

            try {
                Mail::raw($this->emailBody($merchant, $venue), function ($message) use ($email, $merchant, $venue) {
                    $message->to($email)->subject($this->emailSubject($merchant, $venue));
                });
                $sent++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $status = $attempted === 0
            ? 'skipped'
            : ($failed === 0 ? 'sent' : ($sent > 0 ? 'partial' : 'failed'));

        $run = AreaLaunchAlertRun::create([
            'merchant_id' => $merchant->id,
            'venue_id' => $venue?->id,
            'triggered_by_user_id' => $actor?->id,
            'trigger_source' => $source,
            'postcode_prefix' => $postcodePrefix,
            'city' => $venue?->city,
            'audience_breakdown' => [
                'registered_users' => $preview['audience']['registered_users'],
                'waiting_list_leads' => $preview['audience']['waiting_list_leads'],
                'unique_email_count' => $preview['audience']['unique_email_count'],
                'sample_recipients' => $preview['audience']['sample_recipients'],
            ],
            'attempted_count' => $attempted,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'status' => $status,
            'notes' => $notes,
            'sent_at' => now(),
        ]);

        return [
            'run' => $this->runPayload($run->fresh(['merchant', 'venue', 'triggeredBy'])),
            'preview' => $preview,
        ];
    }

    public function dashboardPayload(int $limit = 8): array
    {
        $recentRuns = AreaLaunchAlertRun::query()
            ->with(['merchant', 'venue', 'triggeredBy'])
            ->latest()
            ->take(max(1, $limit))
            ->get();

        return [
            'summary' => [
                'total_runs' => AreaLaunchAlertRun::count(),
                'sent_runs' => AreaLaunchAlertRun::where('status', 'sent')->count(),
                'partial_runs' => AreaLaunchAlertRun::where('status', 'partial')->count(),
                'skipped_runs' => AreaLaunchAlertRun::where('status', 'skipped')->count(),
                'emails_sent' => (int) AreaLaunchAlertRun::sum('sent_count'),
            ],
            'items' => $recentRuns->map(fn (AreaLaunchAlertRun $run) => $this->runPayload($run))->values()->all(),
        ];
    }

    public function runPayload(AreaLaunchAlertRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger_source' => $run->trigger_source,
            'postcode_prefix' => $run->postcode_prefix,
            'city' => $run->city,
            'attempted_count' => $run->attempted_count,
            'sent_count' => $run->sent_count,
            'failed_count' => $run->failed_count,
            'notes' => $run->notes,
            'sent_at' => optional($run->sent_at)?->toIso8601String(),
            'created_at' => optional($run->created_at)?->toIso8601String(),
            'merchant' => $run->merchant ? [
                'id' => $run->merchant->id,
                'business_name' => $run->merchant->business_name,
            ] : null,
            'venue' => $run->venue ? [
                'id' => $run->venue->id,
                'name' => $run->venue->name,
            ] : null,
            'triggered_by' => $run->triggeredBy ? [
                'id' => $run->triggeredBy->id,
                'name' => $run->triggeredBy->name,
            ] : null,
            'audience_breakdown' => $run->audience_breakdown ?: [],
        ];
    }

    private function primaryVenue(Merchant $merchant): ?Venue
    {
        if ($merchant->relationLoaded('venues')) {
            return $merchant->venues->sortBy('id')->first();
        }

        return $merchant->venues()->orderBy('id')->first();
    }

    private function userAudience(?string $postcodePrefix): Collection
    {
        if (! $postcodePrefix) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($query) use ($postcodePrefix) {
                $query->whereRaw('UPPER(postcode) like ?', [$postcodePrefix . '%']);
            })
            ->get(['email', 'postcode'])
            ->map(fn (User $user) => [
                'email' => strtolower((string) $user->email),
                'postcode' => $user->postcode,
                'source' => 'registered_user',
            ]);
    }

    private function leadAudience(?string $postcodePrefix, ?string $city, string $journeyType): Collection
    {
        if (! $postcodePrefix && ! $city) {
            return collect();
        }

        $query = LeadCapture::query()
            ->where('contact_consent', true)
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            ->where('journey_type', $journeyType)
            ->whereIn('status', ['new', 'contacted', 'converted'])
            ->where(function ($builder) use ($postcodePrefix, $city) {
                if ($postcodePrefix) {
                    $builder->whereRaw('UPPER(postcode) like ?', [$postcodePrefix . '%']);
                }

                if ($city) {
                    $method = $postcodePrefix ? 'orWhereRaw' : 'whereRaw';
                    $builder->{$method}('LOWER(city) = ?', [mb_strtolower($city)]);
                }
            });

        return $query->get(['customer_email', 'postcode'])
            ->map(fn (LeadCapture $lead) => [
                'email' => strtolower((string) $lead->customer_email),
                'postcode' => $lead->postcode,
                'source' => 'waiting_list',
            ]);
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

    private function emailSubject(Merchant $merchant, ?Venue $venue): string
    {
        return sprintf('New TALK to CAS venue near you: %s', $venue?->name ?? $merchant->business_name);
    }

    private function emailBody(Merchant $merchant, ?Venue $venue): string
    {
        $frontendUrl = rtrim((string) config('talktocas.operations.frontend_url', config('app.url')), '/');
        $journeyCopy = OfferRules::isFoodBusiness((string) $merchant->business_type)
            ? 'Fresh food offers are now available near your area.'
            : 'A new going-out venue is now live near your area.';

        $offerLine = $venue?->offer_type === 'food'
            ? sprintf('Offer: £%s off food%s.', number_format((float) ($venue?->offer_value ?? 0), 2, '.', ''), $venue?->minimum_order ? ' on orders from £' . number_format((float) $venue->minimum_order, 2, '.', '') : '')
            : sprintf('Offer: £%s %s.', number_format((float) ($venue?->offer_value ?? 0), 2, '.', ''), $venue?->ride_trip_type === 'to_and_from' ? 'ride voucher for 2 trips (to-and-from)' : 'ride voucher for 1 trip (to venue)');

        return trim(sprintf(
            "%s\n\n%s\nVenue: %s\nArea: %s%s\n%s\n\nOpen TALK to CAS: %s/welcome\n\nYou are receiving this because you joined the waiting list or registered in this postcode area.",
            $journeyCopy,
            $merchant->business_name,
            $venue?->name ?? $merchant->business_name,
            $venue?->city ?: 'Local area',
            $venue?->postcode ? ' (' . $venue->postcode . ')' : '',
            $offerLine,
            $frontendUrl,
        ));
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$name, $domain] = explode('@', $email, 2);
        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));

        return $visible . str_repeat('*', max(2, mb_strlen($name) - mb_strlen($visible))) . '@' . $domain;
    }
}
