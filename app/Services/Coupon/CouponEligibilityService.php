<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Collection;

class CouponEligibilityService
{
    public function profilePayload(?User $user = null, array $overrides = []): array
    {
        $uberExisting = array_key_exists('is_uber_existing_customer', $overrides)
            ? $this->normaliseNullableBoolean($overrides['is_uber_existing_customer'])
            : $this->normaliseNullableBoolean($user?->is_uber_existing_customer);

        $ubereatsExisting = array_key_exists('is_ubereats_existing_customer', $overrides)
            ? $this->normaliseNullableBoolean($overrides['is_ubereats_existing_customer'])
            : $this->normaliseNullableBoolean($user?->is_ubereats_existing_customer);

        return [
            'is_uber_existing_customer' => $uberExisting,
            'is_ubereats_existing_customer' => $ubereatsExisting,
            'is_uber_new_customer' => $uberExisting === null ? null : ! $uberExisting,
            'is_ubereats_new_customer' => $ubereatsExisting === null ? null : ! $ubereatsExisting,
            'provider_states' => [
                'uber' => $this->statePayload('uber', $uberExisting),
                'ubereats' => $this->statePayload('ubereats', $ubereatsExisting),
            ],
            'profile_completion_message' => $this->profileCompletionMessage($uberExisting, $ubereatsExisting),
            'updated_at' => optional($user?->provider_profile_updated_at)?->toIso8601String(),
        ];
    }

