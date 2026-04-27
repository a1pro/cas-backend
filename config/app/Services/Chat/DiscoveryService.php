<?php

namespace App\Services\Chat;

use App\Models\LiveAreaPostcode;
use App\Models\Venue;
use App\Services\Weather\OpenWeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class DiscoveryService
{
    public function __construct(private readonly OpenWeatherService $weatherService)
    {
    }

    public function search(string $journeyType, ?string $postcode = null, ?float $latitude = null, ?float $longitude = null): array
    {
        return $this->searchWithContext($journeyType, $postcode, $latitude, $longitude)['venues'];
    }

    public function searchWithContext(string $journeyType, ?string $postcode = null, ?float $latitude = null, ?float $longitude = null): array
    {
        $postcode = $postcode ? strtoupper(trim($postcode)) : null;
        $journeyType = $journeyType === 'food' ? 'food' : 'nightlife';

        $context = [
            'live_area' => $this->liveAreaContext($postcode),
            'weather' => null,
            'resolved_location' => [
                'postcode' => $postcode,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'geocoded_postcode' => false,
            ],
        ];

        if ($postcode && config('talktocas.live_areas.enabled', true) && ! $context['live_area']['is_live']) {
            Log::info('Discovery blocked outside live area', [
                'journey_type' => $journeyType,
                'postcode' => $postcode,
                'live_area' => $context['live_area'],
            ]);

            return [
                'venues' => [],
                'weather' => null,
                'live_area' => $context['live_area'],
                'resolved_location' => $context['resolved_location'],
            ];
        }

        if (($latitude === null || $longitude === null) && $postcode) {
            $geo = $this->weatherService->geocodePostcode($postcode);
            if ($geo) {
                $latitude = (float) $geo['latitude'];
                $longitude = (float) $geo['longitude'];
                $context['resolved_location']['latitude'] = $latitude;
                $context['resolved_location']['longitude'] = $longitude;
                $context['resolved_location']['geocoded_postcode'] = true;
            }
        }

        if ($latitude !== null && $longitude !== null) {
            $context['weather'] = $this->weatherService->getWeatherContext(
                (float) $latitude,
                (float) $longitude,
                (int) config('talktocas.weather.lookahead_hours', 4)
            );
        }

        $venues = Venue::query()
            ->with(['merchant.wallet'])
            ->where('is_active', true)
            ->where('offer_enabled', true)
            ->whereHas('merchant', function ($query) {
                $query->where('status', 'active')->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', true));
            })
            ->get();

        $items = $venues->filter(fn (Venue $venue) => $this->matchesJourney($venue, $journeyType))
            ->filter(fn (Venue $venue) => $this->matchesSchedule($venue))
            ->filter(fn (Venue $venue) => $this->walletReady($venue))
            ->map(fn (Venue $venue) => $this->transformVenue($venue, $journeyType, $postcode, $latitude, $longitude, $context['weather']))
            ->sortByDesc('score')
            ->values()
            ->all();

        Log::info($journeyType === 'food' ? 'Food discovery search completed' : 'Chat discovery search completed', [
            'flow_type' => $journeyType,
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geocoded_postcode' => $context['resolved_location']['geocoded_postcode'],
            'weather_applied' => (bool) data_get($context, 'weather.applied', false),
            'weather_condition' => data_get($context, 'weather.condition'),
            'results_count' => count($items),
        ]);

        return [
            'venues' => $items,
            'weather' => $context['weather'],
            'live_area' => $context['live_area'],
            'resolved_location' => $context['resolved_location'],
        ];
    }

    public function isSelectable(int $venueId, string $journeyType): bool
    {
        return collect($this->search($journeyType))->contains(fn (array $venue) => $venue['id'] === $venueId);
    }

    private function matchesJourney(Venue $venue, string $journeyType): bool
    {
        $offerType = $venue->offer_type ?: ($journeyType === 'food' ? 'food' : 'ride');
        $isDual = in_array($offerType, ['dual_choice', 'dual'], true);

        if ($journeyType === 'nightlife') {
            return in_array($venue->category, ['club', 'bar', 'bar-lounge', 'lounge'], true)
                && ($isDual || $offerType === 'ride' || $offerType === null);
        }

        return in_array($venue->category, ['restaurant', 'takeaway'], true)
            && ($isDual || $offerType === 'food' || $offerType === null);
    }

    private function matchesSchedule(Venue $venue): bool
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = strtolower($now->englishDayOfWeek);
        $days = collect($venue->offer_days ?? [])->map(fn ($day) => strtolower(trim((string) $day)))->all();

        if (! empty($days) && ! in_array($today, $days, true)) {
            return false;
        }

        if (! $venue->offer_start_time || ! $venue->offer_end_time) {
            return true;
        }

        $current = $now->format('H:i:s');
        $start = strlen((string) $venue->offer_start_time) === 5 ? $venue->offer_start_time . ':00' : (string) $venue->offer_start_time;
        $end = strlen((string) $venue->offer_end_time) === 5 ? $venue->offer_end_time . ':00' : (string) $venue->offer_end_time;

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }

    private function walletReady(Venue $venue): bool
    {
        $wallet = $venue->merchant?->wallet;
        if (! $wallet) {
            return false;
        }

        return (float) $wallet->balance >= ((float) $venue->offer_value + (float) $venue->merchant->default_service_fee);
    }

    private function transformVenue(Venue $venue, string $journeyType, ?string $postcode, ?float $latitude, ?float $longitude, ?array $weather): array
    {
        $distanceMiles = $this->distanceMiles($venue, $postcode, $latitude, $longitude);
        $voucherWeight = min(35, ((float) $venue->offer_value / 10) * 35);
        $proximityWeight = $distanceMiles === null ? 12.5 : max(0, 25 - min(25, $distanceMiles * 4));
        $walletBalance = (float) optional($venue->merchant?->wallet)->balance;
        $walletWeight = min(20, $walletBalance / 10);
        $historyWeight = min(15, (float) $venue->redemption_score);
        $ratingWeight = min(5, ((float) $venue->rating / 5) * 5);
        $newBonus = $venue->created_at && $venue->created_at->gt(now()->subWeek()) ? 5 : 0;

        [$weatherDelta, $weatherBehavior] = $this->weatherAdjustment($venue, $journeyType, $distanceMiles, $weather);

        $score = round($voucherWeight + $proximityWeight + $walletWeight + $historyWeight + $ratingWeight + $newBonus + $weatherDelta, 1);

        return [
            'id' => $venue->id,
            'merchant_id' => $venue->merchant_id,
            'name' => $venue->name,
            'category' => $venue->category,
            'city' => $venue->city,
            'postcode' => $venue->postcode,
            'description' => $venue->description,
            'offer_value' => (float) $venue->offer_value,
            'minimum_order' => $venue->minimum_order ? (float) $venue->minimum_order : null,
            'fulfilment_type' => $venue->fulfilment_type,
            'offer_type' => $venue->offer_type,
            'ride_trip_type' => $venue->ride_trip_type,
            'wallet_balance' => $walletBalance,
            'distance' => $distanceMiles !== null ? round($distanceMiles, 1) : null,
            'distance_label' => $distanceMiles === null
                ? 'Nearby postcode area'
                : ($postcode && strtoupper($postcode) === strtoupper((string) $venue->postcode) ? 'Postcode match' : round($distanceMiles, 1) . ' miles'),
            'rating' => (float) $venue->rating,
            'score' => $score,
            'is_new' => $newBonus > 0,
            'promo_message' => $venue->promo_message,
            'score_breakdown' => [
                'voucher' => round($voucherWeight, 1),
                'proximity' => round($proximityWeight, 1),
                'wallet' => round($walletWeight, 1),
                'history' => round($historyWeight, 1),
                'rating' => round($ratingWeight, 1),
                'new_bonus' => round($newBonus, 1),
                'weather_delta' => round($weatherDelta, 1),
                'weather_behavior' => $weatherBehavior,
            ],
        ];
    }

    private function weatherAdjustment(Venue $venue, string $journeyType, ?float $distanceMiles, ?array $weather): array
    {
        if (! data_get($weather, 'applied')) {
            return [0, null];
        }

        $conditionKey = data_get($weather, 'condition_key', 'clear');
        $delta = 0;
        $notes = [];
        $text = strtolower(($venue->name ?: '') . ' ' . ($venue->description ?: ''));
        $isRooftopOrOutdoor = str_contains($text, 'rooftop') || str_contains($text, 'outdoor') || str_contains($text, 'terrace');

        if ($journeyType === 'nightlife') {
            if (in_array($conditionKey, ['rain', 'cold', 'snow_ice'], true)) {
                $delta += in_array($venue->category, ['club', 'bar', 'bar-lounge', 'lounge'], true) ? 6 : 0;
                $notes[] = 'Indoor nightlife boosted';
            }
            if ($isRooftopOrOutdoor && in_array($conditionKey, ['rain', 'cold', 'snow_ice'], true)) {
                $delta -= $conditionKey === 'snow_ice' ? 6 : 4;
                $notes[] = 'Outdoor / rooftop de-prioritised';
            }
            if ($distanceMiles !== null && $distanceMiles > 1.5 && in_array($conditionKey, ['cold', 'snow_ice'], true)) {
                $delta -= 2;
                $notes[] = 'Further walk softened in cold weather';
            }
        } else {
            if (in_array($conditionKey, ['rain', 'cold', 'snow_ice'], true) && in_array($venue->fulfilment_type, ['delivery', 'both'], true)) {
                $delta += 8;
                $notes[] = 'Delivery-friendly food boosted';
            }
            if (in_array($conditionKey, ['rain', 'cold', 'snow_ice'], true) && $venue->fulfilment_type === 'collection') {
                $delta -= 2;
                $notes[] = 'Collection-only slightly reduced';
            }
        }

        return [$delta, $notes ? implode(' • ', array_unique($notes)) : null];
    }

    private function distanceMiles(Venue $venue, ?string $postcode, ?float $latitude, ?float $longitude): ?float
    {
        if ($latitude !== null && $longitude !== null && $venue->latitude !== null && $venue->longitude !== null) {
            return $this->haversine($latitude, $longitude, (float) $venue->latitude, (float) $venue->longitude);
        }

        if ($postcode && strtoupper($postcode) === strtoupper((string) $venue->postcode)) {
            return 0.2;
        }

        $searchPrefix = $postcode ? strtoupper((string) strtok($postcode, ' ')) : null;
        $venuePrefix = strtoupper((string) strtok((string) $venue->postcode, ' '));
        if ($searchPrefix && $venuePrefix === $searchPrefix) {
            return 0.8;
        }

        return null;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function liveAreaContext(?string $postcode): array
    {
        $prefix = $postcode ? strtoupper((string) strtok(trim($postcode), ' ')) : null;
        $record = $prefix
            ? LiveAreaPostcode::query()->where('postcode_prefix', $prefix)->where('is_active', true)->first()
            : null;

        return [
            'enabled' => (bool) config('talktocas.live_areas.enabled', true),
            'is_live' => $prefix ? (bool) $record : true,
            'postcode_prefix' => $prefix,
            'label' => $record?->label,
        ];
    }
}
