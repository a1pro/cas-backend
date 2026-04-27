<?php

namespace App\Services\Voucher;

use App\Models\User;
use App\Models\Venue;
use App\Models\Voucher;
use App\Services\Affiliate\AffiliateTrackingService;
use App\Services\Fraud\FraudPreventionService;
use App\Support\OfferRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    public function __construct(
        private readonly AffiliateTrackingService $affiliateTrackingService,
        private readonly FraudPreventionService $fraudPreventionService,
        private readonly VenueUrgencyService $venueUrgencyService,
        private readonly ProviderVoucherLinkService $providerVoucherLinkService,
    ) {
    }

    public function issue(User $user, Venue $venue, string $journeyType, ?string $deviceFingerprint = null): Voucher
    {
        $journeyType = $this->normaliseJourneyType($journeyType);

        $this->fraudPreventionService->guardVoucherIssuance($user, $venue, $deviceFingerprint);
        $this->venueUrgencyService->guardAvailability($venue);

        $serviceFee = (float) ($venue->merchant->default_service_fee ?? 2.50);
        $voucherValue = (float) $venue->offer_value;
        $providerLink = $this->providerVoucherLinkService->matchActiveLink($venue, $journeyType);

        if (! $providerLink && config('talktocas.exact_voucher_links.enabled', true) && config('talktocas.exact_voucher_links.strict_issue', false)) {
            throw ValidationException::withMessages([
                'voucher' => ['No active Uber / Uber Eats voucher link is available for this venue yet. Please ask the admin to upload vouchers for the venue reference code.'],
            ]);
        }
        $rideTripType = $journeyType === 'ride' ? ($providerLink?->ride_trip_type ?? $venue->ride_trip_type) : null;
        $minimumOrder = $journeyType === 'food'
            ? ($providerLink?->minimum_order !== null ? (float) $providerLink->minimum_order : (float) ($venue->minimum_order ?? 25))
            : null;
        $totalCharge = OfferRules::requiredWalletBalanceForVoucher($voucherValue, $serviceFee, $journeyType, $rideTripType);

        $voucher = DB::transaction(function () use ($user, $venue, $journeyType, $serviceFee, $voucherValue, $minimumOrder, $totalCharge, $providerLink, $rideTripType) {
            return Voucher::create([
                'user_id' => $user->id,
                'merchant_id' => $venue->merchant_id,
                'venue_id' => $venue->id,
                'provider_voucher_link_id' => $providerLink?->id,
                'code' => 'TTC-' . strtoupper(Str::random(8)),
                'journey_type' => $journeyType,
                'provider_name' => $providerLink?->provider ?? ($journeyType === 'food' ? 'ubereats' : 'uber'),
                'offer_type' => $providerLink?->offer_type ?? $venue->offer_type,
                'ride_trip_type' => $rideTripType,
                'destination_postcode' => $venue->postcode,
                'promo_message' => $this->promoMessage($venue, $journeyType, $rideTripType),
                'voucher_value' => $voucherValue,
                'service_fee' => $serviceFee,
                'total_charge' => $totalCharge,
                'minimum_order' => $minimumOrder,
                'status' => 'issued',
                'issued_at' => now(),
                'expires_at' => now()->addHours(4),
                'voucher_link_url' => $providerLink?->link_url,
            ]);
        });

        $voucher = $voucher->fresh(['user']);
        $this->fraudPreventionService->afterVoucherIssued($voucher, $deviceFingerprint);
        $this->affiliateTrackingService->markVoucherIssued($voucher);

        return $voucher;
    }

    private function promoMessage(Venue $venue, string $journeyType, ?string $rideTripType = null): string
    {
        if ($journeyType === 'food') {
            $message = sprintf('£%.0f off food at %s. Minimum order applies.', (float) $venue->offer_value, $venue->name);
        } else {
            $message = sprintf(
                '£%.0f TALK to CAS Uber ride voucher for %s. %s.',
                (float) $venue->offer_value,
                $venue->name,
                OfferRules::rideTripTypeLabel($rideTripType)
            );
        }

        // Older installations have vouchers.promo_message as varchar(80). Keep this safe even before
        // the Batch 5 migration is run, while the migration below expands the column for future copy.
        return Str::limit($message, 80, '');
    }

    private function normaliseJourneyType(string $journeyType): string
    {
        return strtolower(trim($journeyType)) === 'food' ? 'food' : 'ride';
    }
}
