<?php

namespace App\Services\Weather;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenWeatherService
{
    public function enabled(): bool
    {
        return (bool) config('talktocas.weather.enabled', false)
            && filled(config('services.openweather.api_key'));
    }

    public function geocodePostcode(string $postcode, ?string $countryCode = null): ?array
    {
        if (! filled(config('services.openweather.api_key'))) {
            return null;
        }

        $postcode = strtoupper(trim($postcode));
        $countryCode = strtoupper(trim((string) ($countryCode ?: config('services.openweather.country_code', 'GB'))));

        Log::info('OpenWeather postcode geocoding started', [
            'postcode' => $postcode,
            'country_code' => $countryCode,
        ]);

        $cacheKey = sprintf('owm:geo:%s:%s', $countryCode, md5($postcode));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($postcode, $countryCode) {
            try {
                $response = $this->http()->get('/geo/1.0/zip', [
                    'zip' => $postcode . ',' . $countryCode,
                    'appid' => config('services.openweather.api_key'),
                ]);

                if (! $response->successful()) {
                    Log::warning('OpenWeather postcode geocoding failed', [
                        'postcode' => $postcode,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                $json = $response->json();
                $result = [
                    'latitude' => (float) Arr::get($json, 'lat'),
                    'longitude' => (float) Arr::get($json, 'lon'),
                    'name' => Arr::get($json, 'name', $postcode),
                    'country' => Arr::get($json, 'country', $countryCode),
                    'source' => 'zip',
                ];

                Log::info('OpenWeather postcode geocoding success', [
                    'postcode' => $postcode,
                    'result' => $result,
                ]);

                return $result;
            } catch (\Throwable $throwable) {
                Log::warning('OpenWeather postcode geocoding exception', [
                    'postcode' => $postcode,
                    'message' => $throwable->getMessage(),
                ]);

                return null;
            }
        });
    }

    public function getWeatherContext(float $latitude, float $longitude, int $lookaheadHours = 4): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        Log::info('OpenWeather One Call request started', [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        $cacheMinutes = max(1, (int) config('talktocas.weather.cache_minutes', 10));
        $cacheKey = sprintf('owm:onecall:%s:%s:%d', round($latitude, 3), round($longitude, 3), $lookaheadHours);

        return Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($latitude, $longitude, $lookaheadHours) {
            try {
                $response = $this->http()->get('/data/3.0/onecall', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'appid' => config('services.openweather.api_key'),
                    'units' => 'metric',
                    'exclude' => 'minutely,alerts',
                ]);

                if (! $response->successful()) {
                    Log::warning('OpenWeather One Call failed', [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                $json = $response->json();
                $hourly = collect(Arr::get($json, 'hourly', []))->take(max(2, $lookaheadHours));
                $currentTemp = (float) Arr::get($json, 'current.temp', 0);
                $currentCondition = strtolower((string) Arr::get($json, 'current.weather.0.main', 'clear'));
                $currentDescription = strtolower((string) Arr::get($json, 'current.weather.0.description', 'clear sky'));

                $maxPop = (float) $hourly->max(fn ($item) => (float) Arr::get($item, 'pop', 0));
                $hasSnow = $hourly->contains(function ($item) {
                    $main = strtolower((string) Arr::get($item, 'weather.0.main', ''));
                    $description = strtolower((string) Arr::get($item, 'weather.0.description', ''));
                    return str_contains($main, 'snow') || str_contains($description, 'snow') || str_contains($description, 'ice');
                });
                $hasRain = $hourly->contains(function ($item) {
                    $main = strtolower((string) Arr::get($item, 'weather.0.main', ''));
                    $description = strtolower((string) Arr::get($item, 'weather.0.description', ''));
                    return str_contains($main, 'rain') || str_contains($description, 'rain') || str_contains($description, 'drizzle');
                });

                $conditionKey = $hasSnow
                    ? 'snow_ice'
                    : ($hasRain ? 'rain' : ($currentTemp <= (float) config('talktocas.weather.cold_threshold_c', 9) ? 'cold' : 'clear'));

                $context = [
                    'applied' => true,
                    'condition' => $currentCondition,
                    'condition_key' => $conditionKey,
                    'description' => ucfirst($currentDescription),
                    'temperature_c' => round($currentTemp, 1),
                    'is_rainy' => $hasRain,
                    'is_cold' => $currentTemp <= (float) config('talktocas.weather.cold_threshold_c', 9),
                    'is_snow_or_ice' => $hasSnow,
                    'precipitation_probability' => round($maxPop * 100, 1),
                    'lookahead_hours' => $lookaheadHours,
                    'hourly' => $hourly->map(function ($item) {
                        return [
                            'timestamp' => Arr::get($item, 'dt'),
                            'temperature_c' => round((float) Arr::get($item, 'temp', 0), 1),
                            'condition' => strtolower((string) Arr::get($item, 'weather.0.main', '')),
                            'description' => ucfirst((string) Arr::get($item, 'weather.0.description', '')),
                            'precipitation_probability' => round((float) Arr::get($item, 'pop', 0) * 100, 1),
                        ];
                    })->values()->all(),
                ];

                Log::info('OpenWeather One Call success', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'weather' => [
                        'condition' => $context['condition'],
                        'temperature_c' => $context['temperature_c'],
                        'is_rainy' => $context['is_rainy'],
                        'is_cold' => $context['is_cold'],
                        'precipitation_probability' => $context['precipitation_probability'],
                    ],
                ]);

                return $context;
            } catch (\Throwable $throwable) {
                Log::warning('OpenWeather One Call exception', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'message' => $throwable->getMessage(),
                ]);

                return null;
            }
        });
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openweather.api_base'), '/'))
            ->acceptJson()
            ->timeout(8)
            ->connectTimeout(4)
            ->retry(1, 250);
    }
}
