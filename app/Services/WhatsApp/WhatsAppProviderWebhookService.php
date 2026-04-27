<?php

namespace App\Services\WhatsApp;

use App\Services\Chat\WhatsAppChatService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppProviderWebhookService
{
    public function __construct(
        private readonly WhatsAppChatService $whatsAppChatService,
        private readonly ThreeSixtyDialogService $threeSixtyDialogService,
    ) {
    }

    public function verifyChallenge(array $query): array
    {
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? $query['mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? $query['verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? $query['challenge'] ?? '');
        $expected = (string) config('services.whatsapp360.verify_token', '');

        $valid = $mode === 'subscribe' && filled($expected) && hash_equals($expected, $token) && $challenge !== '';

        return [
            'valid' => $valid,
            'challenge' => $valid ? $challenge : null,
            'reason' => $valid ? null : 'WhatsApp webhook verification token did not match.',
        ];
    }

    public function handle(array $payload, array $context = []): array
    {
        $verification = $this->verifyInboundSignature(
            (string) ($context['raw_payload'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $context['signature'] ?? null,
        );

        if ($this->signatureVerificationRequired() && ! $verification['valid']) {
            throw new RuntimeException($verification['reason'] ?? 'Invalid WhatsApp webhook signature.');
        }

        Log::info('360dialog webhook payload received', [
            'entry_count' => count($payload['entry'] ?? []),
            'signature_valid' => $verification['valid'],
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $contacts = $value['contacts'] ?? [];
                $contact = $contacts[0] ?? [];
                $fallbackPhone = $contact['wa_id'] ?? null;
                $name = $contact['profile']['name'] ?? 'WhatsApp User';

                foreach (($value['statuses'] ?? []) as $status) {
                    Log::info('360dialog outbound status webhook', $status);
                }

                foreach (($value['messages'] ?? []) as $message) {
                    $phone = $fallbackPhone ?: ($message['from'] ?? null);
                    if (! $phone) {
                        continue;
                    }

                    $type = $message['type'] ?? 'text';
                    Log::info('WhatsApp inbound event', ['from' => $phone, 'type' => $type, 'message_id' => $message['id'] ?? null]);

                    if ($type === 'text') {
                        $body = trim((string) ($message['text']['body'] ?? ''));
                        if ($body !== '') {
                            $this->whatsAppChatService->handleProviderText($phone, $name, $body, $message);
                        }
                    } elseif ($type === 'location') {
                        $location = $message['location'] ?? [];
                        $this->whatsAppChatService->handleProviderLocation($phone, $name, [
                            'latitude' => $location['latitude'] ?? null,
                            'longitude' => $location['longitude'] ?? null,
                            'address' => $location['address'] ?? null,
                            'name' => $location['name'] ?? null,
                        ], $message);
                    } elseif ($type === 'request_welcome') {
                        $this->whatsAppChatService->handleProviderText($phone, $name, 'Hi TALK TO CAS', $message);
                    }
                }
            }
        }

        return [
            'processed' => true,
            'signature_valid' => $verification['valid'],
            'verification_mode' => $verification['mode'],
            'webhook_url' => $this->threeSixtyDialogService->webhookUrl(),
        ];
    }

    public function signatureVerificationRequired(): bool
    {
        return (bool) config('talktocas.whatsapp.require_valid_signature', false)
            || (bool) config('talktocas.operations.live_mode', false);
    }

    private function verifyInboundSignature(string $rawPayload, ?string $signatureHeader): array
    {
        $secret = (string) config('services.whatsapp360.app_secret', '');

        if (blank($signatureHeader)) {
            return [
                'valid' => false,
                'mode' => 'unsigned',
                'reason' => 'Missing X-Hub-Signature-256 header.',
            ];
        }

        if (blank($secret)) {
            return [
                'valid' => false,
                'mode' => 'missing_secret',
                'reason' => 'Missing WHATSAPP_360_APP_SECRET for signature verification.',
            ];
        }

        $signature = trim((string) $signatureHeader);
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);
        $valid = hash_equals($expected, $signature);

        return [
            'valid' => $valid,
            'mode' => 'sha256',
            'reason' => $valid ? null : 'WhatsApp webhook signature mismatch.',
        ];
    }
}
