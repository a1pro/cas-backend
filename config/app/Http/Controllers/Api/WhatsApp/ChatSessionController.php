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

class ChatSessionController extends BaseController
{
    public function __construct(private readonly WhatsAppChatService $whatsAppChatService)
    {
    }

    public function start(StartChatSessionRequest $request)
    {
        $session = $this->whatsAppChatService->startSession($request->validated());

        return $this->success($this->payload($session), 'WhatsApp demo session started', 201);
    }

    public function show(WhatsAppSession $session)
    {
        return $this->success($this->payload($session->load(['messages', 'user', 'selectedVenue'])));
    }

    public function message(WhatsAppSession $session, SendChatMessageRequest $request)
    {
        $session = $this->whatsAppChatService->handleMessage($session, $request->validated()['message']);

        return $this->success($this->payload($session->load(['messages', 'user', 'selectedVenue'])));
    }

    public function location(WhatsAppSession $session, ShareLocationRequest $request)
    {
        $session = $this->whatsAppChatService->shareLocation($session, $request->validated());

        return $this->success($this->payload($session->load(['messages', 'user', 'selectedVenue'])));
    }

    public function selectVenue(WhatsAppSession $session, SelectVenueRequest $request)
    {
        $venue = Venue::findOrFail($request->validated()['venue_id']);
        $session = $this->whatsAppChatService->selectVenue($session, $venue);

        return $this->success($this->payload($session->load(['messages', 'user', 'selectedVenue'])));
    }

    private function payload(WhatsAppSession $session): array
    {
        $lastVenueResults = $session->messages->where('message_type', 'venue_results')->last();

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
            'user' => [
                'id' => $session->user?->id,
                'name' => $session->user?->name,
                'phone' => $session->user?->phone,
            ],
            'messages' => $session->messages->map(fn ($message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'payload' => $message->payload,
                'created_at' => $message->created_at,
            ])->values(),
            'venue_results' => $lastVenueResults?->payload['venues'] ?? [],
            'weather_note' => $lastVenueResults?->payload['weather_note'] ?? null,
            'budget_tips' => $lastVenueResults?->payload['budget_tips'] ?? [],
            'weather' => $lastVenueResults?->payload['weather'] ?? null,
            'selected_venue' => $session->selectedVenue,
        ];
    }
}
