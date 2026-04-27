<?php

return [
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'daily' => [
        'api_key' => env('DAILY_API_KEY'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080')),
    ],

    'whatsapp360' => [
        'enabled' => env('WHATSAPP_360_ENABLED', false),
        'api_base' => env('WHATSAPP_360_API_BASE', 'https://waba-v2.360dialog.io'),
        'api_key' => env('WHATSAPP_360_API_KEY'),
        'phone_number' => env('WHATSAPP_360_PHONE_NUMBER'),
        'display_phone_number' => env('WHATSAPP_360_DISPLAY_PHONE_NUMBER'),
        'start_message' => env('WHATSAPP_360_START_MESSAGE', 'Hi TALK TO CAS'),
    ],

    'openweather' => [
        'api_key' => env('OPENWEATHER_API_KEY'),
        'api_base' => env('OPENWEATHER_API_BASE', 'https://api.openweathermap.org'),
        'country_code' => env('TALKTOCAS_WEATHER_COUNTRY_CODE', 'GB'),
    ],

];