    public function recommendForVenue(
        Venue $venue,
        string $journeyType,
        ?User $user = null,
        ?float $basketTotal = null,
        array $overrides = []
    ): ?array {
        $couponJourneyType = $journeyType === 'food' ? 'order_food' : 'going_out';
        $provider = $journeyType === 'food' ? 'ubereats' : 'uber';
        $existingCustomer = $journeyType === 'food'
            ? $this->normaliseNullableBoolean($overrides['is_ubereats_existing_customer'] ?? $user?->is_ubereats_existing_customer)
            : $this->normaliseNullableBoolean($overrides['is_uber_existing_customer'] ?? $user?->is_uber_existing_customer);

        $providers = $journeyType === 'food' ? ['ubereats', 'manual'] : ['uber', 'manual'];

        /** @var Collection<int, Coupon> $liveCoupons */
        $liveCoupons = Coupon::query()
            ->where(function ($query) use ($venue) {
                $query->where('venue_id', $venue->id)
                    ->orWhere(function ($subQuery) use ($venue) {
                        $subQuery->whereNull('venue_id')
                            ->where('merchant_id', $venue->merchant_id);
                    });
            })
            ->where('journey_type', $couponJourneyType)
            ->whereIn('provider', $providers)
            ->where('status', 'live')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->get()
            ->values();

        if ($liveCoupons->isEmpty()) {
            return null;
        }

        $newCustomerOnlyCount = $liveCoupons->where('is_new_customer_only', true)->count();
        $generalCouponCount = $liveCoupons->where('is_new_customer_only', false)->count();

        $eligibleCoupons = $liveCoupons;
        $filteredOutNewOnlyCount = 0;
        if ($existingCustomer === true) {
            $filteredOutNewOnlyCount = $eligibleCoupons->where('is_new_customer_only', true)->count();
            $eligibleCoupons = $eligibleCoupons->where('is_new_customer_only', false)->values();
        }

        $filteredOutMinOrderCount = 0;
        if ($basketTotal !== null) {
            $filteredOutMinOrderCount = $eligibleCoupons->filter(function (Coupon $coupon) use ($basketTotal) {
                return $coupon->minimum_order !== null && (float) $coupon->minimum_order > $basketTotal;
            })->count();

            $eligibleCoupons = $eligibleCoupons->filter(function (Coupon $coupon) use ($basketTotal) {
                return $coupon->minimum_order === null || (float) $coupon->minimum_order <= $basketTotal;
            })->values();
        }

        $selectionPool = $eligibleCoupons->isNotEmpty() ? $eligibleCoupons : $liveCoupons;

        /** @var Coupon|null $coupon */
        $coupon = $selectionPool
            ->sort(function (Coupon $left, Coupon $right) use ($existingCustomer) {
                $leftPriority = $this->couponPriority($left, $existingCustomer);
                $rightPriority = $this->couponPriority($right, $existingCustomer);

                if ($leftPriority !== $rightPriority) {
                    return $rightPriority <=> $leftPriority;
                }

                $discountCompare = (float) $right->discount_amount <=> (float) $left->discount_amount;
                if ($discountCompare !== 0) {
                    return $discountCompare;
                }

                $leftMinimum = $left->minimum_order !== null ? (float) $left->minimum_order : 0;
                $rightMinimum = $right->minimum_order !== null ? (float) $right->minimum_order : 0;
                if ($leftMinimum !== $rightMinimum) {
                    return $leftMinimum <=> $rightMinimum;
                }

                return ($left->expires_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($right->expires_at?->getTimestamp() ?? PHP_INT_MAX);
            })
            ->first();

        if (! $coupon) {
            return null;
        }

        $requiresProfileConfirmation = $existingCustomer === null && (bool) $coupon->is_new_customer_only;
        $eligibleForUser = $this->eligibleForCustomerState($coupon, $existingCustomer, $basketTotal);
        $providerState = $this->statePayload($provider, $existingCustomer);

        return [
            'id' => $coupon->id,
            'title' => $coupon->title,
            'code' => $coupon->code,
            'provider' => $coupon->provider,
            'discount_amount' => number_format((float) $coupon->discount_amount, 2, '.', ''),
            'minimum_order' => $coupon->minimum_order !== null ? number_format((float) $coupon->minimum_order, 2, '.', '') : null,
            'is_new_customer_only' => (bool) $coupon->is_new_customer_only,
            'eligibility_note' => $this->eligibilityNote($coupon, $existingCustomer),
            'eligibility_reason' => $this->eligibilityReason($coupon, $existingCustomer, $basketTotal),
            'requires_profile_confirmation' => $requiresProfileConfirmation,
            'eligible_for_user' => $eligibleForUser,
            'status_badge' => $this->statusBadge($coupon, $existingCustomer),
            'provider_customer_state' => $providerState['state'],
            'provider_customer_label' => $providerState['label'],
            'profile_complete' => $existingCustomer !== null,
            'live_coupon_count' => $liveCoupons->count(),
            'eligible_coupon_count' => $eligibleCoupons->count(),
            'general_coupon_count' => $generalCouponCount,
            'new_customer_only_count' => $newCustomerOnlyCount,
            'filtered_out_new_customer_only_count' => $filteredOutNewOnlyCount,
            'filtered_out_minimum_order_count' => $filteredOutMinOrderCount,
            'basket_total' => $basketTotal !== null ? number_format($basketTotal, 2, '.', '') : null,
            'expires_at' => optional($coupon->expires_at)?->toIso8601String(),
        ];
    }

    private function statePayload(string $provider, ?bool $existingCustomer): array
    {
        return [
            'provider' => $provider,
            'state' => $existingCustomer === null ? 'unknown' : ($existingCustomer ? 'existing' : 'new'),
            'label' => $existingCustomer === null
                ? 'Profile not set'
                : ($existingCustomer ? 'Existing customer' : 'New customer'),
            'message' => match (true) {
                $existingCustomer === true => strtoupper($provider) . ' existing-customer promos only.',
                $existingCustomer === false => strtoupper($provider) . ' new-customer promos can be shown.',
                default => 'Set your ' . strtoupper($provider) . ' status to tighten promo eligibility.',
            },
        ];
    }

    private function profileCompletionMessage(?bool $uberExisting, ?bool $ubereatsExisting): string
    {
        if ($uberExisting !== null && $ubereatsExisting !== null) {
            return 'Both Uber and Uber Eats eligibility profiles are set.';
        }

        if ($uberExisting !== null || $ubereatsExisting !== null) {
            return 'One provider profile is set. Add the other to refine live promo eligibility.';
        }

        return 'Provider profile not set yet. Discovery will prioritise broader promos until the user confirms eligibility.';
    }

    private function couponPriority(Coupon $coupon, ?bool $existingCustomer): int
    {
        if ($existingCustomer === true) {
            return $coupon->is_new_customer_only ? 0 : 4;
        }

        if ($existingCustomer === false) {
            return $coupon->is_new_customer_only ? 4 : 3;
        }

        return $coupon->is_new_customer_only ? 1 : 5;
    }

    private function eligibilityNote(Coupon $coupon, ?bool $existingCustomer): string
    {
        if ($coupon->is_new_customer_only) {
            return match (true) {
                $existingCustomer === false => 'New-customer promo matched for this user.',
                $existingCustomer === true => 'New-customer promo excluded for existing customers.',
                default => 'New-customer promo — confirm eligibility first.',
            };
        }

        return match (true) {
            $existingCustomer === true => 'Existing-customer compatible promo.',
            $existingCustomer === false => 'Also works for new customers.',
            default => 'Broad promo chosen while eligibility is still unknown.',
        };
    }

    private function eligibilityReason(Coupon $coupon, ?bool $existingCustomer, ?float $basketTotal): string
    {
        $parts = [];

        if ($coupon->is_new_customer_only) {
            $parts[] = 'new-customer-only';
        } else {
            $parts[] = 'existing-customer-safe';
        }

        if ($coupon->minimum_order !== null) {
            $parts[] = 'minimum order £' . number_format((float) $coupon->minimum_order, 2, '.', '');
        }

        if ($basketTotal !== null) {
            $parts[] = 'basket checked at £' . number_format($basketTotal, 2, '.', '');
        } elseif ($coupon->minimum_order !== null) {
            $parts[] = 'basket not confirmed yet';
        }

        if ($existingCustomer === null) {
            $parts[] = 'profile still unknown';
        }

        return implode(' • ', $parts);
    }

    private function statusBadge(Coupon $coupon, ?bool $existingCustomer): string
    {
        if ($coupon->is_new_customer_only) {
            return $existingCustomer === false ? 'New user promo' : 'Eligibility check';
        }

        return $existingCustomer === true ? 'Existing user eligible' : 'Live promo';
    }

    private function eligibleForCustomerState(Coupon $coupon, ?bool $existingCustomer, ?float $basketTotal): ?bool
    {
        if ($existingCustomer === true && $coupon->is_new_customer_only) {
            return false;
        }

        if ($basketTotal !== null && $coupon->minimum_order !== null && (float) $coupon->minimum_order > $basketTotal) {
            return false;
        }

        if ($existingCustomer === null && $coupon->is_new_customer_only) {
            return null;
        }

        return true;
    }

    private function normaliseNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }
}
