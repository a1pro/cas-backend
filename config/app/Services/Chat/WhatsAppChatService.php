<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Models\Venue;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSession;
use App\Services\Notifications\MerchantNotificationService;
use App\Services\Voucher\VoucherService;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppChatService
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
        private readonly VoucherService $voucherService,
        private readonly MerchantNotificationService $merchantNotificationService,
        private readonly ThreeSixtyDialogService $threeSixtyDialogService,
        private readonly TalkToCasConversationFlow $conversationFlow,
    ) {
    }

    public function startSession(array $payload): WhatsAppSession
    {
        return DB::transaction(function () use ($payload) {
            $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));
            $user = $this->findOrCreateUser($phone, $payload['name'] ?? 'WhatsApp User');

            $session = WhatsAppSession::create([
                'user_id' => $user->id,
                'from_number' => $phone,
                'phone_number' => $phone,
                'channel' => $payload['channel'] ?? 'web_widget',
                'status' => 'active',
                'last_step' => 'awaiting_journey',
                'metadata' => [
                    'started_from' => $payload['channel'] ?? 'web_widget',
                    'flow_version' => 'cas_suggested_flow_v1',
                    'mirror_to_whatsapp' => (bool) ($payload['mirror_to_whatsapp'] ?? false),
                ],
            ]);

            $this->welcome($session);

            return $session->load('messages');
        });
    }

    public function handleProviderText(string $phone, ?string $name, string $message, array $providerPayload = []): WhatsAppSession
    {
        $normalizedPhone = $this->normalizePhone($phone);
        Log::info('WhatsApp handleProviderText start', [
            'phone' => $normalizedPhone,
            'name' => $name,
            'message' => $message,
        ]);

        $session = $this->findOrCreateProviderSession($normalizedPhone, $name);
        $this->inbound($session, 'text', $message, [
            'provider' => '360dialog',
            'payload' => $providerPayload,
        ]);

        return $this->progressConversation($session, $message);
    }

    public function handleProviderLocation(string $phone, ?string $name, array $location, array $providerPayload = []): WhatsAppSession
    {
        $normalizedPhone = $this->normalizePhone($phone);
        Log::info('WhatsApp handleProviderLocation start', [
            'phone' => $normalizedPhone,
            'name' => $name,
            'location' => $location,
        ]);

        $session = $this->findOrCreateProviderSession($normalizedPhone, $name);
        $this->inbound($session, 'location', 'Shared location', [
            'provider' => '360dialog',
            'payload' => $providerPayload,
        ]);

        return $this->shareLocation($session, $location);
    }

    public function handleMessage(WhatsAppSession $session, string $message): WhatsAppSession
    {
        $this->inbound($session, 'text', $message);
        return $this->progressConversation($session, $message);
    }

    public function shareLocation(WhatsAppSession $session, array $location): WhatsAppSession
    {
        $postcode = isset($location['postcode']) && $location['postcode'] ? strtoupper((string) $location['postcode']) : $session->postcode;
        $latitude = $location['latitude'] ?? $session->latitude;
        $longitude = $location['longitude'] ?? $session->longitude;

        $session->update([
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'last_step' => 'showing_results',
        ]);

        $result = $this->discoveryService->searchWithContext(
            $session->journey_type ?? 'nightlife',
            $postcode,
            $latitude !== null ? (float) $latitude : null,
            $longitude !== null ? (float) $longitude : null,
        );

        $venues = $result['venues'] ?? [];
        $weather = $result['weather'] ?? null;
        $liveArea = $result['live_area'] ?? null;

        if (($liveArea['enabled'] ?? false) && ! ($liveArea['is_live'] ?? true)) {
            $session->update(['last_step' => 'no_results']);
            $this->outbound($session, 'text', $this->conversationFlow->comingSoonArea($postcode, $this->tagLink('invite')));
            return $session->fresh('messages');
        }

        if (empty($venues)) {
            $session->update(['last_step' => 'no_results']);
            $this->outbound($session, 'text', $this->conversationFlow->noResults($this->tagLink('invite')));
            return $session->fresh('messages');
        }

        $weatherNote = $this->conversationFlow->renderWeatherNote($session->journey_type ?? 'nightlife', $weather);
        $budgetTips = $this->conversationFlow->budgetTips($session->journey_type ?? 'nightlife', data_get($weather, 'condition_key'));

        $session->update([
            'metadata' => array_merge($session->metadata ?? [], [
                'last_result_ids' => collect($venues)->pluck('id')->take(3)->values()->all(),
                'last_weather' => $weather,
                'last_weather_note' => $weatherNote,
                'last_budget_tips' => $budgetTips,
            ]),
        ]);

        $summary = $this->conversationFlow->resultsSummary($session->journey_type ?? 'nightlife', $venues, $weather);
        $this->outbound($session, 'venue_results', $summary, [
            'venues' => $venues,
            'weather' => $weather,
            'weather_note' => $weatherNote,
            'budget_tips' => $budgetTips,
        ]);
        $this->outbound($session, 'text', $this->conversationFlow->askVenueChoice());

        return $session->fresh('messages');
    }

    public function selectVenue(WhatsAppSession $session, Venue $venue): WhatsAppSession
    {
        if (! $this->discoveryService->isSelectable($venue->id, $session->journey_type ?? 'nightlife')) {
            $this->outbound($session, 'text', $this->conversationFlow->invalidOffer());
            return $session->fresh('messages');
        }

        $metadata = $session->metadata ?? [];
        $metadata['pending_venue_id'] = $venue->id;

        $nextStep = config('talktocas.conversation.ask_email', true) ? 'awaiting_email' : (config('talktocas.conversation.require_consent', true) ? 'awaiting_consent' : 'issuing_voucher');

        $session->update([
            'selected_venue_id' => $venue->id,
            'last_step' => $nextStep,
            'metadata' => $metadata,
        ]);

        if ($nextStep === 'awaiting_email') {
            $this->outbound($session, 'text', $this->conversationFlow->askEmail());
            return $session->fresh('messages');
        }

        if ($nextStep === 'awaiting_consent') {
            $this->outbound($session, 'text', $this->conversationFlow->askConsent());
            return $session->fresh('messages');
        }

        return $this->issueVoucher($session, $venue);
    }

    private function progressConversation(WhatsAppSession $session, string $message): WhatsAppSession
    {
        $normalized = Str::of($message)->trim()->lower()->replaceMatches('/\s+/', ' ')->value();
        Log::info('WhatsApp progressConversation', [
            'session_id' => $session->id,
            'phone' => $session->from_number,
            'last_step' => $session->last_step,
            'status' => $session->status,
            'message' => $message,
            'normalized' => $normalized,
        ]);

        $restartWords = ['hi', 'hello', 'hey', 'start', 'restart', 'menu', 'logout', 'hi talk to cas'];
        if (in_array($normalized, $restartWords, true)) {
            $this->resetSession($session);
            $this->welcome($session);
            return $session->fresh('messages');
        }

        if ($this->isBiggerVoucherIntent($normalized)) {
            return $this->handleBiggerVoucherIntent($session);
        }

        if ($this->isTagIntent($normalized) || $session->last_step === 'awaiting_tag_venue_name') {
            return $this->handleTagFlow($session, $message, $normalized);
        }

        if ($this->isGoingOutIntent($normalized)) {
            $session->update(['journey_type' => 'nightlife', 'last_step' => 'awaiting_location', 'status' => 'active']);
            $this->askForLocation($session);
            return $session->fresh('messages');
        }

        if ($this->isFoodIntent($normalized)) {
            $session->update(['journey_type' => 'food', 'last_step' => 'awaiting_location', 'status' => 'active']);
            $this->askForLocation($session);
            return $session->fresh('messages');
        }

        if ($session->last_step === 'awaiting_journey') {
            if ($this->looksLikePostcode($normalized)) {
                $session->update(['last_step' => 'awaiting_location']);
                return $this->shareLocation($session, ['postcode' => strtoupper(trim($message))]);
            }

            $this->outbound($session, 'text', $this->conversationFlow->clarifyIntent());
            return $session->fresh('messages');
        }

        if ($session->last_step === 'awaiting_location') {
            if ($this->looksLikePostcode($normalized)) {
                return $this->shareLocation($session, ['postcode' => strtoupper(trim($message))]);
            }

            $this->outbound($session, 'text', $this->conversationFlow->askLocation());
            return $session->fresh('messages');
        }

        if ($session->last_step === 'showing_results' && ctype_digit($normalized)) {
            $resultIds = collect($session->metadata['last_result_ids'] ?? []);
            $selectedId = $resultIds->get(((int) $normalized) - 1);
            if ($selectedId) {
                $venue = Venue::find($selectedId);
                if ($venue) {
                    return $this->selectVenue($session, $venue);
                }
            }

            $this->outbound($session, 'text', $this->conversationFlow->invalidVenueChoice());
            return $session->fresh('messages');
        }

        if ($session->last_step === 'awaiting_email') {
            if (! in_array($normalized, ['skip', 'no', 'none'], true) && ! filter_var($message, FILTER_VALIDATE_EMAIL)) {
                $this->outbound($session, 'text', $this->conversationFlow->invalidEmail());
                return $session->fresh('messages');
            }

            if (filter_var($message, FILTER_VALIDATE_EMAIL)) {
                $session->user?->update(['email' => trim($message)]);
            }

            if (config('talktocas.conversation.require_consent', true)) {
                $session->update(['last_step' => 'awaiting_consent']);
                $this->outbound($session, 'text', $this->conversationFlow->askConsent());
                return $session->fresh('messages');
            }

            $pendingVenueId = $session->metadata['pending_venue_id'] ?? null;
            $venue = $pendingVenueId ? Venue::with('merchant')->find($pendingVenueId) : null;
            return $venue ? $this->issueVoucher($session, $venue) : $this->restartVenueChoice($session);
        }

        if ($session->last_step === 'awaiting_consent') {
            if (! in_array($normalized, ['yes', 'y', 'i agree'], true)) {
                $this->outbound($session, 'text', $this->conversationFlow->invalidConsent());
                return $session->fresh('messages');
            }

            $pendingVenueId = $session->metadata['pending_venue_id'] ?? null;
            $venue = $pendingVenueId ? Venue::with('merchant')->find($pendingVenueId) : null;
            return $venue ? $this->issueVoucher($session, $venue) : $this->restartVenueChoice($session);
        }

        if ($session->last_step === 'no_results') {
            $this->outbound($session, 'text', $this->conversationFlow->fallback());
            return $session->fresh('messages');
        }

        $this->outbound($session, 'text', $this->conversationFlow->fallback());
        return $session->fresh('messages');
    }

    private function issueVoucher(WhatsAppSession $session, Venue $venue): WhatsAppSession
    {
        $voucher = $this->voucherService->issue($session->user, $venue->fresh('merchant'), $session->journey_type ?? 'nightlife');

        $metadata = $session->metadata ?? [];
        $metadata['consent_at'] = now()->toIso8601String();

        $session->update([
            'selected_venue_id' => $venue->id,
            'last_step' => 'voucher_issued',
            'status' => 'voucher_issued',
            'metadata' => $metadata,
        ]);

        $voucherLink = rtrim((string) config('app.url'), '/') . '/voucher/' . $voucher->code;
        $body = $this->conversationFlow->voucherReady([
            'journey_type' => $session->journey_type,
            'venue_name' => $venue->name,
            'offer_value' => (float) $voucher->voucher_value,
            'minimum_order' => $voucher->minimum_order ? (float) $voucher->minimum_order : 25,
            'voucher_link' => $voucherLink,
            'offer_type' => $venue->offer_type,
            'weather_note' => data_get($session->metadata, 'last_weather_note'),
            'budget_tips' => data_get($session->metadata, 'last_budget_tips', []),
        ]);

        $this->outbound($session, 'voucher', $body, [
            'voucher' => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'status' => $voucher->status,
                'voucher_value' => (float) $voucher->voucher_value,
                'promo_message' => $voucher->promo_message,
                'destination_postcode' => $voucher->destination_postcode,
                'minimum_order' => $voucher->minimum_order ? (float) $voucher->minimum_order : null,
                'voucher_link' => $voucherLink,
            ],
        ]);

        $this->merchantNotificationService->notifyMerchantVoucherIssued($voucher->fresh(['merchant', 'venue']));

        return $session->fresh('messages');
    }

    private function askForLocation(WhatsAppSession $session): void
    {
        $body = $this->conversationFlow->askLocation();

        if ($session->channel === '360dialog') {
            $this->threeSixtyDialogService->sendLocationRequest($session->from_number ?: $session->phone_number ?: (string) $session->user?->phone, $body);
        }

        $this->outbound($session, 'location_request', $body);
    }

    private function welcome(WhatsAppSession $session): void
    {
        Log::info('WhatsApp welcome triggered', [
            'session_id' => $session->id,
            'phone' => $session->from_number,
            'channel' => $session->channel,
        ]);
        $this->outbound($session, 'text', $this->conversationFlow->welcome());
    }

    private function resetSession(WhatsAppSession $session): void
    {
        $session->update([
            'status' => 'active',
            'journey_type' => null,
            'last_step' => 'awaiting_journey',
            'postcode' => null,
            'latitude' => null,
            'longitude' => null,
            'selected_venue_id' => null,
            'metadata' => ['started_from' => $session->channel, 'flow_version' => 'cas_suggested_flow_v1'],
        ]);
    }

    private function handleBiggerVoucherIntent(WhatsAppSession $session): WhatsAppSession
    {
        if (! config('talktocas.bnpl.enabled', false) || ! config('talktocas.bnpl.link')) {
            $this->outbound($session, 'text', $this->conversationFlow->fallback());
            return $session->fresh('messages');
        }

        $this->outbound($session, 'text', $this->conversationFlow->bnplOffer((string) config('talktocas.bnpl.link')));
        return $session->fresh('messages');
    }

    private function handleTagFlow(WhatsAppSession $session, string $message, string $normalized): WhatsAppSession
    {
        if (! config('talktocas.tag_button.enabled', true)) {
            $this->outbound($session, 'text', $this->conversationFlow->fallback());
            return $session->fresh('messages');
        }

        if ($session->last_step !== 'awaiting_tag_venue_name' || in_array($normalized, ['tag', 'tag now', 'invite venue', 'invite'], true)) {
            $session->update(['last_step' => 'awaiting_tag_venue_name']);
            $this->outbound($session, 'text', $this->conversationFlow->tagPrompt());
            return $session->fresh('messages');
        }

        $venueName = $this->conversationFlow->normalizeVenueName($message);
        $tagLink = $this->tagLink($venueName);

        $metadata = $session->metadata ?? [];
        $metadata['tagged_venues'][] = [
            'name' => $venueName,
            'link' => $tagLink,
            'created_at' => now()->toIso8601String(),
        ];

        $session->update([
            'last_step' => 'awaiting_journey',
            'metadata' => $metadata,
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->tagCreated($venueName, $tagLink));
        return $session->fresh('messages');
    }

    private function restartVenueChoice(WhatsAppSession $session): WhatsAppSession
    {
        $session->update(['last_step' => 'showing_results']);
        $this->outbound($session, 'text', $this->conversationFlow->chooseAgain());
        return $session->fresh('messages');
    }

    private function findOrCreateProviderSession(string $phone, ?string $name): WhatsAppSession
    {
        $user = $this->findOrCreateUser($phone, $name ?: 'WhatsApp User');

        $latestSession = WhatsAppSession::query()
            ->where('channel', '360dialog')
            ->where('from_number', $phone)
            ->latest('id')
            ->first();

        Log::info('WhatsApp provider session lookup', [
            'phone' => $phone,
            'latest_session_id' => $latestSession?->id,
            'latest_status' => $latestSession?->status,
            'latest_last_step' => $latestSession?->last_step,
            'should_start_fresh' => $latestSession ? $this->shouldStartFreshProviderSession($latestSession) : true,
        ]);

        if (! $latestSession || $this->shouldStartFreshProviderSession($latestSession)) {
            if ($latestSession && $latestSession->status === 'active') {
                $latestSession->update(['status' => 'completed']);
            }

            $newSession = WhatsAppSession::create([
                'user_id' => $user->id,
                'from_number' => $phone,
                'phone_number' => $phone,
                'channel' => '360dialog',
                'status' => 'active',
                'last_step' => 'awaiting_journey',
                'metadata' => array_filter([
                    'started_from' => '360dialog_live',
                    'flow_version' => 'cas_suggested_flow_v1',
                    'previous_session_id' => $latestSession?->id,
                ]),
            ]);

            Log::info('WhatsApp provider session created', [
                'session_id' => $newSession->id,
                'phone' => $phone,
            ]);

            return $newSession;
        }

        Log::info('WhatsApp provider session reused', [
            'session_id' => $latestSession->id,
            'phone' => $phone,
        ]);

        return $latestSession;
    }

    private function shouldStartFreshProviderSession(WhatsAppSession $session): bool
    {
        if (in_array($session->status, ['voucher_issued', 'completed', 'closed', 'expired'], true)) {
            return true;
        }

        if ($session->last_step === 'voucher_issued') {
            return true;
        }

        return $session->updated_at !== null
            && $session->updated_at->lt(now()->subHours(4));
    }

    private function findOrCreateUser(string $phone, string $name): User
    {
        return User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'email' => 'user+' . Str::lower(Str::random(8)) . '@talktocas.local',
                'password' => Str::random(16),
                'role' => 'user',
                'is_active' => true,
            ]
        );
    }

    private function inbound(WhatsAppSession $session, string $type, string $body, array $payload = []): void
    {
        WhatsAppMessage::create([
            'session_id' => $session->id,
            'from_number' => $session->from_number,
            'direction' => 'inbound',
            'message_type' => $type,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    private function shouldMirrorToWhatsApp(WhatsAppSession $session): bool
    {
        if ($session->channel === '360dialog') {
            return true;
        }

        return $session->channel === 'web_widget'
            && (bool) data_get($session->metadata, 'mirror_to_whatsapp', false);
    }

    private function outbound(WhatsAppSession $session, string $type, string $body, array $payload = []): void
    {
        Log::info('WhatsApp outbound message preparing', [
            'session_id' => $session->id,
            'phone' => $session->from_number ?: $session->phone_number ?: (string) $session->user?->phone,
            'channel' => $session->channel,
            'type' => $type,
            'body' => $body,
        ]);

        if ($this->shouldMirrorToWhatsApp($session)) {
            $phone = $session->from_number ?: $session->phone_number ?: (string) $session->user?->phone;
            $response = $type === 'location_request'
                ? $this->threeSixtyDialogService->sendLocationRequest($phone, $body)
                : $this->threeSixtyDialogService->sendText($phone, $body);

            Log::info('WhatsApp outbound message mirrored via 360dialog', [
                'session_id' => $session->id,
                'channel' => $session->channel,
                'type' => $type,
                'response' => $response,
                'mirror_to_whatsapp' => (bool) data_get($session->metadata, 'mirror_to_whatsapp', false),
            ]);
        }

        WhatsAppMessage::create([
            'session_id' => $session->id,
            'from_number' => $session->from_number,
            'direction' => 'outbound',
            'message_type' => $type,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private function isGoingOutIntent(string $normalized): bool
    {
        return in_array($normalized, ['1', 'going-out tonight', 'going out tonight', 'going-out', 'going out', 'nightlife', 'clubbing'], true);
    }

    private function isFoodIntent(string $normalized): bool
    {
        return in_array($normalized, ['2', 'ordering-food', 'ordering food', 'order food', 'food', 'restaurant', 'takeaway'], true);
    }

    private function looksLikePostcode(string $normalized): bool
    {
        return preg_match('/^[a-z0-9\-\s]{4,10}$/i', $normalized) === 1;
    }

    private function isBiggerVoucherIntent(string $normalized): bool
    {
        return in_array($normalized, ['bigger voucher', 'more discount', 'higher amount'], true);
    }

    private function isTagIntent(string $normalized): bool
    {
        return in_array($normalized, ['tag', 'tag now', 'invite venue', 'invite my venue'], true);
    }

    private function tagLink(string $venueName): string
    {
        $base = rtrim((string) config('talktocas.tag_button.base_url'), '/');
        $query = http_build_query(['venue' => $venueName]);
        return $query ? $base . '?' . $query : $base;
    }
}
