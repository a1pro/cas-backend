<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Tag\TagButtonService;
use Illuminate\Http\Request;

class TagButtonController extends BaseController
{
    public function __construct(private readonly TagButtonService $tagButtonService)
    {
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'venue_name' => ['required', 'string', 'max:255'],
            'inviter_name' => ['nullable', 'string', 'max:255'],
            'inviter_phone' => ['nullable', 'string', 'max:50'],
            'inviter_email' => ['nullable', 'email', 'max:255'],
            'venue_contact_email' => ['nullable', 'email', 'max:255'],
            'venue_contact_phone' => ['nullable', 'string', 'max:50'],
            'source_channel' => ['nullable', 'string', 'max:50'],
        ]);

        $tag = $this->tagButtonService->create([
            ...$validated,
            'source_channel' => $validated['source_channel'] ?? 'website',
            'metadata' => [
                'created_from' => 'public_tag_form',
                'request_ip' => $request->ip(),
            ],
        ]);

        return $this->success([
            'tag' => $this->tagButtonService->toPublicPayload($tag),
        ], 'Venue tag created successfully', 201);
    }

    public function show(string $shareCode)
    {
        $tag = $this->tagButtonService->findPublic($shareCode);

        if (! $tag) {
            return $this->error('Venue tag not found.', 404);
        }

        return $this->success([
            'tag' => $this->tagButtonService->toPublicPayload($tag),
        ]);
    }

    public function resend(string $shareCode)
    {
        $tag = $this->tagButtonService->resendInvite($shareCode);

        if (! $tag) {
            return $this->error('Venue tag not found.', 404);
        }

        if ($tag->status === 'expired') {
            return $this->error('This venue tag has expired and cannot be re-sent.', 422);
        }

        return $this->success([
            'tag' => $this->tagButtonService->toPublicPayload($tag),
        ], 'Venue invite notifications sent');
    }
}
