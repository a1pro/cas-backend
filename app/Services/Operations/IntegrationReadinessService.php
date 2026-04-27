<?php

namespace App\Services\Operations;

use App\Services\WhatsApp\ThreeSixtyDialogService;

class IntegrationReadinessService
{
    public function __construct(private readonly ThreeSixtyDialogService $threeSixtyDialogService)
    {
    }

    public function dashboardPayload(): array
    {
        $liveMode = (bool) config('talktocas.operations.live_mode', false);
        $checks = [
            $this->checkAppUrl(),
            $this->checkFrontendUrl(),
            $this->checkStripeSecret(),
            $this->checkStripePublishableKey(),
            $this->checkStripeWebhookSecret(),
            $this->checkStripeWebhookEndpoint(),
            $this->checkProviderWebhookSecret(),
            $this->checkProviderWebhookEndpoint(),
            $this->checkWhatsAppApiKey(),
            $this->checkWhatsAppPhoneNumber(),
            $this->checkWhatsAppVerifyToken(),
            $this->checkWhatsAppAppSecret(),
            $this->checkWhatsAppWebhookEndpoint(),
            $this->checkGoogleMaps(),
            $this->checkWeatherApi(),
            $this->checkSimulationFlags($liveMode),
        ];

        $readyChecks = collect($checks)->where('status', 'ready')->count();
        $criticalFailures = collect($checks)->where('severity', 'critical')->where('status', 'missing')->count();
        $warnings = collect($checks)->whereIn('status', ['warning', 'missing'])->where('severity', 'recommended')->count()
            + collect($checks)->where('status', 'warning')->where('severity', 'critical')->count();
        $simulationCheck = collect($checks)->firstWhere('key', 'simulation_flags');
        $simulationEnabledCount = collect(is_array($simulationCheck['details'] ?? null) ? $simulationCheck['details'] : [])->filter()->count();

        $groupSummaries = collect($checks)
            ->groupBy('group')
            ->map(function ($groupChecks, $groupKey) {
                $items = $groupChecks->values()->all();
                $ready = collect($items)->where('status', 'ready')->count();
                $warnings = collect($items)->where('status', 'warning')->count();
                $missing = collect($items)->where('status', 'missing')->count();

                return [
                    'group' => $groupKey,
                    'ready' => $ready,
                    'warnings' => $warnings,
                    'missing' => $missing,
                    'total' => count($items),
                ];
            })
            ->values()
            ->all();

        return [
            'live_mode' => $liveMode,
            'summary' => [
                'ready_checks' => $readyChecks,
                'total_checks' => count($checks),
                'completion_rate' => count($checks) > 0 ? round(($readyChecks / count($checks)) * 100, 1) : 0,
                'critical_failures' => $criticalFailures,
                'warnings' => $warnings,
                'simulation_enabled_count' => $simulationEnabledCount,
            ],
            'endpoints' => [
                'app_url' => rtrim((string) config('talktocas.operations.app_url', config('app.url')), '/'),
                'frontend_url' => rtrim((string) config('talktocas.operations.frontend_url', config('services.frontend.url', config('app.url'))), '/'),
                'stripe_webhook_url' => rtrim((string) config('talktocas.stripe_finance.webhooks.endpoint_url'), '/'),
                'provider_webhook_url' => rtrim((string) config('talktocas.provider_webhooks.endpoint_url'), '/'),
                'whatsapp_webhook_url' => $this->threeSixtyDialogService->webhookUrl(),
            ],
            'group_summaries' => $groupSummaries,
            'checks' => $checks,
        ];
    }

    private function checkAppUrl(): array
    {
        $value = rtrim((string) config('talktocas.operations.app_url', config('app.url')), '/');

        return $this->buildCheck(
            'app_url',
            'App URL',
            'platform',
            filled($value) ? 'ready' : 'missing',
            'recommended',
            filled($value) ? 'Backend app URL is configured.' : 'Set APP_URL so webhooks and invite links resolve correctly.',
            ['value' => $value ?: null]
        );
    }

