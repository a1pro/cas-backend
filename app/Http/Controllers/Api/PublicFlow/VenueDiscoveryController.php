<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\AreaLaunchService;
use App\Services\Chat\DiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class VenueDiscoveryController extends BaseController
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
        private readonly AreaLaunchService $areaLaunchService,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'flow_type' => ['nullable', 'in:going_out,order_food'],
                'postcode' => ['nullable', 'string', 'max:16'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'basket_total' => ['nullable', 'numeric', 'min:0'],
                'is_uber_existing_customer' => ['nullable', 'boolean'],
                'is_ubereats_existing_customer' => ['nullable', 'boolean'],
            ]);

            $flowType = $validated['flow_type'] ?? 'going_out';
            $postcode = isset($validated['postcode']) ? strtoupper(trim($validated['postcode'])) : null;
            $latitude = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
            $longitude = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
            $basketTotal = isset($validated['basket_total']) ? (float) $validated['basket_total'] : null;
            $journeyType = $flowType === 'order_food' ? 'food' : 'nightlife';

            $couponContext = [
                'is_uber_existing_customer' => $validated['is_uber_existing_customer'] ?? null,
                'is_ubereats_existing_customer' => $validated['is_ubereats_existing_customer'] ?? null,
            ];

            $result = $this->discoveryService->searchWithContext(
                $journeyType,
                $postcode,
                $latitude,
                $longitude,
                null,
                $couponContext,
                $basketTotal,
            );

            $venues = collect($result['venues'])->map(function (array $venue) {
                return [
                    'id' => $venue['id'],
                    'name' => $venue['name'],
                    'category' => $venue['category'],
                    'city' => $venue['city'],
                    'postcode' => $venue['postcode'],
                    'address' => $venue['address'] ?? null,
                    'latitude' => $venue['latitude'] ?? null,
                    'longitude' => $venue['longitude'] ?? null,
                    'description' => $venue['description'],
                    'offer_value' => number_format((float) $venue['offer_value'], 2, '.', ''),
                    'minimum_order' => $venue['minimum_order'] !== null ? number_format((float) $venue['minimum_order'], 2, '.', '') : null,
                    'fulfilment_type' => $venue['fulfilment_type'],
                    'wallet_balance' => number_format((float) $venue['wallet_balance'], 2, '.', ''),
                    'low_balance_threshold' => null,
                    'distance_miles' => $venue['distance'],
                    'distance_label' => $venue['distance_label'],
                    'directions_url' => $venue['directions_url'] ?? null,
                    'route' => $venue['route'] ?? null,
                    'offer_type' => $venue['offer_type'],
                    'offer_label' => $venue['offer_label'] ?? null,
                    'ride_trip_type' => $venue['ride_trip_type'],
                    'ride_trip_label' => $venue['ride_trip_label'] ?? null,
                    'is_new' => $venue['is_new'],
                    'ranking_score' => $venue['score'],
                    'weather_note' => $venue['weather_note'] ?? null,
                    'recommended_coupon' => $venue['recommended_coupon'] ?? null,
                    'urgency' => $venue['urgency'] ?? null,
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
                        'business_name' => $venue['merchant_business_name'] ?? '',
                        'business_type' => $venue['merchant_business_type'] ?? $venue['category'],
                    ],
                ];
            })->values();

            $data = [
                    'flow_type' => $flowType,
                    'filters' => [
                        'postcode' => $postcode,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'basket_total' => $basketTotal,
                        'source' => $latitude !== null && $longitude !== null ? 'gps' : ($postcode ? 'postcode' : 'open'),
                    ],
                    'summary' => [
                        'total_results' => $venues->count(),
                        'gps_used' => $latitude !== null && $longitude !== null,
                        'postcode_used' => (bool) $postcode,
                    ],
                    'weather' => $this->weatherPayload($result['weather'] ?? null, $flowType),
                    'live_area' => [
                        ...($result['live_area'] ?? []),
                        'audience' => $this->areaLaunchService->audienceSummary($postcode),
                        'requested_postcode_prefix' => $this->areaLaunchService->extractPostcodePrefix($postcode),
                    ],
                    'resolved_location' => $result['resolved_location'] ?? null,
                    'coupon_profile' => $result['coupon_profile'] ?? null,
                    'venues' => $venues,
                ];

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    private function weatherPayload(?array $weather, string $flowType): ?array
    {
        if (! $weather) {
            return null;
        }

        $summary = $this->weatherSummary($weather);
        $behaviouralMessage = $this->weatherBehaviouralMessage($weather, $flowType);

        return [
            ...$weather,
            'summary' => $summary,
            'behavioural_message' => $behaviouralMessage,
        ];
    }

    private function weatherSummary(array $weather): string
    {
        $description = trim((string) ($weather['description'] ?? 'Weather conditions available.'));
        $temperature = array_key_exists('temperature_c', $weather)
            ? sprintf('%.1f°C', (float) $weather['temperature_c'])
            : null;
        $precipitation = array_key_exists('precipitation_probability', $weather)
            ? sprintf('%.0f%% precipitation chance', (float) $weather['precipitation_probability'])
            : null;
        $lookahead = isset($weather['lookahead_hours']) ? (int) $weather['lookahead_hours'] : null;

        $parts = array_values(array_filter([$description, $temperature, $precipitation]));
        $summary = implode(' · ', $parts);

        if ($lookahead) {
            $summary .= sprintf(' over the next %d hour%s.', $lookahead, $lookahead === 1 ? '' : 's');
        } else {
            $summary .= '.';
        }

        return $summary;
    }

    private function weatherBehaviouralMessage(array $weather, string $flowType): ?string
    {
        $conditionKey = (string) ($weather['condition_key'] ?? 'clear');

        if ($flowType === 'order_food') {
            return match ($conditionKey) {
                'rain', 'cold', 'snow_ice' => 'Delivery-friendly food venues can rank slightly higher in harsher weather.',
                default => 'Clearer weather keeps food ranking closer to the base voucher and distance score.',
            };
        }

        return match ($conditionKey) {
            'rain', 'cold', 'snow_ice' => 'Indoor nightlife venues can rank slightly higher while longer walks and outdoor spots are softened.',
            default => 'Clearer weather keeps nightlife ranking closer to the base voucher and proximity score.',
        };
    }
}
