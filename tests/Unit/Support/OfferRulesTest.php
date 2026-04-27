<?php

namespace Tests\Unit\Support;

use App\Support\OfferRules;
use PHPUnit\Framework\TestCase;

class OfferRulesTest extends TestCase
{
    public function test_dual_choice_supports_food_and_ride(): void
    {
        $this->assertTrue(OfferRules::offerTypeSupportsFood('dual_choice'));
        $this->assertTrue(OfferRules::offerTypeSupportsRide('dual_choice'));
    }

    public function test_two_trip_label_matches_client_wording(): void
    {
        $this->assertSame('2 Trips (to-and-from)', OfferRules::rideTripTypeLabel('to_and_from'));
        $this->assertSame('1 Trip (to the venue)', OfferRules::rideTripTypeLabel('to_venue'));
    }

    public function test_minimum_wallet_balance_for_two_trip_offer_is_calculated_per_trip(): void
    {
        $required = OfferRules::minimumWalletBalanceForOffer('dual_choice', 5.0, 2.5, 'to_and_from');

        $this->assertSame(15.0, $required);
    }
}
