<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Models\Venue;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSession;
use App\Services\Notifications\MerchantNotificationService;
use App\Services\Tag\TagButtonService;
use App\Services\Voucher\VoucherService;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppChatService
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
        private readonly VoucherService $voucherService,
        private readonly MerchantNotificationService $merchantNotificationService,
        private readonly ThreeSixtyDialogService $threeSixtyDialogService,
        private readonly TalkToCasConversationFlow $conversationFlow,
        private readonly TagButtonService $tagButtonService,
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
                'metadata' => array_filter([
                    'started_from' => $payload['channel'] ?? 'web_widget',
                    'flow_version' => 'cas_two_option_v2',
                    'mirror_to_whatsapp' => (bool) ($payload['mirror_to_whatsapp'] ?? false),
                    'device_fingerprint' => $payload['device_fingerprint'] ?? null,
                ], fn ($value) => ! is_null($value) && $value !== ''),
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
        $postcode = isset($location['postcode']) && $location['postcode']
            ? strtoupper(trim((string) ($location['postcode'])))
            : $session->postcode;

        $latitude = $location['latitude'] ?? $session->latitude;
        $longitude = $location['longitude'] ?? $session->longitude;

        if (! $this->isConcreteJourneyType($session->journey_type)) {
            return $this->saveLocationThenAskJourney($session, $postcode, $latitude, $longitude);
        }

        $session->update([
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'last_step' => 'showing_results',
            'metadata' => array_merge($session->metadata ?? [], [
                'last_resolved_location' => [
                    'postcode' => $postcode,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'geocoded_postcode' => false,
                ],
            ]),
        ]);

        $journeyType = $session->journey_type === 'food' ? 'food' : 'nightlife';

        return $this->presentJourneyResults($session, $journeyType, $postcode, $latitude, $longitude);
    }

    public function selectVenue(WhatsAppSession $session, Venue $venue): WhatsAppSession
    {
        $allowedIds = collect(data_get($session->metadata, 'last_result_ids', []))->map(fn ($id) => (int) $id);
        $isSelectable = $allowedIds->contains($venue->id)
            || $this->discoveryService->isSelectable($venue->id, $session->journey_type ?? 'nightlife');

        if (! $isSelectable) {
            $this->outbound($session, 'text', $this->conversationFlow->invalidOffer());
            return $session->fresh('messages');
        }

        $effectiveJourneyType = $this->isBrowseJourneyType($session->journey_type)
            ? $this->inferJourneyTypeFromVenue($venue)
            : $session->journey_type;

        $metadata = $session->metadata ?? [];
        $metadata['pending_venue_id'] = $venue->id;
        $metadata['selected_offer_usage'] = null;
        $metadata['selected_from_browse'] = $this->isBrowseJourneyType($session->journey_type);

        if (in_array(($venue->offer_type ?? null), ['dual_choice', 'dual'], true)) {
            $metadata['selected_offer_usage'] = null;

            $session->update([
                'journey_type' => $effectiveJourneyType,
                'selected_venue_id' => $venue->id,
                'last_step' => 'awaiting_dual_choice_mode',
                'metadata' => $metadata,
            ]);

            $this->outbound($session, 'text', $this->conversationFlow->askDualChoiceUsage([
                'name' => $venue->name,
                'offer_value' => (float) $venue->offer_value,
                'minimum_order' => $venue->minimum_order ? (float) $venue->minimum_order : 25,
                'ride_trip_type' => $venue->ride_trip_type,
            ]));

            return $session->fresh('messages');
        }

        $nextStep = $this->nextStepAfterVenueSelection();

        $session->update([
            'journey_type' => $effectiveJourneyType,
            'selected_venue_id' => $venue->id,
            'last_step' => $nextStep,
            'metadata' => $metadata,
        ]);

        if ($nextStep === 'awaiting_email') {
            $this->outbound($session, 'text', $this->conversationFlow->venueSelected($venue->name));
            $this->outbound($session, 'text', $this->conversationFlow->askEmail());
            return $session->fresh('messages');
        }

        if ($nextStep === 'awaiting_consent') {
            $this->outbound($session, 'text', $this->conversationFlow->venueSelected($venue->name));
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

        if ($this->isChangeNumberIntent($normalized)) {
            return $this->handleChangeNumber($session);
        }

        if ($this->isEndIntent($normalized)) {
            return $this->endChat($session);
        }

        if ($this->isChangeLocationIntent($normalized)) {
            return $this->handleChangeLocation($session);
        }

        if ($this->isChangeJourneyIntent($normalized)) {
            return $this->handleChangeJourney($session);
        }

        if ($this->isBackIntent($normalized)) {
            return $this->handleBackToVenueChoice($session);
        }

        if ($this->isBiggerVoucherIntent($normalized)) {
            return $this->handleBiggerVoucherIntent($session);
        }

        if ($this->isTagIntent($normalized) || $session->last_step === 'awaiting_tag_venue_name') {
            return $this->handleTagFlow($session, $message, $normalized);
        }

        if ($objectionType = $this->detectObjectionType($normalized, $session)) {
            return $this->presentObjectionChoices($session, $objectionType);
        }

        if ($session->last_step === 'awaiting_objection_choice') {
            return $this->handleObjectionChoice($session, $normalized);
        }

        if ($session->last_step === 'awaiting_weather_choice') {
            if (in_array($normalized, ['1', 'going out', 'going out tonight', 'nightlife'], true)) {
                return $this->presentStoredJourneyResults($session, 'nightlife');
            }

            if (in_array($normalized, ['2', 'food', 'ordering food', 'order food'], true)) {
                return $this->presentStoredJourneyResults($session, 'food');
            }

            $this->outbound($session, 'text', $this->conversationFlow->invalidWeatherChoice());
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

        if ($session->last_step === 'awaiting_dual_choice_mode') {
            $usage = $this->parseDualChoiceUsage($normalized);
            if (! $usage) {
                $this->outbound($session, 'text', $this->conversationFlow->invalidDualChoiceUsage());
                return $session->fresh('messages');
            }

            $pendingVenueId = $session->metadata['pending_venue_id'] ?? null;
            $venue = $pendingVenueId ? Venue::with('merchant')->find($pendingVenueId) : null;
            if (! $venue) {
                return $this->restartVenueChoice($session);
            }

            $metadata = $session->metadata ?? [];
            $metadata['selected_offer_usage'] = $usage;
            $nextStep = $this->nextStepAfterVenueSelection();

            $session->update([
                'journey_type' => $usage === 'food' ? 'food' : 'nightlife',
                'last_step' => $nextStep,
                'metadata' => $metadata,
            ]);

            $this->outbound($session, 'text', $this->conversationFlow->dualChoiceSelectionConfirmed($usage, [
                'name' => $venue->name,
                'offer_value' => (float) $venue->offer_value,
                'minimum_order' => $venue->minimum_order ? (float) $venue->minimum_order : 25,
                'ride_trip_type' => $venue->ride_trip_type,
            ]));

            if ($nextStep === 'awaiting_email') {
                $this->outbound($session, 'text', $this->conversationFlow->askEmail());
                return $session->fresh('messages');
            }

            if ($nextStep === 'awaiting_consent') {
                $this->outbound($session, 'text', $this->conversationFlow->askConsent());
                return $session->fresh('messages');
            }

            return $this->issueVoucher($session, $venue, $usage);
        }


        if ($this->isGoingOutIntent($normalized)) {
            return $this->chooseJourney($session, 'nightlife');
        }

        if ($this->isFoodIntent($normalized)) {
            return $this->chooseJourney($session, 'food');
        }

        if ($this->isBrowseIntent($normalized)) {
            $this->outbound($session, 'text', $this->conversationFlow->clarifyIntent());
            return $session->fresh('messages');
        }

        if ($session->last_step === 'awaiting_journey') {
            if ($this->looksLikePostcode($normalized)) {
                return $this->saveLocationThenAskJourney($session, strtoupper(trim($message)), $session->latitude, $session->longitude);
            }

            $this->outbound($session, 'text', $this->conversationFlow->clarifyIntent());
            return $session->fresh('messages');
        }

        if ($session->last_step === 'awaiting_location') {
            if ($this->looksLikePostcode($normalized)) {
                return $this->shareLocation($session, ['postcode' => strtoupper(trim($message))]);
            }

            $this->outbound($session, 'text', $this->conversationFlow->askLocation($this->isBrowseJourneyType($session->journey_type)));
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

    private function issueVoucher(WhatsAppSession $session, Venue $venue, ?string $selectedUsage = null): WhatsAppSession
    {
        $resolvedUsage = $selectedUsage ?? data_get($session->metadata, 'selected_offer_usage');
        $journeyTypeForIssue = $this->resolveVoucherJourneyType($session->journey_type, $resolvedUsage);

        try {
            $voucher = $this->voucherService->issue(
                $session->user,
                $venue->fresh('merchant'),
                $journeyTypeForIssue,
                $this->currentDeviceFingerprint($session)
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?: 'We cannot issue another voucher for this account right now.';

            $metadata = $session->metadata ?? [];
            $metadata['last_error'] = [
                'type' => 'voucher_issue_blocked',
                'message' => $message,
                'venue_id' => $venue->id,
                'journey_type' => $journeyTypeForIssue,
                'created_at' => now()->toIso8601String(),
            ];
            $metadata['pending_venue_id'] = null;
            $metadata['selected_offer_usage'] = null;

            $session->update([
                'selected_venue_id' => null,
                'last_step' => 'fraud_blocked',
                'status' => 'active',
                'metadata' => $metadata,
            ]);

            $this->outbound($session, 'text', $message);

            return $session->fresh('messages');
        } catch (\Throwable $exception) {
            Log::error('Voucher issuing failed during chat flow', [
                'session_id' => $session->id,
                'user_id' => $session->user?->id,
                'venue_id' => $venue->id,
                'journey_type' => $journeyTypeForIssue,
                'message' => $exception->getMessage(),
            ]);

            $metadata = $session->metadata ?? [];
            $metadata['last_error'] = [
                'type' => 'voucher_issue_error',
                'message' => $exception->getMessage(),
                'venue_id' => $venue->id,
                'journey_type' => $journeyTypeForIssue,
                'created_at' => now()->toIso8601String(),
            ];
            $metadata['pending_venue_id'] = null;
            $metadata['selected_offer_usage'] = null;

            $session->update([
                'selected_venue_id' => null,
                'last_step' => 'voucher_issue_error',
                'status' => 'active',
                'metadata' => $metadata,
            ]);

            $this->outbound($session, 'text', $this->conversationFlow->voucherIssueFailed());

            return $session->fresh('messages');
        }

        $metadata = $session->metadata ?? [];
        $metadata['consent_at'] = now()->toIso8601String();

        $session->update([
            'selected_venue_id' => $venue->id,
            'last_step' => 'voucher_issued',
            'status' => 'voucher_issued',
            'metadata' => $metadata,
        ]);

        $voucherLink = $voucher->voucher_link_url ?: (rtrim((string) config('app.url'), '/') . '/voucher/' . $voucher->code);
        $body = $this->conversationFlow->voucherReady([
            'journey_type' => $journeyTypeForIssue,
            'venue_name' => $venue->name,
            'offer_value' => (float) $voucher->voucher_value,
            'minimum_order' => $voucher->minimum_order ? (float) $voucher->minimum_order : 25,
            'voucher_link' => $voucherLink,
            'offer_type' => $voucher->offer_type ?? $venue->offer_type,
            'ride_trip_type' => $voucher->ride_trip_type ?? $venue->ride_trip_type,
            'selected_usage' => $resolvedUsage,
            'weather_note' => data_get($session->metadata, 'last_weather_note'),
            'budget_tips' => data_get($session->metadata, 'last_budget_tips', []),
        ]);

        $this->outbound($session, 'voucher', $body, [
            'voucher' => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'status' => $voucher->status,
                'voucher_value' => (float) $voucher->voucher_value,
                'journey_type' => $journeyTypeForIssue,
                'promo_message' => $voucher->promo_message,
                'destination_postcode' => $voucher->destination_postcode,
                'minimum_order' => $voucher->minimum_order ? (float) $voucher->minimum_order : null,
                'voucher_link' => $voucherLink,
                'provider_link_url' => $voucherLink,
                'provider_name' => $voucher->provider_name,
                'venue' => [
                    'id' => $venue->id,
                    'name' => $venue->name,
                    'category' => $venue->category,
                    'postcode' => $venue->postcode,
                ],
            ],
        ]);

        $this->merchantNotificationService->notifyMerchantVoucherIssued($voucher->fresh(['merchant', 'venue']));

        return $session->fresh('messages');
    }

    private function chooseJourney(WhatsAppSession $session, string $journeyType): WhatsAppSession
    {
        $journeyType = $journeyType === 'food' ? 'food' : 'nightlife';
        $metadata = $session->metadata ?? [];

        unset(
            $metadata['pending_venue_id'],
            $metadata['selected_from_browse'],
            $metadata['last_objection_prompt']
        );

        $metadata['selected_offer_usage'] = null;

        $session->update([
            'journey_type' => $journeyType,
            'selected_venue_id' => null,
            'status' => 'active',
            'last_step' => $this->hasSavedLocation($session) ? 'showing_results' : 'awaiting_location',
            'metadata' => $metadata,
        ]);

        if ($this->hasSavedLocation($session)) {
            return $this->presentJourneyResults(
                $session,
                $journeyType,
                $session->postcode,
                $session->latitude,
                $session->longitude
            );
        }

        $this->askForLocation($session);

        return $session->fresh('messages');
    }

    private function saveLocationThenAskJourney(
        WhatsAppSession $session,
        mixed $postcode = null,
        mixed $latitude = null,
        mixed $longitude = null
    ): WhatsAppSession {
        $postcode = $postcode ? strtoupper(trim((string) $postcode)) : null;

        $session->update([
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'journey_type' => null,
            'selected_venue_id' => null,
            'last_step' => 'awaiting_journey',
            'status' => 'active',
            'metadata' => array_merge($session->metadata ?? [], [
                'last_resolved_location' => [
                    'postcode' => $postcode,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'geocoded_postcode' => false,
                ],
                'selected_offer_usage' => null,
            ]),
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->locationSavedAskJourney($postcode));

        return $session->fresh('messages');
    }

    private function hasSavedLocation(WhatsAppSession $session): bool
    {
        return filled($session->postcode)
            || ($session->latitude !== null && $session->longitude !== null);
    }

    private function isConcreteJourneyType(?string $journeyType): bool
    {
        return in_array($journeyType, ['food', 'nightlife'], true);
    }

    private function askForLocation(WhatsAppSession $session): void
    {
        $body = $this->conversationFlow->askLocation($this->isBrowseJourneyType($session->journey_type));

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
            'metadata' => [
                'started_from' => $session->channel,
                'flow_version' => 'cas_two_option_v2',
                'mirror_to_whatsapp' => (bool) data_get($session->metadata, 'mirror_to_whatsapp', false),
                'device_fingerprint' => data_get($session->metadata, 'device_fingerprint'),
                'selected_offer_usage' => null,
            ],
        ]);
    }

    private function endChat(WhatsAppSession $session): WhatsAppSession
    {
        $session->update([
            'status' => 'ended',
            'last_step' => 'ended',
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->chatEnded());

        return $session->fresh('messages');
    }

    private function handleChangeNumber(WhatsAppSession $session): WhatsAppSession
    {
        $session->update([
            'status' => 'ended',
            'last_step' => 'ended',
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->changeNumberPrompt());

        return $session->fresh('messages');
    }

    private function handleChangeLocation(WhatsAppSession $session): WhatsAppSession
    {
        $metadata = $session->metadata ?? [];

        foreach ([
            'pending_venue_id',
            'selected_offer_usage',
            'selected_from_browse',
            'last_result_ids',
            'last_result_venues',
            'last_journey_previews',
            'last_objection_prompt',
            'last_escalation',
        ] as $key) {
            unset($metadata[$key]);
        }

        $session->update([
            'postcode' => null,
            'latitude' => null,
            'longitude' => null,
            'selected_venue_id' => null,
            'last_step' => 'awaiting_location',
            'status' => 'active',
            'metadata' => $metadata,
        ]);

        $this->askForLocation($session);

        return $session->fresh('messages');
    }

    private function handleChangeJourney(WhatsAppSession $session): WhatsAppSession
    {
        $metadata = $session->metadata ?? [];

        foreach ([
            'pending_venue_id',
            'selected_offer_usage',
            'selected_from_browse',
            'last_result_ids',
            'last_result_venues',
            'last_journey_previews',
            'last_objection_prompt',
            'last_escalation',
        ] as $key) {
            unset($metadata[$key]);
        }

        $session->update([
            'journey_type' => null,
            'selected_venue_id' => null,
            'last_step' => 'awaiting_journey',
            'status' => 'active',
            'metadata' => $metadata,
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->clarifyIntent());

        return $session->fresh('messages');
    }

    private function handleBackToVenueChoice(WhatsAppSession $session): WhatsAppSession
    {
        $metadata = $session->metadata ?? [];

        if ($session->last_step === 'awaiting_location') {
            $session->update([
                'journey_type' => null,
                'selected_venue_id' => null,
                'last_step' => 'awaiting_journey',
                'status' => 'active',
                'metadata' => array_merge($metadata, [
                    'pending_venue_id' => null,
                    'selected_offer_usage' => null,
                ]),
            ]);

            $this->outbound($session, 'text', $this->conversationFlow->clarifyIntent());
            return $session->fresh('messages');
        }

        $hasVenueList = ! empty(data_get($metadata, 'last_result_ids', []))
            || ! empty(data_get($metadata, 'last_result_venues', []));

        if (! $hasVenueList) {
            $this->outbound($session, 'text', $this->conversationFlow->noVenueToGoBackTo());
            return $session->fresh('messages');
        }

        $metadata['pending_venue_id'] = null;
        $metadata['selected_offer_usage'] = null;
        $metadata['selected_from_browse'] = null;

        $session->update([
            'selected_venue_id' => null,
            'last_step' => 'showing_results',
            'status' => 'active',
            'metadata' => $metadata,
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->changeVenuePrompt());

        return $session->fresh('messages');
    }

    private function handleBiggerVoucherIntent(WhatsAppSession $session): WhatsAppSession
    {
        if (! config('talktocas.bnpl.enabled', false)) {
            $this->outbound($session, 'text', $this->conversationFlow->fallback());
            return $session->fresh('messages');
        }

        $link = $this->bnplLink();
        if (! $link) {
            $this->outbound($session, 'text', $this->conversationFlow->fallback());
            return $session->fresh('messages');
        }

        $this->outbound($session, 'text', $this->conversationFlow->bnplOffer($link));
        return $session->fresh('messages');
    }

    private function bnplLink(): ?string
    {
        $baseUrl = (string) config('talktocas.bnpl.base_url', '');
        if (filled($baseUrl)) {
            return rtrim($baseUrl, '/');
        }

        $fallback = (string) config('talktocas.bnpl.link', '');

        return filled($fallback) ? $fallback : null;
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
        $tag = $this->tagButtonService->createFromSession($session, $venueName);
        $tagLink = $this->tagButtonService->publicUrl($tag);

        $metadata = $session->metadata ?? [];
        $metadata['tagged_venues'][] = [
            'name' => $venueName,
            'share_code' => $tag->share_code,
            'link' => $tagLink,
            'created_at' => now()->toIso8601String(),
        ];

        $session->update([
            'last_step' => 'awaiting_journey',
            'metadata' => $metadata,
        ]);

        $this->outbound($session, 'text', $this->conversationFlow->tagCreated($venueName, $tagLink), [
            'tag' => [
                'share_code' => $tag->share_code,
                'venue_name' => $tag->venue_name,
                'public_url' => $tagLink,
                'join_url' => $this->tagButtonService->joinUrl($tag),
            ],
        ]);
        return $session->fresh('messages');
    }


    private function presentObjectionChoices(WhatsAppSession $session, string $objectionType): WhatsAppSession
    {
        $prompt = $this->buildObjectionPrompt($session, $objectionType);
        $metadata = array_merge($session->metadata ?? [], [
            'last_objection_prompt' => $prompt,
        ]);

        $session->update([
            'last_step' => 'awaiting_objection_choice',
            'metadata' => $metadata,
        ]);

        $this->outbound($session, 'objection_prompt', $this->conversationFlow->objectionPrompt(
            $this->activeJourneyForResults($session),
            $objectionType,
            $prompt['options'] ?? []
        ), [
            'prompt' => $prompt,
        ]);

        return $session->fresh('messages');
    }

    private function handleObjectionChoice(WhatsAppSession $session, string $normalized): WhatsAppSession
    {
        $choice = match ($normalized) {
            '1' => 0,
            '2' => 1,
            '3' => 2,
            default => null,
        };

        $prompt = data_get($session->metadata, 'last_objection_prompt', []);
        $options = $prompt['options'] ?? [];

        if ($choice === null || ! isset($options[$choice])) {
            $this->outbound($session, 'text', $this->conversationFlow->invalidObjectionChoice());
            return $session->fresh('messages');
        }

        $selected = $options[$choice];
        $strategy = $selected['key'] ?? null;
        $objectionType = $prompt['objection_type'] ?? null;

        $this->outbound($session, 'text', $this->conversationFlow->objectionChoiceAcknowledgement($objectionType, $strategy));

        return $this->applyObjectionStrategy($session, (string) $strategy);
    }

    private function applyObjectionStrategy(WhatsAppSession $session, string $strategy): WhatsAppSession
    {
        return match ($strategy) {
            'closest' => $this->refreshLastResultsByStrategy($session, 'closest'),
            'best_value' => $this->refreshLastResultsByStrategy($session, 'best_value'),
            'delivery_friendly' => $this->refreshLastResultsByStrategy($session, 'delivery_friendly'),
            'indoor' => $this->refreshLastResultsByStrategy($session, 'indoor'),
            'see_both' => $this->presentCombinedResults($session),
            'switch_food' => $this->presentJourneyResults($session, 'food', $session->postcode, $session->latitude, $session->longitude),
            'switch_nightlife' => $this->presentJourneyResults($session, 'nightlife', $session->postcode, $session->latitude, $session->longitude),
            'upgrade_voucher' => $this->handleBiggerVoucherIntent($session),
            default => $session->fresh('messages'),
        };
    }

    private function buildObjectionPrompt(WhatsAppSession $session, string $objectionType): array
    {
        $journeyType = $this->activeJourneyForResults($session);

        $title = match ($objectionType) {
            'distance' => 'Let’s tighten this up around closer options.',
            'weather' => 'Let’s adapt this around the weather.',
            'unsure' => 'I’ll narrow this down quickly.',
            default => 'Let’s keep this budget-friendly.',
        };

        $body = match ($objectionType) {
            'distance' => 'I can re-rank the closest options first, switch lane, or show both.',
            'weather' => 'I can lean into indoor or delivery-friendly options, switch lane, or show both.',
            'unsure' => 'I can show the closest picks, the best-value picks, or both lanes together.',
            default => $this->conversationFlow->budgetObjection($journeyType, data_get($session->metadata, 'last_weather.condition_key')),
        };

        $options = match ($objectionType) {
            'distance' => [
                ['key' => 'closest', 'label' => 'Show the closest picks first'],
                ['key' => $journeyType === 'nightlife' ? 'switch_food' : 'see_both', 'label' => $journeyType === 'nightlife' ? 'Switch me to food instead' : 'Show both lanes'],
                ['key' => 'upgrade_voucher', 'label' => 'Show bigger voucher options'],
            ],
            'weather' => $journeyType === 'food'
                ? [
                    ['key' => 'delivery_friendly', 'label' => 'Prioritise delivery-friendly food'],
                    ['key' => 'closest', 'label' => 'Keep it nearby'],
                    ['key' => 'see_both', 'label' => 'Show both lanes'],
                ]
                : [
                    ['key' => 'indoor', 'label' => 'Prioritise indoor / weather-safe picks'],
                    ['key' => 'switch_food', 'label' => 'Switch me to food instead'],
                    ['key' => 'see_both', 'label' => 'Show both lanes'],
                ],
            'unsure' => [
                ['key' => 'closest', 'label' => 'Show the closest picks'],
                ['key' => 'best_value', 'label' => 'Show the best-value picks'],
                ['key' => 'see_both', 'label' => 'Show both lanes'],
            ],
            default => [
                ['key' => 'closest', 'label' => 'Keep it to the closest options'],
                ['key' => 'best_value', 'label' => 'Show the best-value picks'],
                ['key' => $journeyType === 'nightlife' ? 'switch_food' : ($journeyType === 'food' ? 'upgrade_voucher' : 'see_both'), 'label' => $journeyType === 'nightlife' ? 'Switch me to food instead' : ($journeyType === 'food' ? 'Show bigger voucher options' : 'Show both lanes')],
            ],
        };

        return [
            'objection_type' => $objectionType,
            'title' => $title,
            'body' => $body,
            'options' => $options,
        ];
    }

    private function activeJourneyForResults(WhatsAppSession $session): string
    {
        $type = (string) (data_get($session->metadata, 'last_journey_results_type') ?: $session->journey_type ?: 'nightlife');

        return in_array($type, ['food', 'nightlife', 'both', 'browse'], true) ? $type : 'nightlife';
    }

    private function refreshLastResultsByStrategy(WhatsAppSession $session, string $strategy): WhatsAppSession
    {
        $journeyType = $this->activeJourneyForResults($session);
        $weather = data_get($session->metadata, 'last_weather');

        if ($journeyType === 'both') {
            $nightlifeVenues = $this->sortVenuesByStrategy(array_values(data_get($session->metadata, 'pending_browse_results.nightlife', [])), $strategy);
            $foodVenues = $this->sortVenuesByStrategy(array_values(data_get($session->metadata, 'pending_browse_results.food', [])), $strategy);
            if (empty($nightlifeVenues) && empty($foodVenues)) {
                return $this->presentCombinedResults($session);
            }

            $combined = collect($nightlifeVenues)->take(3)->merge(collect($foodVenues)->take(3))->sortByDesc('score')->take(5)->values()->all();
            $budgetTips = array_slice(array_values(array_unique(array_merge(
                $this->conversationFlow->budgetTips('nightlife', data_get($weather, 'condition_key')),
                $this->conversationFlow->budgetTips('food', data_get($weather, 'condition_key')),
            ))), 0, 4);

            return $this->sendResultsPayload(
                $session,
                'both',
                $combined,
                $weather,
                data_get($session->metadata, 'last_weather_note'),
                $budgetTips,
                [
                    'pending_browse_results' => [
                        'nightlife' => $nightlifeVenues,
                        'food' => $foodVenues,
                    ],
                    'objection_strategy' => $strategy,
                ],
                $this->conversationFlow->combinedResultsSummary($nightlifeVenues, $foodVenues, $weather)
            );
        }

        $venues = array_values(data_get($session->metadata, 'last_result_venues', []));
        if (empty($venues)) {
            $venues = array_values(data_get($session->metadata, 'pending_browse_results.' . $journeyType, []));
        }

        if (empty($venues)) {
            return $this->presentJourneyResults($session, $journeyType === 'food' ? 'food' : 'nightlife', $session->postcode, $session->latitude, $session->longitude);
        }

        $venues = $this->sortVenuesByStrategy($venues, $strategy);

        return $this->sendResultsPayload(
            $session,
            $journeyType === 'food' ? 'food' : 'nightlife',
            $venues,
            $weather,
            null,
            null,
            ['objection_strategy' => $strategy]
        );
    }

    private function sortVenuesByStrategy(array $venues, string $strategy): array
    {
        $collection = collect($venues);

        $sorted = match ($strategy) {
            'closest' => $collection->sort(function (array $left, array $right) {
                $leftDistance = $left['distance'] ?? PHP_INT_MAX;
                $rightDistance = $right['distance'] ?? PHP_INT_MAX;
                $compare = ($leftDistance <=> $rightDistance);
                return $compare !== 0 ? $compare : (($right['score'] ?? 0) <=> ($left['score'] ?? 0));
            }),
            'best_value' => $collection->sort(function (array $left, array $right) {
                $offerCompare = ((float) ($right['offer_value'] ?? 0)) <=> ((float) ($left['offer_value'] ?? 0));
                return $offerCompare !== 0 ? $offerCompare : (($right['score'] ?? 0) <=> ($left['score'] ?? 0));
            }),
            'delivery_friendly' => $collection->sort(function (array $left, array $right) {
                $leftRank = in_array($left['fulfilment_type'] ?? null, ['delivery', 'both'], true) ? 1 : 0;
                $rightRank = in_array($right['fulfilment_type'] ?? null, ['delivery', 'both'], true) ? 1 : 0;
                $compare = $rightRank <=> $leftRank;
                return $compare !== 0 ? $compare : (($right['score'] ?? 0) <=> ($left['score'] ?? 0));
            }),
            'indoor' => $collection->sort(function (array $left, array $right) {
                $leftRank = empty($left['weather_note']) ? 0 : 1;
                $rightRank = empty($right['weather_note']) ? 0 : 1;
                $compare = $rightRank <=> $leftRank;
                return $compare !== 0 ? $compare : (($right['score'] ?? 0) <=> ($left['score'] ?? 0));
            }),
            default => $collection->sortByDesc('score'),
        };

        return $sorted->values()->all();
    }

    private function sendResultsPayload(
        WhatsAppSession $session,
        string $journeyType,
        array $venues,
        ?array $weather,
        ?string $weatherNote = null,
        ?array $budgetTips = null,
        array $extraMetadata = [],
        ?string $summary = null
    ): WhatsAppSession {
        $weatherNote = $weatherNote ?? ($journeyType === 'both'
            ? data_get($session->metadata, 'last_weather_note')
            : $this->conversationFlow->renderWeatherNote($journeyType, $weather));

        $budgetTips = $budgetTips ?? ($journeyType === 'both'
            ? array_slice(array_values(array_unique(array_merge(
                $this->conversationFlow->budgetTips('nightlife', data_get($weather, 'condition_key')),
                $this->conversationFlow->budgetTips('food', data_get($weather, 'condition_key')),
            ))), 0, 4)
            : $this->conversationFlow->budgetTips($journeyType, data_get($weather, 'condition_key')));

        $visibleVenueLimit = max(3, min(10, (int) config('talktocas.conversation.minimum_visible_venues', 3)));
        $visibleVenues = array_slice($venues, 0, $visibleVenueLimit);
        $couponProfile = data_get($session->metadata, 'last_coupon_profile');
        $escalation = $this->buildEscalationPayload($journeyType, $visibleVenues, $weather, is_array($couponProfile) ? $couponProfile : []);

        $metadata = array_merge($session->metadata ?? [], $extraMetadata, [
            'last_result_ids' => collect($visibleVenues)->pluck('id')->values()->all(),
            'last_result_venues' => $visibleVenues,
            'last_weather' => $weather,
            'last_weather_note' => $weatherNote,
            'last_budget_tips' => $budgetTips,
            'last_journey_results_type' => $journeyType,
            'last_escalation' => $escalation,
            'last_objection_prompt' => null,
        ]);

        $session->update([
            'journey_type' => $journeyType === 'both' ? 'browse' : $journeyType,
            'last_step' => 'showing_results',
            'metadata' => $metadata,
        ]);

        $summary = $summary ?: $this->conversationFlow->resultsSummary($journeyType, $visibleVenues, $weather);
        $this->outbound($session, 'venue_results', $summary, [
            'venues' => $visibleVenues,
            'weather' => $weather,
            'weather_note' => $weatherNote,
            'budget_tips' => $budgetTips,
            'escalation' => $escalation,
        ]);
        $this->outbound($session, 'text', $this->conversationFlow->askVenueChoice());

        return $session->fresh('messages');
    }

    private function buildEscalationPayload(string $journeyType, array $venues, ?array $weather, array $couponProfile = []): array
    {
        $items = [];
        $highestLevel = 1;

        $firstUrgency = collect($venues)->map(fn (array $venue) => $venue['urgency'] ?? null)->first(fn ($urgency) => is_array($urgency) && ($urgency['enabled'] ?? false));
        if ($firstUrgency) {
            $items[] = [
                'level' => 1,
                'title' => 'Offer window live',
                'message' => ! empty($firstUrgency['offer_window_label'])
                    ? 'Offer window live now: ' . $firstUrgency['offer_window_label']
                    : 'Offer window live now.',
            ];
        }

        $lowInventoryVenue = collect($venues)->first(function (array $venue) {
            return (bool) data_get($venue, 'urgency.is_low_inventory', false);
        });

        if ($lowInventoryVenue) {
            $highestLevel = 2;
            $remaining = (int) data_get($lowInventoryVenue, 'urgency.remaining_count', 0);
            $items[] = [
                'level' => 2,
                'title' => 'Limited live vouchers',
                'message' => sprintf('Only %d left tonight for %s.', $remaining, $lowInventoryVenue['name']),
            ];
        }

        if ($journeyType === 'food') {
            $items[] = [
                'level' => 3,
                'title' => 'Eligibility filter',
                'message' => ($couponProfile['is_ubereats_existing_customer'] ?? null) === true
                    ? 'Existing-customer food promos only — new-customer-only codes are filtered out.'
                    : 'Live eligible food promos are being prioritised for this user.',
            ];
            $highestLevel = max($highestLevel, 3);
        } elseif ($journeyType === 'nightlife') {
            $items[] = [
                'level' => 3,
                'title' => 'Eligibility filter',
                'message' => ($couponProfile['is_uber_existing_customer'] ?? null) === true
                    ? 'Ride-only new-customer promos are hidden for this user where needed.'
                    : 'Live eligible ride offers are being prioritised for this user.',
            ];
            $highestLevel = max($highestLevel, 3);
        }

        if ($this->bnplLink()) {
            $items[] = [
                'level' => 4,
                'title' => 'Upgrade path',
                'message' => 'Need a bigger voucher? Type "upgrade voucher" to see £30 / £50 / £75 / £100 options.',
            ];
            $highestLevel = max($highestLevel, 4);
        }

        $headline = collect($items)->sortByDesc('level')->first()['message'] ?? null;

        return [
            'highest_level' => $highestLevel,
            'headline' => $headline,
            'items' => array_values($items),
            'upgrade_available' => (bool) $this->bnplLink(),
            'weather' => $weather,
        ];
    }

    private function presentJourneyResults(WhatsAppSession $session, string $journeyType, ?string $postcode, mixed $latitude, mixed $longitude): WhatsAppSession
    {
        $session->loadMissing('user');
        $result = $this->discoveryService->searchWithContext(
            $journeyType,
            $postcode,
            $latitude !== null ? (float) $latitude : null,
            $longitude !== null ? (float) $longitude : null,
            $session->user,
            [
                'is_uber_existing_customer' => $session->user?->is_uber_existing_customer,
                'is_ubereats_existing_customer' => $session->user?->is_ubereats_existing_customer,
            ],
        );

        $venues = $result['venues'] ?? [];
        $weather = $result['weather'] ?? null;
        $liveArea = $result['live_area'] ?? null;
        $resolvedLocation = $result['resolved_location'] ?? [
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        $session->update([
            'metadata' => array_merge($session->metadata ?? [], [
                'last_live_area' => $liveArea,
                'last_resolved_location' => $resolvedLocation,
            ]),
        ]);

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

        return $this->sendResultsPayload(
            $session,
            $journeyType,
            $venues,
            $weather,
            null,
            null,
            [
                'last_live_area' => $liveArea,
                'last_resolved_location' => $resolvedLocation,
                'last_coupon_profile' => $result['coupon_profile'] ?? null,
            ]
        );
    }

    private function presentBrowseOptions(WhatsAppSession $session, ?string $postcode, mixed $latitude, mixed $longitude): WhatsAppSession
    {
        $session->loadMissing('user');

        $nightlife = $this->discoveryService->searchWithContext(
            'nightlife',
            $postcode,
            $latitude !== null ? (float) $latitude : null,
            $longitude !== null ? (float) $longitude : null,
            $session->user,
            [
                'is_uber_existing_customer' => $session->user?->is_uber_existing_customer,
                'is_ubereats_existing_customer' => $session->user?->is_ubereats_existing_customer,
            ],
        );

        $food = $this->discoveryService->searchWithContext(
            'food',
            $postcode,
            $latitude !== null ? (float) $latitude : null,
            $longitude !== null ? (float) $longitude : null,
            $session->user,
            [
                'is_uber_existing_customer' => $session->user?->is_uber_existing_customer,
                'is_ubereats_existing_customer' => $session->user?->is_ubereats_existing_customer,
            ],
        );

        $weather = $nightlife['weather'] ?? $food['weather'] ?? null;
        $liveArea = $nightlife['live_area'] ?? $food['live_area'] ?? null;
        $resolvedLocation = $nightlife['resolved_location'] ?? $food['resolved_location'] ?? [
            'postcode' => $postcode,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if (($liveArea['enabled'] ?? false) && ! ($liveArea['is_live'] ?? true)) {
            $session->update([
                'last_step' => 'no_results',
                'metadata' => array_merge($session->metadata ?? [], [
                    'last_live_area' => $liveArea,
                    'last_resolved_location' => $resolvedLocation,
                ]),
            ]);
            $this->outbound($session, 'text', $this->conversationFlow->comingSoonArea($postcode, $this->tagLink('invite')));
            return $session->fresh('messages');
        }

        $nightlifeVenues = array_slice($nightlife['venues'] ?? [], 0, 5);
        $foodVenues = array_slice($food['venues'] ?? [], 0, 5);

        if (empty($nightlifeVenues) && empty($foodVenues)) {
            $session->update(['last_step' => 'no_results']);
            $this->outbound($session, 'text', $this->conversationFlow->noResults($this->tagLink('invite')));
            return $session->fresh('messages');
        }

        $weatherNote = $this->conversationFlow->renderWeatherNote('nightlife', $weather);
        $budgetTips = array_values(array_unique(array_merge(
            $this->conversationFlow->budgetTips('nightlife', data_get($weather, 'condition_key')),
            $this->conversationFlow->budgetTips('food', data_get($weather, 'condition_key')),
        )));

        $journeyPreviews = [
            [
                'key' => 'nightlife',
                'title' => 'Going Out Tonight',
                'count' => count($nightlifeVenues),
                'top_venue' => $nightlifeVenues[0]['name'] ?? null,
                'offer_hint' => isset($nightlifeVenues[0]['offer_value']) ? '£' . number_format((float) $nightlifeVenues[0]['offer_value'], 0) . ' ride voucher' : null,
            ],
            [
                'key' => 'food',
                'title' => 'Ordering Food',
                'count' => count($foodVenues),
                'top_venue' => $foodVenues[0]['name'] ?? null,
                'offer_hint' => isset($foodVenues[0]['offer_value']) ? '£' . number_format((float) $foodVenues[0]['offer_value'], 0) . ' off food' : null,
            ],
        ];

        $session->update([
            'journey_type' => 'browse',
            'last_step' => 'awaiting_weather_choice',
            'metadata' => array_merge($session->metadata ?? [], [
                'last_weather' => $weather,
                'last_weather_note' => $weatherNote,
                'last_budget_tips' => array_slice($budgetTips, 0, 4),
                'last_live_area' => $liveArea,
                'last_resolved_location' => $resolvedLocation,
                'pending_browse_results' => [
                    'nightlife' => $nightlifeVenues,
                    'food' => $foodVenues,
                ],
                'last_journey_previews' => $journeyPreviews,
            ]),
        ]);

        $this->outbound($session, 'journey_preview', $this->conversationFlow->browseOptionsSummary($weatherNote, $journeyPreviews), [
            'journey_previews' => $journeyPreviews,
            'weather' => $weather,
            'weather_note' => $weatherNote,
            'budget_tips' => array_slice($budgetTips, 0, 4),
        ]);

        return $session->fresh('messages');
    }

    private function presentStoredJourneyResults(WhatsAppSession $session, string $journeyType): WhatsAppSession
    {
        $storedVenues = array_values(data_get($session->metadata, 'pending_browse_results.' . $journeyType, []));
        if (empty($storedVenues)) {
            return $this->presentJourneyResults($session, $journeyType, $session->postcode, $session->latitude, $session->longitude);
        }

        $weather = data_get($session->metadata, 'last_weather');

        return $this->sendResultsPayload(
            $session,
            $journeyType,
            $storedVenues,
            $weather
        );
    }

    private function presentCombinedResults(WhatsAppSession $session): WhatsAppSession
    {
        $nightlifeVenues = array_values(data_get($session->metadata, 'pending_browse_results.nightlife', []));
        $foodVenues = array_values(data_get($session->metadata, 'pending_browse_results.food', []));

        if (empty($nightlifeVenues) && empty($foodVenues)) {
            return $this->presentBrowseOptions($session, $session->postcode, $session->latitude, $session->longitude);
        }

        $weather = data_get($session->metadata, 'last_weather');
        $budgetTips = array_slice(array_values(array_unique(array_merge(
            $this->conversationFlow->budgetTips('nightlife', data_get($weather, 'condition_key')),
            $this->conversationFlow->budgetTips('food', data_get($weather, 'condition_key')),
        ))), 0, 4);

        $combined = collect($nightlifeVenues)
            ->take(3)
            ->merge(collect($foodVenues)->take(3))
            ->sortByDesc('score')
            ->take(5)
            ->values()
            ->all();

        return $this->sendResultsPayload(
            $session,
            'both',
            $combined,
            $weather,
            data_get($session->metadata, 'last_weather_note'),
            $budgetTips,
            [
                'pending_browse_results' => [
                    'nightlife' => $nightlifeVenues,
                    'food' => $foodVenues,
                ],
            ],
            $this->conversationFlow->combinedResultsSummary($nightlifeVenues, $foodVenues, $weather)
        );
    }

    private function inferJourneyTypeFromVenue(Venue $venue): string
    {
        if (in_array($venue->category, ['restaurant', 'takeaway', 'cafe'], true) || $venue->offer_type === 'food') {
            return 'food';
        }

        return 'nightlife';
    }

    private function isBrowseJourneyType(?string $journeyType): bool
    {
        return in_array($journeyType, [null, '', 'browse', 'both'], true);
    }

    private function restartVenueChoice(WhatsAppSession $session): WhatsAppSession
    {
        $session->update(['last_step' => 'showing_results']);
        $this->outbound($session, 'text', $this->conversationFlow->chooseAgain());
        return $session->fresh('messages');
    }

    private function nextStepAfterVenueSelection(): string
    {
        if (config('talktocas.conversation.ask_email', true)) {
            return 'awaiting_email';
        }

        if (config('talktocas.conversation.require_consent', true)) {
            return 'awaiting_consent';
        }

        return 'issuing_voucher';
    }

    private function parseDualChoiceUsage(string $normalized): ?string
    {
        if (in_array($normalized, ['1', 'ride', 'uber', 'going out', 'going out tonight'], true)) {
            return 'ride';
        }

        if (in_array($normalized, ['2', 'food', 'ubereats', 'order food', 'ordering food'], true)) {
            return 'food';
        }

        return null;
    }

    private function resolveVoucherJourneyType(?string $sessionJourneyType, ?string $selectedUsage = null): string
    {
        if ($selectedUsage === 'food') {
            return 'food';
        }

        if ($selectedUsage === 'ride') {
            return 'ride';
        }

        return strtolower((string) $sessionJourneyType) === 'food' ? 'food' : 'ride';
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
                    'flow_version' => 'cas_two_option_v2',
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
        if (in_array($session->status, ['voucher_issued', 'completed', 'closed', 'expired', 'ended'], true)) {
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
        $user = User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'email' => 'user+' . Str::lower(Str::random(8)) . '@talktocas.local',
                'password' => Str::random(16),
                'role' => 'user',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );

        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        if ($name && $user->name === 'WhatsApp User') {
            $user->forceFill(['name' => $name])->save();
        }

        return $user;
    }

    private function currentDeviceFingerprint(WhatsAppSession $session): ?string
    {
        return data_get($session->metadata, 'device_fingerprint')
            ?: data_get($session->metadata, 'fingerprint')
            ?: null;
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

    private function isEndIntent(string $normalized): bool
    {
        return in_array($normalized, ['end', 'end chat', 'stop', 'finish', 'close', 'close chat', 'done', 'no thanks'], true);
    }

    private function isChangeNumberIntent(string $normalized): bool
    {
        return in_array($normalized, ['change number', 'wrong number', 'new number', 'different number', 'phone number', 'change phone'], true);
    }

    private function isChangeLocationIntent(string $normalized): bool
    {
        return in_array($normalized, ['change postcode', 'change post code', 'change zip', 'change zipcode', 'change zip code', 'wrong postcode', 'wrong post code', 'wrong zip', 'new postcode', 'new zip', 'different postcode', 'different zip', 'change location', 'wrong location'], true);
    }

    private function isChangeJourneyIntent(string $normalized): bool
    {
        return in_array($normalized, ['change option', 'change journey', 'change category', 'change choice', 'back to menu', 'main menu'], true);
    }

    private function isBackIntent(string $normalized): bool
    {
        return in_array($normalized, ['back', 'go back', 'change', 'change venue', 'choose again', 'another venue', 'wrong venue', 'wrong selected', 'selected wrong'], true);
    }

    private function isGoingOutIntent(string $normalized): bool
    {
        return in_array($normalized, ['1', 'going-out tonight', 'going out tonight', 'going-out', 'going out', 'nightlife', 'clubbing'], true);
    }

    private function isFoodIntent(string $normalized): bool
    {
        return in_array($normalized, ['2', 'ordering-food', 'ordering food', 'order food', 'food', 'restaurant', 'takeaway'], true);
    }

    private function isBrowseIntent(string $normalized): bool
    {
        return in_array($normalized, ['neither', 'neither / just browsing', 'just browsing', 'browsing', 'browse', 'see both', 'show both', 'both'], true);
    }

    private function looksLikePostcode(string $normalized): bool
    {
        return preg_match('/^[a-z0-9\-\s]{4,10}$/i', $normalized) === 1;
    }

    private function isBiggerVoucherIntent(string $normalized): bool
    {
        return in_array($normalized, ['bigger voucher', 'more discount', 'higher amount', 'upgrade voucher'], true);
    }

    private function isBudgetIntent(string $normalized): bool
    {
        return str_contains($normalized, 'skint')
            || str_contains($normalized, 'broke')
            || str_contains($normalized, 'cheap')
            || str_contains($normalized, 'budget')
            || str_contains($normalized, 'too expensive')
            || str_contains($normalized, 'cant afford')
            || str_contains($normalized, "can't afford");
    }

    private function isDistanceIntent(string $normalized): bool
    {
        return str_contains($normalized, 'too far')
            || str_contains($normalized, 'far away')
            || str_contains($normalized, 'distance')
            || $normalized === 'far';
    }

    private function isWeatherIntent(string $normalized): bool
    {
        return str_contains($normalized, 'rain')
            || str_contains($normalized, 'raining')
            || str_contains($normalized, 'weather')
            || str_contains($normalized, 'snow')
            || str_contains($normalized, 'cold');
    }

    private function isUnsureIntent(string $normalized): bool
    {
        return str_contains($normalized, 'not sure')
            || str_contains($normalized, 'unsure')
            || str_contains($normalized, "don't know")
            || str_contains($normalized, 'dont know')
            || $normalized === 'idk';
    }

    private function detectObjectionType(string $normalized, WhatsAppSession $session): ?string
    {
        $hasContext = in_array($session->last_step, ['awaiting_weather_choice', 'showing_results', 'awaiting_objection_choice', 'awaiting_dual_choice_mode', 'awaiting_email', 'awaiting_consent'], true)
            || ! empty(data_get($session->metadata, 'last_result_venues'));

        if (! $hasContext) {
            return null;
        }

        if ($this->isBudgetIntent($normalized)) {
            return 'budget';
        }

        if ($this->isDistanceIntent($normalized)) {
            return 'distance';
        }

        if ($this->isWeatherIntent($normalized)) {
            return 'weather';
        }

        if ($this->isUnsureIntent($normalized)) {
            return 'unsure';
        }

        return null;
    }

    private function isTagIntent(string $normalized): bool
    {
        return in_array($normalized, ['tag', 'tag now', 'invite venue', 'invite my venue'], true);
    }

    private function tagLink(string $venueName): string
    {
        $base = rtrim((string) config('talktocas.tag_button.base_url'), '/');

        if (in_array(strtolower(trim($venueName)), ['invite', 'tag'], true)) {
            return $base . '/new';
        }

        $query = http_build_query(['venue' => $venueName]);
        return $query ? $base . '/new?' . $query : $base . '/new';
    }
}
