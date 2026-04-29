<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\WhatsApp\SelectVenueRequest;
use App\Http\Requests\WhatsApp\SendChatMessageRequest;
use App\Http\Requests\WhatsApp\ShareLocationRequest;
use App\Http\Requests\WhatsApp\StartChatSessionRequest;
use App\Models\Venue;
use App\Models\WhatsAppSession;
use App\Services\Chat\WhatsAppChatService;
use Illuminate\Support\Facades\DB;

class ChatSessionController extends BaseController
{
    public function __construct(private readonly WhatsAppChatService $whatsAppChatService)
    {
    }

    public function start(StartChatSessionRequest $request)
    {
        try {
            DB::beginTransaction();

            $session = $this->whatsAppChatService->startSession(array_merge(
                $request->validated(),
                ['device_fingerprint' => $request->header('X-Device-Fingerprint')]
            ));

            $data = $this->payload($session);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(WhatsAppSession $session)
    {
        try {
            $data = $this->payload($session->load(['messages', 'user', 'selectedVenue']));

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function message(WhatsAppSession $session, SendChatMessageRequest $request)
    {
        try {
            DB::beginTransaction();

            $session = $this->whatsAppChatService->handleMessage($session, $request->validated()['message']);

            $data = $this->payload($session->load(['messages', 'user', 'selectedVenue']));

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function location(WhatsAppSession $session, ShareLocationRequest $request)
    {
        try {
            DB::beginTransaction();

            $session = $this->whatsAppChatService->shareLocation($session, $request->validated());

            $data = $this->payload($session->load(['messages', 'user', 'selectedVenue']));

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function selectVenue(WhatsAppSession $session, SelectVenueRequest $request)
    {
        try {
            DB::beginTransaction();

            $venue = Venue::findOrFail($request->validated()['venue_id']);
            $session = $this->whatsAppChatService->selectVenue($session, $venue);

            $data = $this->payload($session->load(['messages', 'user', 'selectedVenue']));

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    private function payload(WhatsAppSession $session): array
    {
        $messages = $session->messages->sortBy('id')->values();

        $lastVenueResults = $messages->where('message_type', 'venue_results')->last();
        $lastJourneyPreview = $messages->where('message_type', 'journey_preview')->last();
        $lastObjectionPrompt = $messages->where('message_type', 'objection_prompt')->last();
        $lastVoucher = $messages->where('message_type', 'voucher')->last();
        $latestAssistantMessage = $messages->where('direction', 'outbound')->last();

        $lastStep = (string) $session->last_step;
        $storedVenueResults = $lastVenueResults?->payload['venues'] ?? data_get($session->metadata, 'last_result_venues', []);
        $venueResults = $lastStep === 'showing_results' ? $storedVenueResults : [];
        $journeyPreviews = $lastStep === 'awaiting_weather_choice'
            ? ($lastJourneyPreview?->payload['journey_previews'] ?? data_get($session->metadata, 'last_journey_previews', []))
            : [];

        $selectedVenue = in_array($lastStep, ['awaiting_dual_choice_mode', 'awaiting_email', 'awaiting_consent'], true)
            ? $this->resolveSelectedVenue($session, is_array($storedVenueResults) ? $storedVenueResults : [])
            : null;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'channel' => $session->channel,
            'mirror_to_whatsapp' => (bool) data_get($session->metadata, 'mirror_to_whatsapp', false),
            'journey_type' => $session->journey_type,
            'last_step' => $session->last_step,
            'postcode' => $session->postcode,
            'latitude' => $session->latitude,
            'longitude' => $session->longitude,
            'live_area' => in_array($lastStep, ['awaiting_journey', 'awaiting_location', 'ended'], true) ? null : data_get($session->metadata, 'last_live_area'),
            'resolved_location' => in_array($lastStep, ['awaiting_journey', 'awaiting_location', 'ended'], true) ? null : data_get($session->metadata, 'last_resolved_location'),
            'user' => [
                'id' => $session->user?->id,
                'name' => $session->user?->name,
                'phone' => $session->user?->phone,
            ],
            'messages' => $messages->map(fn ($message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'payload' => $message->payload,
                'created_at' => $message->created_at,
            ])->values(),
            'latest_assistant_message' => $latestAssistantMessage ? [
                'id' => $latestAssistantMessage->id,
                'direction' => $latestAssistantMessage->direction,
                'message_type' => $latestAssistantMessage->message_type,
                'body' => $latestAssistantMessage->body,
                'payload' => $latestAssistantMessage->payload,
                'created_at' => $latestAssistantMessage->created_at,
            ] : null,
            'venue_results' => $venueResults,
            'journey_previews' => $journeyPreviews,
            'weather_note' => $lastStep === 'showing_results'
                ? ($lastVenueResults?->payload['weather_note'] ?? data_get($session->metadata, 'last_weather_note'))
                : data_get($session->metadata, 'last_weather_note'),
            'budget_tips' => $lastStep === 'showing_results'
                ? ($lastVenueResults?->payload['budget_tips'] ?? data_get($session->metadata, 'last_budget_tips', []))
                : data_get($session->metadata, 'last_budget_tips', []),
            'weather' => $lastStep === 'showing_results'
                ? ($lastVenueResults?->payload['weather'] ?? data_get($session->metadata, 'last_weather'))
                : data_get($session->metadata, 'last_weather'),
            'escalation' => $lastStep === 'showing_results'
                ? ($lastVenueResults?->payload['escalation'] ?? data_get($session->metadata, 'last_escalation'))
                : data_get($session->metadata, 'last_escalation'),
            'objection_prompt' => $lastObjectionPrompt?->payload['prompt'] ?? data_get($session->metadata, 'last_objection_prompt'),
            'selected_venue' => $selectedVenue,
            'selected_offer_usage' => data_get($session->metadata, 'selected_offer_usage'),
            'voucher' => $session->last_step === 'voucher_issued' ? ($lastVoucher?->payload['voucher'] ?? null) : null,
        ];
    }

    private function resolveSelectedVenue(WhatsAppSession $session, array $venueResults): mixed
    {
        if (! $session->selected_venue_id) {
            return null;
        }

        $matched = collect($venueResults)->firstWhere('id', $session->selected_venue_id);
        if ($matched) {
            return $matched;
        }

        $selectedVenue = $session->selectedVenue;
        if (! $selectedVenue) {
            return null;
        }

        return [
            'id' => $selectedVenue->id,
            'name' => $selectedVenue->name,
            'category' => $selectedVenue->category,
            'city' => $selectedVenue->city,
            'postcode' => $selectedVenue->postcode,
            'address' => $selectedVenue->address,
            'latitude' => $selectedVenue->latitude,
            'longitude' => $selectedVenue->longitude,
            'offer_value' => $selectedVenue->offer_value,
            'minimum_order' => $selectedVenue->minimum_order,
            'offer_type' => $selectedVenue->offer_type,
            'offer_type_label' => match ($selectedVenue->offer_type) {
                'food' => 'Food offer',
                'ride' => 'Ride only',
                'dual_choice', 'dual' => 'Dual choice',
                default => null,
            },
            'ride_trip_type' => $selectedVenue->ride_trip_type,
            'ride_trip_type_label' => match ($selectedVenue->ride_trip_type) {
                'to_and_from' => '2 Trips (to-and-from)',
                'to_venue' => '1 Trip (to the venue)',
                default => null,
            },
            'can_choose_usage' => in_array($selectedVenue->offer_type, ['dual_choice', 'dual'], true),
        ];
    }
}
