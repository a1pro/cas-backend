<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMapsService
{
    protected string $baseUrl = 'https://maps.googleapis.com/maps/api';

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function geocode(string $address): ?array
    {
        return $this->geocodeAddress($address);
    }

    public function geocodeAddress(string $address, ?string $city = null, ?string $postcode = null): ?array
    {
        $fullAddress = implode(', ', array_filter([trim($address), trim((string) $city), trim((string) $postcode)]));

        if (! $this->isConfigured() || $fullAddress === '') {
            return null;
        }

        $response = Http::timeout(12)
            ->acceptJson()
            ->get($this->baseUrl . '/geocode/json', [
                'address' => $fullAddress,
                'key' => $this->apiKey(),
                'region' => 'GB',
                'language' => 'en-GB',
            ]);

        if (! $response->ok()) {
            Log::warning('Google geocoding request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
            Log::info('Google geocoding returned no match', [
                'status' => $data['status'] ?? null,
                'address' => $fullAddress,
            ]);

            return null;
        }

        $result = $data['results'][0];
        $location = $result['geometry']['location'];

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => $result['formatted_address'] ?? null,
            'place_id' => $result['place_id'] ?? null,
        ];
    }

    public function validatePostcode(string $postcode): ?array
    {
        $clean = strtoupper(trim($postcode));

        if ($clean === '' || ! $this->isConfigured()) {
            return null;
        }

        $response = Http::timeout(12)
            ->acceptJson()
            ->get($this->baseUrl . '/geocode/json', [
                'address' => $clean,
                'components' => 'country:GB',
                'key' => $this->apiKey(),
                'region' => 'GB',
                'language' => 'en-GB',
            ]);

        if (! $response->ok()) {
            Log::warning('Google postcode validation request failed', [
                'status' => $response->status(),
                'postcode' => $clean,
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        if (($data['status'] ?? null) !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
            return null;
        }

        $result = $data['results'][0];
        $location = $result['geometry']['location'];

        return [
            'postcode' => $clean,
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => $result['formatted_address'] ?? null,
            'place_id' => $result['place_id'] ?? null,
        ];
    }

    public function computeRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey(),
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
            ])
            ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $originLat,
                            'longitude' => $originLng,
                        ],
                    ],
                ],
                'destination' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $destinationLat,
                            'longitude' => $destinationLng,
                        ],
                    ],
                ],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_AWARE',
            ]);

        if (! $response->ok()) {
            Log::warning('Google routes request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            return null;
        }

        $distanceMeters = isset($route['distanceMeters']) ? (int) $route['distanceMeters'] : null;
        $duration = $route['duration'] ?? null;
        $durationSeconds = $this->durationStringToSeconds($duration);

        return [
            'distance_meters' => $distanceMeters,
            'duration' => $duration,
            'duration_seconds' => $durationSeconds,
            'eta_minutes' => $durationSeconds !== null ? (int) ceil($durationSeconds / 60) : null,
            'distance_miles' => $distanceMeters !== null ? round($distanceMeters / 1609.344, 2) : null,
        ];
    }

    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $theta = deg2rad($lng1 - $lng2);
        $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($theta);
        $distance = acos(min(1, max(-1, $distance)));
        $distance = rad2deg($distance);

        return round($distance * 60 * 1.1515 * 1.609344, 2);
    }

    public function getDistanceMatrix(array $origins, array $destinations): array
    {
        if (! $this->isConfigured() || $origins === [] || $destinations === []) {
            return [];
        }

        $originStrings = array_map(fn (array $origin) => ($origin['lat'] ?? $origin['latitude']) . ',' . ($origin['lng'] ?? $origin['longitude']), $origins);
        $destinationStrings = array_map(fn (array $destination) => ($destination['lat'] ?? $destination['latitude']) . ',' . ($destination['lng'] ?? $destination['longitude']), $destinations);

        $response = Http::timeout(15)
            ->acceptJson()
            ->get($this->baseUrl . '/distancematrix/json', [
                'origins' => implode('|', $originStrings),
                'destinations' => implode('|', $destinationStrings),
                'key' => $this->apiKey(),
                'region' => 'GB',
                'language' => 'en-GB',
            ]);

        if (! $response->ok()) {
            Log::warning('Google distance matrix request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $data = $response->json();
        if (($data['status'] ?? null) !== 'OK') {
            return [];
        }

        return $this->parseDistanceMatrix($data);
    }

    public function directionsUrl(float $destinationLat, float $destinationLng, ?float $originLat = null, ?float $originLng = null): string
    {
        $params = [
            'api' => '1',
            'destination' => $destinationLat . ',' . $destinationLng,
            'travelmode' => 'driving',
        ];

        if ($originLat !== null && $originLng !== null) {
            $params['origin'] = $originLat . ',' . $originLng;
        }

        return 'https://www.google.com/maps/dir/?' . http_build_query($params);
    }

    public function getDirectionsUrl(float $originLat, float $originLng, float $destinationLat, float $destinationLng): string
    {
        return $this->directionsUrl($destinationLat, $destinationLng, $originLat, $originLng);
    }

    protected function parseDistanceMatrix(array $data): array
    {
        $results = [];

        foreach ($data['rows'] ?? [] as $rowIndex => $row) {
            foreach ($row['elements'] ?? [] as $columnIndex => $element) {
                if (($element['status'] ?? null) !== 'OK') {
                    continue;
                }

                $results[] = [
                    'origin_index' => $rowIndex,
                    'destination_index' => $columnIndex,
                    'distance_km' => round(((int) ($element['distance']['value'] ?? 0)) / 1000, 1),
                    'distance_text' => $element['distance']['text'] ?? null,
                    'duration_minutes' => isset($element['duration']['value']) ? (int) round(((int) $element['duration']['value']) / 60) : null,
                    'duration_text' => $element['duration']['text'] ?? null,
                ];
            }
        }

        return $results;
    }

    private function apiKey(): ?string
    {
        return config('services.google_maps.server_key')
            ?: config('services.google.maps_api_key')
            ?: env('GOOGLE_MAPS_SERVER_KEY')
            ?: env('GOOGLE_MAPS_API_KEY');
    }

    private function durationStringToSeconds(?string $duration): ?int
    {
        if (! is_string($duration) || ! str_ends_with($duration, 's')) {
            return null;
        }

        $seconds = substr($duration, 0, -1);

        return is_numeric($seconds) ? (int) round((float) $seconds) : null;
    }
}
