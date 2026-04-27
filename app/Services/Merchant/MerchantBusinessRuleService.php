<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MerchantBusinessRuleService
{
    public function evaluateRegistration(array $payload, ?Merchant $ignoreMerchant = null): array
    {
        $minimumTopUpAmount = (float) config('talktocas.merchant_rules.minimum_top_up_amount', 30);
        $requestedPlan = Arr::get($payload, 'requested_plan', 'free_trial') === 'payg' ? 'payg' : 'free_trial';
        $normalizedAddress = $this->normalizeTrialAddress(
            Arr::get($payload, 'address'),
            Arr::get($payload, 'city'),
            Arr::get($payload, 'postcode')
        );

        $blockedKeywords = $this->detectOneOffEventKeywords(
            Arr::get($payload, 'business_name'),
            Arr::get($payload, 'venue_description')
        );

        $sameAddressMerchant = $this->findPriorFreeTrialMerchant($normalizedAddress, $ignoreMerchant?->id);
        $freeTrialAvailable = $sameAddressMerchant === null && $blockedKeywords === [];

        $freeTrialStatus = 'eligible';
        $freeTrialIneligibleReason = null;
        $message = sprintf(
            'Free trial available for this venue. If you later move to PAY-AS-YOU-GO, the minimum top-up is £%.2f.',
            $minimumTopUpAmount
        );

        if ($sameAddressMerchant) {
            $freeTrialStatus = 'blocked_same_address';
            $freeTrialIneligibleReason = 'same_address_previous_trial';
            $message = sprintf(
                '⚠️ NO MORE FREE TRIAL! This venue address has already used a TALK to CAS free trial. Please continue on PAY-AS-YOU-GO with a minimum £%.2f top-up.',
                $minimumTopUpAmount
            );
        } elseif ($blockedKeywords !== []) {
            $freeTrialStatus = 'blocked_one_off_event';
            $freeTrialIneligibleReason = 'one_off_event_keywords';
            $message = sprintf(
                '⚠️ FREE TRIAL IS NOT AVAILABLE FOR 1 OFF EVENTS. Continue on PAY-AS-YOU-GO with a minimum £%.2f top-up.',
                $minimumTopUpAmount
            );
        } elseif ($requestedPlan === 'payg') {
            $message = sprintf(
                'PAY-AS-YOU-GO selected. Your first wallet top-up must be at least £%.2f.',
                $minimumTopUpAmount
            );
        }

        return [
            'requested_plan' => $requestedPlan,
            'resolved_plan' => $requestedPlan === 'free_trial' && $freeTrialAvailable ? 'free_trial' : 'payg',
            'free_trial_available' => $freeTrialAvailable,
            'free_trial_status' => $freeTrialStatus,
            'free_trial_ineligible_reason' => $freeTrialIneligibleReason,
            'free_trial_message' => $message,
            'blocked_keywords' => $blockedKeywords,
            'normalized_trial_address' => $normalizedAddress,
            'minimum_top_up_amount' => number_format($minimumTopUpAmount, 2, '.', ''),
            'same_address_match' => $sameAddressMerchant ? [
                'merchant_id' => $sameAddressMerchant->id,
                'business_name' => $sameAddressMerchant->business_name,
                'joined_at' => optional($sameAddressMerchant->created_at)?->toIso8601String(),
            ] : null,
        ];
    }

    public function minimumTopUpAmount(): float
    {
        return (float) config('talktocas.merchant_rules.minimum_top_up_amount', 30);
    }

    private function findPriorFreeTrialMerchant(?string $normalizedAddress, ?int $ignoreMerchantId = null): ?Merchant
    {
        if (blank($normalizedAddress)) {
            return null;
        }

        return Merchant::query()
            ->when($ignoreMerchantId, fn ($query) => $query->where('id', '!=', $ignoreMerchantId))
            ->where('onboarding_plan', 'free_trial')
            ->where('normalized_trial_address', $normalizedAddress)
            ->orderBy('id')
            ->first();
    }

    private function normalizeTrialAddress(?string $address, ?string $city, ?string $postcode): ?string
    {
        $address = Str::upper(trim((string) $address));
        $city = Str::upper(trim((string) $city));
        $postcode = Str::upper(preg_replace('/\s+/', '', trim((string) $postcode)) ?: '');

        if ($address === '' && $city === '') {
            return null;
        }

        $combined = collect([$address, $city, $postcode])
            ->filter(fn ($value) => $value !== '')
            ->map(fn ($value) => preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: '')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(' | ');

        return $combined !== '' ? $combined : null;
    }

    private function detectOneOffEventKeywords(?string $businessName, ?string $venueDescription): array
    {
        $haystack = Str::lower(trim(implode(' ', array_filter([
            (string) $businessName,
            (string) $venueDescription,
        ]))));

        if ($haystack === '') {
            return [];
        }

        $keywords = collect(config('talktocas.merchant_rules.one_off_event_keywords', []))
            ->map(fn ($keyword) => Str::lower(trim((string) $keyword)))
            ->filter()
            ->unique()
            ->values();

        return $keywords
            ->filter(function (string $keyword) use ($haystack) {
                return str_contains($haystack, $keyword);
            })
            ->values()
            ->all();
    }
}
