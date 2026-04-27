<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreeSixtyDialogService
{
    public function enabled(): bool
    {
        return (bool) config('services.whatsapp360.enabled')
            && filled(config('services.whatsapp360.api_key'))
            && filled(config('services.whatsapp360.phone_number'));
    }

    public function displayPhoneNumber(): ?string
    {
        return config('services.whatsapp360.display_phone_number') ?: config('services.whatsapp360.phone_number');
    }

    public function providerName(): string
    {
        return (string) config('talktocas.whatsapp.provider', '360dialog');
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('talktocas.whatsapp.webhook_url', rtrim((string) config('app.url'), '/') . '/api/v1/whatsapp/360dialog/webhook'), '/');
    }

    public function configurationStatus(): array
    {
        return [
            'api_base' => rtrim((string) config('services.whatsapp360.api_base'), '/'),
            'has_api_key' => filled(config('services.whatsapp360.api_key')),
            'has_phone_number' => filled(config('services.whatsapp360.phone_number')),
            'display_phone_number' => $this->displayPhoneNumber(),
            'has_verify_token' => filled(config('services.whatsapp360.verify_token')),
            'has_app_secret' => filled(config('services.whatsapp360.app_secret')),
            'webhook_url' => $this->webhookUrl(),
            'signature_required' => (bool) config('talktocas.whatsapp.require_valid_signature', false),
        ];
    }

    public function startLink(): string
    {
        $phone = $this->normalizePhone((string) config('services.whatsapp360.phone_number'));
        $text = rawurlencode((string) config('services.whatsapp360.start_message', 'Hi TALK TO CAS'));

        return $phone ? "https://wa.me/{$phone}?text={$text}" : '#';
    }

    public function sendText(string $to, string $body): ?array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($to),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ]);
    }

    public function sendLocationRequest(string $to, string $body): ?array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => ['text' => $body],
                'action' => ['name' => 'send_location'],
            ],
        ]);
    }

    private function send(array $payload): ?array
    {
        if (! $this->enabled()) {
            Log::warning('360dialog is not enabled, skipping outbound message', [
                'enabled_flag' => config('services.whatsapp360.enabled'),
                'has_api_key' => filled(config('services.whatsapp360.api_key')),
                'has_phone_number' => filled(config('services.whatsapp360.phone_number')),
            ]);
            return null;
        }

        Log::info('360dialog outbound request', [
            'api_base' => rtrim((string) config('services.whatsapp360.api_base'), '/'),
            'to' => $payload['to'] ?? null,
            'type' => $payload['type'] ?? null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = Http::withHeaders([
            'D360-API-KEY' => (string) config('services.whatsapp360.api_key'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post(rtrim((string) config('services.whatsapp360.api_base'), '/') . '/messages', $payload);

        Log::info('360dialog outbound response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->failed()) {
            Log::warning('360dialog send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }
}