    private function checkFrontendUrl(): array
    {
        $value = rtrim((string) config('talktocas.operations.frontend_url', config('services.frontend.url', config('app.url'))), '/');

        return $this->buildCheck(
            'frontend_url',
            'Frontend URL',
            'platform',
            filled($value) ? 'ready' : 'missing',
            'recommended',
            filled($value) ? 'Frontend URL is configured for public invite links.' : 'Set FRONTEND_URL for affiliate, BNPL, and merchant links.',
            ['value' => $value ?: null]
        );
    }

    private function checkStripeSecret(): array
    {
        $enabled = (bool) config('talktocas.stripe_finance.enabled', true);
        $configured = filled(config('services.stripe.secret'));
        $status = ! $enabled ? 'warning' : ($configured ? 'ready' : 'missing');

        return $this->buildCheck(
            'stripe_secret',
            'Stripe secret key',
            'payments',
            $status,
            'critical',
            ! $enabled ? 'Stripe finance is disabled.' : ($configured ? 'Stripe secret key is configured.' : 'Add STRIPE_SECRET before live wallet top-ups and payouts.'),
            ['enabled' => $enabled, 'configured' => $configured]
        );
    }

    private function checkStripePublishableKey(): array
    {
        $enabled = (bool) config('talktocas.stripe_finance.enabled', true);
        $configured = filled(config('services.stripe.publishable_key'));
        $status = ! $enabled ? 'warning' : ($configured ? 'ready' : 'missing');

        return $this->buildCheck(
            'stripe_publishable_key',
            'Stripe publishable key',
            'payments',
            $status,
            'critical',
            ! $enabled ? 'Stripe finance is disabled.' : ($configured ? 'Stripe publishable key is configured.' : 'Add STRIPE_PUBLISHABLE_KEY for frontend checkout handoff.'),
            ['enabled' => $enabled, 'configured' => $configured]
        );
    }

    private function checkStripeWebhookSecret(): array
    {
        $webhooksEnabled = (bool) config('talktocas.stripe_finance.webhooks.enabled', true);
        $configured = filled(config('services.stripe.webhook_secret'));
        $required = (bool) config('talktocas.stripe_finance.webhooks.require_valid_signature', false) || (bool) config('talktocas.operations.live_mode', false);
        $status = ! $webhooksEnabled ? 'warning' : ($configured ? 'ready' : ($required ? 'missing' : 'warning'));

        return $this->buildCheck(
            'stripe_webhook_secret',
            'Stripe webhook secret',
            'payments',
            $status,
            $required ? 'critical' : 'recommended',
            ! $webhooksEnabled
                ? 'Stripe webhooks are disabled.'
                : ($configured
                    ? 'Stripe webhook secret is configured.'
                    : ($required
                        ? 'Set STRIPE_WEBHOOK_SECRET for signed webhook verification in live mode.'
                        : 'Webhook signing secret is missing; localhost mode can still accept unsigned events.')),
            ['webhooks_enabled' => $webhooksEnabled, 'configured' => $configured, 'required' => $required]
        );
    }

    private function checkStripeWebhookEndpoint(): array
    {
        $value = rtrim((string) config('talktocas.stripe_finance.webhooks.endpoint_url'), '/');
        $expectedSuffix = '/api/v1/stripe/webhook';
        $configured = filled($value);
        $matches = $configured && str_ends_with($value, $expectedSuffix);

        return $this->buildCheck(
            'stripe_webhook_endpoint',
            'Stripe webhook endpoint',
            'payments',
            $configured ? ($matches ? 'ready' : 'warning') : 'missing',
            'recommended',
            ! $configured
                ? 'Configure TALKTOCAS_STRIPE_WEBHOOK_ENDPOINT_URL so Stripe can post settlement events.'
                : ($matches
                    ? 'Stripe webhook endpoint matches the versioned API route.'
                    : 'Stripe webhook endpoint is set but does not match the current /api/v1 route.'),
            ['value' => $value ?: null, 'expected_suffix' => $expectedSuffix]
        );
    }

