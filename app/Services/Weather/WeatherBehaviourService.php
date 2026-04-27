<?php

namespace App\Services\Weather;

use App\Models\Venue;
use App\Support\OfferRules;

class WeatherBehaviourService
{
    public function __construct(private readonly OpenWeatherService $weatherService)
    {
    }

    public function merchantVenueInsights(?Venue $venue, ?string $businessType): array
    {
        $enabled = (bool) config('talktocas.weather.enabled', false);
        $journeyType = OfferRules::isFoodBusiness((string) $businessType) ? 'food' : 'nightlife';

        if (! $enabled) {
            return [
                'enabled' => false,
                'active' => false,
                'journey_type' => $journeyType,
                'summary' => 'Weather behavioural patterns are disabled until the weather provider is configured.',
                'behavioural_pattern' => 'inactive',
                'recommended_actions' => [
                    'Enable TALKTOCAS_WEATHER_ENABLED and add an OpenWeather key to activate live uplift guidance.',
                ],
                'weather' => null,
            ];
        }

        [$latitude, $longitude] = $this->resolveCoordinates($venue);
        if ($latitude === null || $longitude === null) {
            return [
                'enabled' => true,
                'active' => false,
                'journey_type' => $journeyType,
                'summary' => 'Add venue coordinates or a valid postcode to activate live weather behavioural insights.',
                'behavioural_pattern' => 'location_required',
                'recommended_actions' => [
                    'Update the venue postcode and coordinates in the merchant dashboard.',
                ],
                'weather' => null,
            ];
        }

        $weather = $this->weatherService->getWeatherContext(
            $latitude,
            $longitude,
            (int) config('talktocas.weather.lookahead_hours', 4)
        );

        if (! $weather) {
            return [
                'enabled' => true,
                'active' => false,
                'journey_type' => $journeyType,
                'summary' => 'Weather data is temporarily unavailable for this venue location.',
                'behavioural_pattern' => 'unavailable',
                'recommended_actions' => [
                    'Retry after the weather feed refreshes or check the API configuration.',
                ],
                'weather' => null,
            ];
        }

        $conditionKey = (string) ($weather['condition_key'] ?? 'clear');

        if ($journeyType === 'food') {
            $behaviouralPattern = match ($conditionKey) {
                'rain', 'cold', 'snow_ice' => 'delivery_uplift',
                default => 'balanced_food_demand',
            };

            $summary = match ($behaviouralPattern) {
                'delivery_uplift' => 'Poorer weather usually lifts delivery intent and makes collection-only offers slightly less attractive.',
                default => 'Clearer weather keeps food demand closer to the base voucher and distance ranking.',
            };

            $actions = $behaviouralPattern === 'delivery_uplift'
                ? [
                    'Keep delivery or both fulfilment enabled to capture weather-driven demand.',
                    'Consider a stronger weekday food offer when rain or cold weather is active.',
                    'Highlight minimum order and delivery value clearly in promo copy.',
                ]
                : [
                    'Use voucher amount and proximity to stay competitive in normal weather.',
                    'Test delivery and collection messaging side by side to learn what converts better.',
                ];
        } else {
            $behaviouralPattern = match ($conditionKey) {
                'rain', 'cold', 'snow_ice' => 'indoor_nightlife_uplift',
                default => 'balanced_nightlife_demand',
            };

            $summary = match ($behaviouralPattern) {
                'indoor_nightlife_uplift' => 'Rain, cold, or icy conditions usually favour indoor nightlife and reduce willingness for longer walks.',
                default => 'Clearer weather keeps nightlife ranking closer to voucher size and raw proximity.',
            };

            $actions = $behaviouralPattern === 'indoor_nightlife_uplift'
                ? [
                    'Keep ride-based offers live for tonight because indoor venues can receive an uplift.',
                    'Promote short-trip convenience and indoor atmosphere in WhatsApp copy.',
                    'Avoid relying on outdoor or rooftop positioning during harsher weather.',
                ]
                : [
                    'Compete on voucher amount and proximity in clear-weather nightlife searches.',
                    'Use promo copy to highlight atmosphere, newness, and venue uniqueness.',
                ];
        }

        $temperature = array_key_exists('temperature_c', $weather)
            ? sprintf('%.1f°C', (float) $weather['temperature_c'])
            : null;
        $precipitation = array_key_exists('precipitation_probability', $weather)
            ? sprintf('%.0f%% precipitation chance', (float) $weather['precipitation_probability'])
            : null;

        return [
            'enabled' => true,
            'active' => true,
            'journey_type' => $journeyType,
            'summary' => trim(implode(' · ', array_filter([
                $summary,
                $weather['description'] ?? null,
                $temperature,
                $precipitation,
            ]))),
            'behavioural_pattern' => $behaviouralPattern,
            'recommended_actions' => $actions,
            'weather' => [
                'condition_key' => $conditionKey,
                'description' => $weather['description'] ?? null,
                'temperature_c' => $weather['temperature_c'] ?? null,
                'precipitation_probability' => $weather['precipitation_probability'] ?? null,
                'lookahead_hours' => $weather['lookahead_hours'] ?? null,
            ],
        ];
    }

    private function resolveCoordinates(?Venue $venue): array
    {
        if (! $venue) {
            return [null, null];
        }

        if ($venue->latitude !== null && $venue->longitude !== null) {
            return [(float) $venue->latitude, (float) $venue->longitude];
        }

        if ($venue->postcode) {
            $geo = $this->weatherService->geocodePostcode((string) $venue->postcode);
            if ($geo) {
                return [(float) $geo['latitude'], (float) $geo['longitude']];
            }
        }

        return [null, null];
    }
}
