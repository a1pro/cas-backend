<?php

return [
    'whatsapp' => [
        'provider' => env('TALKTOCAS_WHATSAPP_PROVIDER', '360dialog'),
        'templates_required' => env('TALKTOCAS_WHATSAPP_TEMPLATES_REQUIRED', true),
        'allow_simulated_approval' => env('TALKTOCAS_WHATSAPP_ALLOW_SIMULATED_APPROVAL', true),
        'require_valid_signature' => env('TALKTOCAS_WHATSAPP_REQUIRE_VALID_SIGNATURE', false),
        'webhook_url' => env('TALKTOCAS_WHATSAPP_WEBHOOK_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/v1/whatsapp/360dialog/webhook'),
    ],

    'stripe_finance' => [
        'enabled' => env('TALKTOCAS_STRIPE_FINANCE_ENABLED', true),
        'allow_simulated_success' => env('TALKTOCAS_STRIPE_FINANCE_ALLOW_SIMULATED_SUCCESS', true),
        'default_currency' => env('TALKTOCAS_STRIPE_FINANCE_CURRENCY', 'GBP'),
        'default_country_code' => env('TALKTOCAS_STRIPE_FINANCE_COUNTRY_CODE', 'GB'),
        'top_up_base_url' => env('TALKTOCAS_STRIPE_TOP_UP_BASE_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/merchant/dashboard'),
        'connect_base_url' => env('TALKTOCAS_STRIPE_CONNECT_BASE_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/dashboard'),
        'webhooks' => [
            'enabled' => env('TALKTOCAS_STRIPE_WEBHOOKS_ENABLED', true),
            'allow_unsigned_localhost' => env('TALKTOCAS_STRIPE_ALLOW_UNSIGNED_LOCALHOST_WEBHOOKS', true),
            'require_valid_signature' => env('TALKTOCAS_STRIPE_REQUIRE_VALID_SIGNATURE', false),
            'endpoint_url' => env('TALKTOCAS_STRIPE_WEBHOOK_ENDPOINT_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/v1/stripe/webhook'),
        ],
    ],

    'bnpl' => [
        'enabled' => env('TALKTOCAS_BNPL_ENABLED', true),
        'provider' => env('TALKTOCAS_BNPL_PROVIDER', 'stripe_bnpl'),
        'base_url' => env('TALKTOCAS_BNPL_BASE_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/voucher-upgrade'),
        'link' => env('TALKTOCAS_BNPL_LINK', ''),
        'success_url' => env('TALKTOCAS_BNPL_SUCCESS_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/voucher-upgrade/{CHECKOUT_CODE}?status=success'),
        'cancel_url' => env('TALKTOCAS_BNPL_CANCEL_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/voucher-upgrade/{CHECKOUT_CODE}?status=cancelled'),
        'merchant_of_record' => env('TALKTOCAS_BNPL_MERCHANT_OF_RECORD', 'TALK to CAS'),
        'allow_simulated_payment' => env('TALKTOCAS_BNPL_ALLOW_SIMULATED_PAYMENT', true),
        'terms_summary' => env('TALKTOCAS_BNPL_TERMS_SUMMARY', 'Pay later options are subject to approval. Make sure customers can see the repayment schedule before confirming checkout.'),
        'compliance_message' => env('TALKTOCAS_BNPL_COMPLIANCE_MESSAGE', 'TALK to CAS acts as merchant of record. Voucher upgrades are only issued after payment confirmation is received.'),
        'plans' => [
            'standard' => [
                'name' => 'Standard',
                'amount_gbp' => 30,
                'description' => '£30 Uber voucher upgrade',
                'instalment_copy' => 'Lower-value option for casual upgrades.',
            ],
            'plus' => [
                'name' => 'Plus',
                'amount_gbp' => 50,
                'description' => '£50 Uber voucher upgrade',
                'instalment_copy' => 'Balanced option for regular nightlife spend.',
            ],
            'premium' => [
                'name' => 'Premium',
                'amount_gbp' => 75,
                'description' => '£75 Uber voucher upgrade',
                'instalment_copy' => 'Bigger upgrade for higher-spend evenings.',
            ],
            'vip' => [
                'name' => 'VIP',
                'amount_gbp' => 100,
                'description' => '£100 Uber voucher upgrade',
                'instalment_copy' => 'Highest upgrade tier in the proposal.',
            ],
        ],
    ],

    'tag_button' => [
        'enabled' => env('TALKTOCAS_TAG_BUTTON_ENABLED', true),
        'base_url' => env('TALKTOCAS_TAG_INVITE_BASE_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/tag'),
        'invite_expires_days' => (int) env('TALKTOCAS_TAG_INVITE_EXPIRES_DAYS', 7),
        'reward_credit_gbp' => (float) env('TALKTOCAS_TAG_REWARD_CREDIT_GBP', 5),
    ],

    'conversation' => [
        'ask_email' => env('TALKTOCAS_ASK_EMAIL', true),
        'require_consent' => env('TALKTOCAS_REQUIRE_CONSENT', false),
        'minimum_visible_venues' => (int) env('TALKTOCAS_MINIMUM_VISIBLE_VENUES', 3),
    ],

    'weather' => [
        'enabled' => env('TALKTOCAS_WEATHER_ENABLED', false),
        'cache_minutes' => (int) env('TALKTOCAS_WEATHER_CACHE_MINUTES', 10),
        'cold_threshold_c' => (float) env('TALKTOCAS_WEATHER_COLD_THRESHOLD_C', 9),
        'lookahead_hours' => (int) env('TALKTOCAS_WEATHER_LOOKAHEAD_HOURS', 4),
        'behaviour_patterns_enabled' => env('TALKTOCAS_WEATHER_BEHAVIOUR_PATTERNS_ENABLED', true),
    ],

    'affiliates' => [
        'enabled' => env('TALKTOCAS_AFFILIATES_ENABLED', true),
        'invite_base_url' => env('TALKTOCAS_AFFILIATE_INVITE_BASE_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/invite'),
        'attribution_days' => (int) env('TALKTOCAS_AFFILIATE_ATTRIBUTION_DAYS', 30),
        'commission_per_redeemed_voucher' => (float) env('TALKTOCAS_AFFILIATE_COMMISSION_PER_REDEEMED_VOUCHER', 0),
        'immediate_notifications_enabled' => env('TALKTOCAS_AFFILIATE_IMMEDIATE_NOTIFICATIONS_ENABLED', true),
        'report_weeks' => (int) env('TALKTOCAS_AFFILIATE_REPORT_WEEKS', 6),
    ],

    'wallet_alerts' => [
        'enabled' => env('TALKTOCAS_WALLET_ALERTS_ENABLED', true),
        'cooldown_hours' => (int) env('TALKTOCAS_WALLET_ALERTS_COOLDOWN_HOURS', 12),
    ],

    'fraud' => [
        'enabled' => env('TALKTOCAS_FRAUD_ENABLED', true),
        'phone_rule_window_days' => (int) env('TALKTOCAS_FRAUD_PHONE_RULE_WINDOW_DAYS', 30),
        'review_threshold' => (int) env('TALKTOCAS_FRAUD_REVIEW_THRESHOLD', 25),
        'auto_block_threshold' => (int) env('TALKTOCAS_FRAUD_AUTO_BLOCK_THRESHOLD', 75),
        'default_auto_block_days' => (int) env('TALKTOCAS_FRAUD_DEFAULT_AUTO_BLOCK_DAYS', 30),
        'default_manual_block_days' => (int) env('TALKTOCAS_FRAUD_DEFAULT_MANUAL_BLOCK_DAYS', 30),
    ],

    'urgency' => [
        'enabled' => env('TALKTOCAS_URGENCY_ENABLED', true),
        'default_daily_cap' => (int) env('TALKTOCAS_URGENCY_DEFAULT_DAILY_CAP', 5),
        'low_inventory_threshold' => (int) env('TALKTOCAS_URGENCY_LOW_INVENTORY_THRESHOLD', 3),
    ],

    'provider_webhooks' => [
        'enabled' => env('TALKTOCAS_PROVIDER_WEBHOOKS_ENABLED', true),
        'allow_unsigned_localhost' => env('TALKTOCAS_PROVIDER_ALLOW_UNSIGNED_LOCALHOST_WEBHOOKS', true),
        'require_valid_signature' => env('TALKTOCAS_PROVIDER_REQUIRE_VALID_SIGNATURE', false),
        'shared_secret' => env('TALKTOCAS_PROVIDER_WEBHOOK_SHARED_SECRET', ''),
        'endpoint_url' => env('TALKTOCAS_PROVIDER_WEBHOOK_ENDPOINT_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/v1/provider/webhook'),
    ],

    'provider_verification' => [
        'enabled' => env('TALKTOCAS_PROVIDER_VERIFICATION_ENABLED', true),
        'allow_simulated_events' => env('TALKTOCAS_PROVIDER_ALLOW_SIMULATED_EVENTS', true),
        'dashboard_recent_event_limit' => (int) env('TALKTOCAS_PROVIDER_DASHBOARD_RECENT_EVENT_LIMIT', 10),
        'dashboard_pending_limit' => (int) env('TALKTOCAS_PROVIDER_DASHBOARD_PENDING_LIMIT', 5),
    ],

    'lead_generator' => [
        'enabled' => env('TALKTOCAS_LEAD_GENERATOR_ENABLED', true),
        'recent_days' => (int) env('TALKTOCAS_LEAD_GENERATOR_RECENT_DAYS', 14),
        'merchant_recent_limit' => (int) env('TALKTOCAS_LEAD_GENERATOR_MERCHANT_RECENT_LIMIT', 5),
        'base_url' => env('TALKTOCAS_LEAD_GENERATOR_BASE_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/') . '/lead-generator'),
    ],

    'offer_sync' => [
        'enabled' => env('TALKTOCAS_OFFER_SYNC_ENABLED', true),
        'review_delay_hours' => (int) env('TALKTOCAS_OFFER_SYNC_REVIEW_DELAY_HOURS', 24),
        'dashboard_recent_limit' => (int) env('TALKTOCAS_OFFER_SYNC_DASHBOARD_RECENT_LIMIT', 8),
    ],

    'exact_voucher_links' => [
        'enabled' => env('TALKTOCAS_EXACT_VOUCHER_LINKS_ENABLED', true),
        'filter_chat_results' => env('TALKTOCAS_FILTER_CHAT_RESULTS_TO_UPLOADED_LINKS', false),
        'strict_issue' => env('TALKTOCAS_REQUIRE_UPLOADED_VOUCHER_LINK_ON_ISSUE', false),
    ],

    'merchant_rules' => [
        'minimum_balance_for_two_trips' => (float) env('TALKTOCAS_MIN_BALANCE_TWO_TRIPS', 15),
        'minimum_top_up_amount' => (float) env('TALKTOCAS_MINIMUM_TOP_UP_AMOUNT', 30),
        'address_change_support_message' => env('TALKTOCAS_ADDRESS_CHANGE_SUPPORT_MESSAGE', '⚠️ Please contact support. Address changes require admin approval.'),
        'one_off_event_keywords' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'TALKTOCAS_ONE_OFF_EVENT_KEYWORDS',
            'one off event,one-off event,one off party,one-off party,promoter,promotion,dj hire,dj night,private party,popup,pop-up,temporary event'
        ))))),
    ],

    'address_changes' => [
        'enabled' => env('TALKTOCAS_ADDRESS_CHANGES_ENABLED', true),
    ],

    'operations' => [
        'live_mode' => env('TALKTOCAS_LIVE_MODE', false),
        'app_url' => rtrim(env('APP_URL', 'http://localhost'), '/'),
        'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
    ],

    'area_launch_alerts' => [
        'enabled' => env('TALKTOCAS_AREA_LAUNCH_ALERTS_ENABLED', true),
        'dashboard_recent_limit' => (int) env('TALKTOCAS_AREA_LAUNCH_ALERTS_DASHBOARD_RECENT_LIMIT', 8),
        'automatic_on_merchant_approval' => env('TALKTOCAS_AREA_LAUNCH_ALERTS_ON_APPROVAL', true),
    ],

    'live_areas' => [
        'enabled' => env('TALKTOCAS_LIVE_AREAS_ENABLED', true),
    ],
];
