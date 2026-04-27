<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Chat\DiscoveryService;
use Illuminate\Http\Request;

class VenueDiscoveryController extends BaseController
{
    public function __construct(private readonly DiscoveryService $discoveryService)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'flow_type' => ['nullable', 'in:going_out,order_food'],
            'postcode' => ['nullable', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $flowType = $validated['flow_type'] ?? 'going_out';
        $postcode = isset($validated['postcode']) ? strtoupper(trim($validated['postcode'])) : null;
        $latitude = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $longitude = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $journeyType = $flowType === 'order_food' ? 'food' : 'nightlife';

        $result = $this->discoveryService->searchWithContext($journeyType, $postcode, $latitude, $longitude);
        $venues = collect($result['venues'])->map(function (array $venue) {
            return [
                'id' => $venue['id'],
                'name' => $venue['name'],
                'category' => $venue['category'],
                'city' => $venue['city'],
                'postcode' => $venue['postcode'],
                'address' => null,
                'description' => $venue['description'],
                'offer_value' => number_format((float) $venue['offer_value'], 2, '.', ''),
                'minimum_order' => $venue['minimum_order'] !== null ? number_format((float) $venue['minimum_order'], 2, '.', '') : null,
                'fulfilment_type' => $venue['fulfilment_type'],
                'wallet_balance' => number_format((float) $venue['wallet_balance'], 2, '.', ''),
                'low_balance_threshold' => null,
                'distance_miles' => $venue['distance'],
                'distance_label' => $venue['distance_label'],
                'offer_type' => $venue['offer_type'],
                'ride_trip_type' => $venue['ride_trip_type'],
                'is_new' => $venue['is_new'],
                'ranking_score' => $venue['score'],
                'ranking_breakdown' => [
                    'voucher_value' => $venue['score_breakdown']['voucher'],
                    'proximity' => $venue['score_breakdown']['proximity'],
                    'wallet_balance' => $venue['score_breakdown']['wallet'],
                    'redemption_history' => $venue['score_breakdown']['history'],
                    'rating' => $venue['score_breakdown']['rating'],
                    'weather_behavior' => $venue['score_breakdown']['weather_behavior'] ?? null,
                    'weather_delta' => $venue['score_breakdown']['weather_delta'] ?? 0,
                ],
                'merchant' => [
                    'id' => $venue['merchant_id'],
                    'business_name' => '',
                    'business_type' => $venue['category'],
                ],
            ];
        })->values();

        return $this->success([
            'flow_type' => $flowType,
            'filters' => [
                'postcode' => $postcode,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'source' => $latitude !== null && $longitude !== null ? 'gps' : ($postcode ? 'postcode' : 'open'),
            ],
            'summary' => [
                'total_results' => $venues->count(),
                'gps_used' => $latitude !== null && $longitude !== null,
                'postcode_used' => (bool) $postcode,
            ],
            'weather' => $result['weather'],
            'live_area' => $result['live_area'],
            'venues' => $venues,
        ]);
    }
}