    private function checkProviderWebhookSecret(): array
    {
        $enabled = (bool) config('talktocas.provider_webhooks.enabled', true);
        $configured = filled(config('talktocas.provider_webhooks.shared_secret'));
        $required = (bool) config('talktocas.provider_webhooks.require_valid_signature', false) || (bool) config('talktocas.operations.live_mode', false);
        $status = ! $enabled ? 'warning' : ($configured ? 'ready' : ($required ? 'missing' : 'warning'));

        return $this->buildCheck(
            'provider_webhook_secret',
            'Provider webhook secret',
            'providers',
            $status,
            $required ? 'critical' : 'recommended',
            ! $enabled
                ? 'Provider webhooks are disabled.'
                : ($configured
                    ? 'Shared secret is configured for provider settlement callbacks.'
                    : ($required
                        ? 'Set TALKTOCAS_PROVIDER_WEBHOOK_SHARED_SECRET for signed provider callbacks in live mode.'
                        : 'Shared secret is missing; localhost can still accept unsigned provider events.')),
            ['enabled' => $enabled, 'configured' => $configured, 'required' => $required]
        );
    }

    private function checkProviderWebhookEndpoint(): array
    {
        $value = rtrim((string) config('talktocas.provider_webhooks.endpoint_url'), '/');
        $expectedSuffix = '/api/v1/provider/webhook';
        $configured = filled($value);
        $matches = $configured && str_ends_with($value, $expectedSuffix);

        return $this->buildCheck(
            'provider_webhook_endpoint',
            'Provider webhook endpoint',
            'providers',
            $configured ? ($matches ? 'ready' : 'warning') : 'missing',
            'recommended',
            ! $configured
                ? 'Configure TALKTOCAS_PROVIDER_WEBHOOK_ENDPOINT_URL for Uber/Uber Eats settlement callbacks.'
                : ($matches
                    ? 'Provider webhook endpoint matches the versioned API route.'
                    : 'Provider webhook endpoint is set but does not match the current /api/v1 route.'),
            ['value' => $value ?: null, 'expected_suffix' => $expectedSuffix]
        );
    }

    private function checkWhatsAppApiKey(): array
    {
        $configured = filled(config('services.whatsapp360.api_key'));

        return $this->buildCheck(
            'whatsapp_api_key',
            '360dialog API key',
            'whatsapp',
            $configured ? 'ready' : 'missing',
            'critical',
            $configured ? '360dialog API key is configured.' : 'Add WHATSAPP_360_API_KEY before sending live WhatsApp messages.',
            ['configured' => $configured]
        );
    }

    private function checkWhatsAppPhoneNumber(): array
    {
        $configured = filled(config('services.whatsapp360.phone_number'));

        return $this->buildCheck(
            'whatsapp_phone_number',
            'WhatsApp business number',
            'whatsapp',
            $configured ? 'ready' : 'missing',
            'critical',
            $configured ? 'WhatsApp business phone number is configured.' : 'Add WHATSAPP_360_PHONE_NUMBER before go-live.',
            ['configured' => $configured]
        );
    }

    private function checkWhatsAppVerifyToken(): array
    {
        $configured = filled(config('services.whatsapp360.verify_token'));

        return $this->buildCheck(
            'whatsapp_verify_token',
            'WhatsApp verify token',
            'whatsapp',
            $configured ? 'ready' : 'missing',
            'critical',
            $configured ? 'Webhook verify token is configured for Meta/360dialog challenge checks.' : 'Add WHATSAPP_360_VERIFY_TOKEN for webhook verification handshakes.',
            ['configured' => $configured]
        );
    }

