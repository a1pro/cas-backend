<?php

namespace App\Support;

class OfferRules
{
    public static function voucherRangeForBusiness(?string $businessType): array
    {
        if (self::isFoodBusiness($businessType)) {
            return [
                'min' => 2.0,
                'max' => 10.0,
                'default' => 5.0,
                'label' => 'Restaurants / takeaway / cafe: £2 - £10',
            ];
        }

        return [
            'min' => 5.0,
            'max' => 10.0,
            'default' => 5.0,
            'label' => 'Clubs / bars: £5 - £10',
        ];
    }

    public static function isFoodBusiness(?string $businessType): bool
    {
        return in_array($businessType, ['restaurant', 'takeaway', 'cafe'], true);
    }

    public static function voucherAmountMessage(?string $businessType): string
    {
        $range = self::voucherRangeForBusiness($businessType);

        return sprintf(
            'Voucher amount for %s must be between £%.0f and £%.0f.',
            self::isFoodBusiness($businessType) ? 'food venues' : 'clubs and bars',
            $range['min'],
            $range['max']
        );
    }

    public static function offerTypes(): array
    {
        return ['food', 'ride', 'dual_choice'];
    }

    public static function rideTripTypes(): array
    {
        return ['to_venue', 'to_and_from'];
    }

    public static function offerTypeSupportsFood(?string $offerType): bool
    {
        return in_array($offerType, ['food', 'dual_choice'], true);
    }

    public static function offerTypeSupportsRide(?string $offerType): bool
    {
        return in_array($offerType, ['ride', 'dual_choice'], true);
    }

    public static function rideTripMultiplier(?string $rideTripType): int
    {
        return $rideTripType === 'to_and_from' ? 2 : 1;
    }

    public static function requiredWalletBalanceForVoucher(
        float $voucherAmount,
        float $serviceFee,
        ?string $journeyType = 'ride',
        ?string $rideTripType = null
    ): float {
        $multiplier = strtolower((string) $journeyType) === 'food'
            ? 1
            : self::rideTripMultiplier($rideTripType);

        return round(($voucherAmount + $serviceFee) * $multiplier, 2);
    }

    public static function minimumWalletBalanceForOffer(?string $offerType, float $voucherAmount, float $serviceFee, ?string $rideTripType = null): float
    {
        if (! self::offerTypeSupportsRide($offerType)) {
            return 0.0;
        }

        return self::requiredWalletBalanceForVoucher($voucherAmount, $serviceFee, 'ride', $rideTripType);
    }

    public static function rideTripTypeLabel(?string $rideTripType): string
    {
        return match ($rideTripType) {
            'to_and_from' => '2 Trips (to-and-from)',
            default => '1 Trip (to the venue)',
        };
    }
}
