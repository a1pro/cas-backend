<?php

return [
    'bnpl' => [
        'enabled' => env('TALKTOCAS_BNPL_ENABLED', false),
        'link' => env('TALKTOCAS_BNPL_LINK', ''),
    ],
    'tag_button' => [
        'enabled' => env('TALKTOCAS_TAG_BUTTON_ENABLED', true),
        'base_url' => env('TALKTOCAS_TAG_INVITE_BASE_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/tag'),
    ],
    'conversation' => [
        'ask_email' => env('TALKTOCAS_ASK_EMAIL', true),
        'require_consent' => env('TALKTOCAS_REQUIRE_CONSENT', true),
    ],
    'weather' => [
        'enabled' => env('TALKTOCAS_WEATHER_ENABLED', false),
        'cache_minutes' => (int) env('TALKTOCAS_WEATHER_CACHE_MINUTES', 10),
        'cold_threshold_c' => (float) env('TALKTOCAS_WEATHER_COLD_THRESHOLD_C', 9),
        'lookahead_hours' => (int) env('TALKTOCAS_WEATHER_LOOKAHEAD_HOURS', 4),
    ],
    'live_areas' => [
        'enabled' => env('TALKTOCAS_LIVE_AREAS_ENABLED', true),
    ],
];