    private function checkWhatsAppAppSecret(): array
    {
        $configured = filled(config('services.whatsapp360.app_secret'));
        $required = (bool) config('talktocas.whatsapp.require_valid_signature', false) || (bool) config('talktocas.operations.live_mode', false);
        $status = $configured ? 'ready' : ($required ? 'missing' : 'warning');

        return $this->buildCheck(
            'whatsapp_app_secret',
            'WhatsApp app secret',
            'whatsapp',
            $status,
            $required ? 'critical' : 'recommended',
            $configured
                ? 'WhatsApp app secret is configured for webhook signature checks.'
                : ($required
                    ? 'Add WHATSAPP_360_APP_SECRET for signed webhook verification in live mode.'
                    : 'App secret is missing; localhost mode can still accept unsigned webhook payloads.'),
            ['configured' => $configured, 'required' => $required]
        );
    }

    private function checkWhatsAppWebhookEndpoint(): array
    {
        $value = $this->threeSixtyDialogService->webhookUrl();
        $expectedSuffix = '/api/v1/whatsapp/360dialog/webhook';
        $configured = filled($value);
        $matches = $configured && str_ends_with($value, $expectedSuffix);

        return $this->buildCheck(
            'whatsapp_webhook_endpoint',
            'WhatsApp webhook endpoint',
            'whatsapp',
            $configured ? ($matches ? 'ready' : 'warning') : 'missing',
            'recommended',
            ! $configured
                ? 'Configure TALKTOCAS_WHATSAPP_WEBHOOK_URL for inbound message delivery.'
                : ($matches
                    ? 'WhatsApp webhook endpoint matches the versioned API route.'
                    : 'WhatsApp webhook endpoint is set but does not match the current /api/v1 route.'),
            ['value' => $value ?: null, 'expected_suffix' => $expectedSuffix]
        );
    }

    private function checkGoogleMaps(): array
    {
        $configured = filled(config('services.google_maps.server_key'));

        return $this->buildCheck(
            'google_maps',
            'Google Maps server key',
            'geo',
            $configured ? 'ready' : 'warning',
            'recommended',
            $configured ? 'Google Maps server key is configured for postcode and route helpers.' : 'Add GOOGLE_MAPS_SERVER_KEY for production-grade geocoding and route links.',
            ['configured' => $configured]
        );
    }

    private function checkWeatherApi(): array
    {
        $enabled = (bool) config('talktocas.weather.enabled', false);
        $configured = filled(config('services.openweather.api_key'));
        $status = ! $enabled ? 'warning' : ($configured ? 'ready' : 'missing');

        return $this->buildCheck(
            'weather_api',
            'OpenWeather API key',
            'weather',
            $status,
            'recommended',
            ! $enabled
                ? 'Weather behaviour patterns are disabled.'
                : ($configured ? 'OpenWeather API key is configured.' : 'Add OPENWEATHER_API_KEY to power live weather behaviour patterns.'),
            ['enabled' => $enabled, 'configured' => $configured]
        );
    }

    private function checkSimulationFlags(bool $liveMode): array
    {
        $flags = [
            'stripe_finance' => (bool) config('talktocas.stripe_finance.allow_simulated_success', true),
            'bnpl' => (bool) config('talktocas.bnpl.allow_simulated_payment', true),
            'provider_verification' => (bool) config('talktocas.provider_verification.allow_simulated_events', true),
            'whatsapp_templates' => (bool) config('talktocas.whatsapp.allow_simulated_approval', true),
        ];

        $enabledCount = collect($flags)->filter()->count();
        $status = $liveMode && $enabledCount > 0 ? 'missing' : ($enabledCount > 0 ? 'warning' : 'ready');
        $message = $liveMode && $enabledCount > 0
            ? 'Live mode is enabled while localhost simulation flags are still on.'
            : ($enabledCount > 0
                ? 'Simulation flags are still enabled for localhost testing.'
                : 'Simulation flags are disabled.');

        return $this->buildCheck(
            'simulation_flags',
            'Simulation flags',
            'operations',
            $status,
            $liveMode ? 'critical' : 'recommended',
            $message,
            $flags
        );
    }

    private function buildCheck(string $key, string $label, string $group, string $status, string $severity, string $message, array $details = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
        ];
    }
}
