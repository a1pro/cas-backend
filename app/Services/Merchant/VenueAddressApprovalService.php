<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueAddressChangeRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VenueAddressApprovalService
{
    public function createOrReplacePendingRequest(
        Merchant $merchant,
        Venue $venue,
        User $requestedBy,
        array $currentSnapshot,
        array $requestedSnapshot,
        ?string $supportMessage = null
    ): VenueAddressChangeRequest {
        return DB::transaction(function () use ($merchant, $venue, $requestedBy, $currentSnapshot, $requestedSnapshot, $supportMessage) {
            VenueAddressChangeRequest::query()
                ->where('venue_id', $venue->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'superseded',
                    'admin_notes' => 'Replaced by a newer address change request.',
                    'resolved_at' => now(),
                ]);

            return VenueAddressChangeRequest::create([
                'merchant_id' => $merchant->id,
                'venue_id' => $venue->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => 'pending',
                'current_snapshot' => $currentSnapshot,
                'requested_snapshot' => $requestedSnapshot,
                'request_code' => 'ADDR-' . strtoupper(Str::random(8)),
                'support_message' => $supportMessage ?: '⚠️ Please contact support. Address changes require admin approval.',
                'submitted_at' => now(),
            ]);
        });
    }

    public function latestForVenue(?Venue $venue): ?VenueAddressChangeRequest
    {
        if (! $venue) {
            return null;
        }

        return VenueAddressChangeRequest::query()
            ->where('venue_id', $venue->id)
            ->latest()
            ->first();
    }

    public function latestPendingForVenue(?Venue $venue): ?VenueAddressChangeRequest
    {
        if (! $venue) {
            return null;
        }

        return VenueAddressChangeRequest::query()
            ->where('venue_id', $venue->id)
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    public function merchantPayload(?Venue $venue): array
    {
        $latest = $this->latestForVenue($venue);
        $pending = $this->latestPendingForVenue($venue);

        return [
            'summary' => [
                'pending_count' => $venue
                    ? VenueAddressChangeRequest::query()->where('venue_id', $venue->id)->where('status', 'pending')->count()
                    : 0,
                'latest_status' => $latest?->status,
            ],
            'latest_request' => $latest ? $this->payload($latest) : null,
            'pending_request' => $pending ? $this->payload($pending) : null,
        ];
    }

    public function adminDashboardPayload(int $limit = 8): array
    {
        $pending = VenueAddressChangeRequest::query()->where('status', 'pending');

        return [
            'summary' => [
                'pending_requests' => (clone $pending)->count(),
                'approved_today' => VenueAddressChangeRequest::query()
                    ->where('status', 'approved')
                    ->whereDate('resolved_at', today())
                    ->count(),
                'rejected_today' => VenueAddressChangeRequest::query()
                    ->where('status', 'rejected')
                    ->whereDate('resolved_at', today())
                    ->count(),
            ],
            'recent_requests' => VenueAddressChangeRequest::query()
                ->with(['merchant', 'venue', 'requestedBy'])
                ->latest()
                ->take($limit)
                ->get()
                ->map(fn (VenueAddressChangeRequest $request) => $this->payload($request))
                ->values()
                ->all(),
        ];
    }

    public function listPayloads(?string $status = null, int $limit = 25): array
    {
        $query = VenueAddressChangeRequest::query()->with(['merchant', 'venue', 'requestedBy'])->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->take($limit)->get()->map(fn (VenueAddressChangeRequest $request) => $this->payload($request))->values()->all();
    }

    public function approve(VenueAddressChangeRequest $request, ?string $adminNotes = null): VenueAddressChangeRequest
    {
        return DB::transaction(function () use ($request, $adminNotes) {
            $venue = $request->venue()->firstOrFail();
            $snapshot = $request->requested_snapshot ?? [];

            $venue->update([
                'address' => Arr::get($snapshot, 'address'),
                'city' => Arr::get($snapshot, 'city'),
                'postcode' => Arr::get($snapshot, 'postcode'),
                'latitude' => Arr::get($snapshot, 'latitude'),
                'longitude' => Arr::get($snapshot, 'longitude'),
            ]);

            $request->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes ?: $request->admin_notes,
                'resolved_at' => now(),
            ]);

            return $request->fresh(['merchant', 'venue', 'requestedBy']);
        });
    }

    public function reject(VenueAddressChangeRequest $request, ?string $adminNotes = null): VenueAddressChangeRequest
    {
        $request->update([
            'status' => 'rejected',
            'admin_notes' => $adminNotes ?: $request->admin_notes,
            'resolved_at' => now(),
        ]);

        return $request->fresh(['merchant', 'venue', 'requestedBy']);
    }

    public function payload(VenueAddressChangeRequest $request): array
    {
        return [
            'id' => $request->id,
            'request_code' => $request->request_code,
            'status' => $request->status,
            'support_message' => $request->support_message,
            'admin_notes' => $request->admin_notes,
            'merchant' => $request->merchant ? [
                'id' => $request->merchant->id,
                'business_name' => $request->merchant->business_name,
            ] : null,
            'venue' => $request->venue ? [
                'id' => $request->venue->id,
                'name' => $request->venue->name,
                'postcode' => $request->venue->postcode,
            ] : null,
            'requested_by' => $request->requestedBy ? [
                'id' => $request->requestedBy->id,
                'name' => $request->requestedBy->name,
                'email' => $request->requestedBy->email,
            ] : null,
            'current_snapshot' => $request->current_snapshot,
            'requested_snapshot' => $request->requested_snapshot,
            'submitted_at' => optional($request->submitted_at)->toIso8601String(),
            'resolved_at' => optional($request->resolved_at)->toIso8601String(),
            'created_at' => optional($request->created_at)->toIso8601String(),
        ];
    }
}
