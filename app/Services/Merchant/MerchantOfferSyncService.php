<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\OfferSyncRequest;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantOfferSyncService
{
    public function createOrReplacePendingRequest(
        Merchant $merchant,
        Venue $venue,
        MerchantWallet $wallet,
        User $requestedBy,
        array $previousSnapshot,
        array $requestedSnapshot,
        array $changedFields = []
    ): OfferSyncRequest {
        return DB::transaction(function () use ($merchant, $venue, $requestedBy, $previousSnapshot, $requestedSnapshot, $changedFields) {
            OfferSyncRequest::query()
                ->where('venue_id', $venue->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'superseded',
                    'admin_notes' => 'Replaced by a newer merchant offer sync request.',
                ]);

            return OfferSyncRequest::create([
                'merchant_id' => $merchant->id,
                'venue_id' => $venue->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => 'pending',
                'previous_snapshot' => $previousSnapshot,
                'requested_snapshot' => $requestedSnapshot,
                'changed_fields' => array_values($changedFields),
                'export_code' => 'SYNC-' . strtoupper(Str::random(8)),
                'sync_due_at' => now()->addHours((int) config('talktocas.offer_sync.review_delay_hours', 24)),
            ]);
        });
    }

    public function latestForVenue(?Venue $venue): ?OfferSyncRequest
    {
        if (! $venue) {
            return null;
        }

        return OfferSyncRequest::query()
            ->where('venue_id', $venue->id)
            ->latest()
            ->first();
    }

    public function latestPendingForVenue(?Venue $venue): ?OfferSyncRequest
    {
        if (! $venue) {
            return null;
        }

        return OfferSyncRequest::query()
            ->where('venue_id', $venue->id)
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    public function merchantPayload(Merchant $merchant, ?Venue $venue): array
    {
        $latest = $this->latestForVenue($venue);
        $pending = $this->latestPendingForVenue($venue);

        return [
            'enabled' => (bool) config('talktocas.offer_sync.enabled', true),
            'delay_hours' => (int) config('talktocas.offer_sync.review_delay_hours', 24),
            'summary' => [
                'pending_count' => $venue
                    ? OfferSyncRequest::query()->where('venue_id', $venue->id)->where('status', 'pending')->count()
                    : 0,
                'latest_status' => $latest?->status,
            ],
            'latest_request' => $latest ? $this->requestPayload($latest) : null,
            'pending_request' => $pending ? $this->requestPayload($pending) : null,
        ];
    }

    public function adminDashboardPayload(int $limit = 8): array
    {
        $pending = OfferSyncRequest::query()->where('status', 'pending');

        return [
            'summary' => [
                'pending_requests' => (clone $pending)->count(),
                'overdue_pending_requests' => (clone $pending)->where('sync_due_at', '<', now())->count(),
                'synced_today' => OfferSyncRequest::query()
                    ->where('status', 'synced')
                    ->whereDate('synced_at', today())
                    ->count(),
            ],
            'recent_requests' => OfferSyncRequest::query()
                ->with(['merchant', 'venue', 'requestedBy'])
                ->latest()
                ->take($limit)
                ->get()
                ->map(fn (OfferSyncRequest $request) => $this->requestPayload($request))
                ->values()
                ->all(),
        ];
    }

    public function listPayloads(?string $status = null, int $limit = 25): array
    {
        $query = OfferSyncRequest::query()->with(['merchant', 'venue', 'requestedBy'])->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->take($limit)->get()->map(fn (OfferSyncRequest $request) => $this->requestPayload($request))->values()->all();
    }

    public function exportPendingPayload(): array
    {
        $requests = OfferSyncRequest::query()
            ->with(['merchant', 'venue', 'requestedBy'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        $rows = $requests->map(function (OfferSyncRequest $request) {
            $snapshot = $request->requested_snapshot ?? [];
            $merchant = Arr::get($snapshot, 'merchant', []);
            $venue = Arr::get($snapshot, 'venue', []);
            $wallet = Arr::get($snapshot, 'wallet', []);

            return [
                'export_code' => $request->export_code,
                'merchant_name' => $request->merchant?->business_name,
                'venue_name' => $request->venue?->name,
                'business_type' => Arr::get($merchant, 'business_type'),
                'offer_enabled' => Arr::get($venue, 'offer_enabled') ? 'yes' : 'no',
                'offer_type' => Arr::get($venue, 'offer_type'),
                'voucher_amount' => Arr::get($venue, 'offer_value'),
                'offer_days' => implode('|', Arr::wrap(Arr::get($venue, 'offer_days'))),
                'start_time' => Arr::get($venue, 'offer_start_time'),
                'end_time' => Arr::get($venue, 'offer_end_time'),
                'minimum_order' => Arr::get($venue, 'minimum_order'),
                'fulfilment_type' => Arr::get($venue, 'fulfilment_type'),
                'ride_trip_type' => Arr::get($venue, 'ride_trip_type'),
                'low_balance_threshold' => Arr::get($wallet, 'low_balance_threshold'),
                'auto_top_up_enabled' => Arr::get($wallet, 'auto_top_up_enabled') ? 'yes' : 'no',
                'auto_top_up_amount' => Arr::get($wallet, 'auto_top_up_amount'),
                'requested_at' => optional($request->created_at)?->toDateTimeString(),
                'sync_due_at' => optional($request->sync_due_at)?->toDateTimeString(),
            ];
        })->values();

        $headers = [
            'export_code', 'merchant_name', 'venue_name', 'business_type', 'offer_enabled', 'offer_type', 'voucher_amount',
            'offer_days', 'start_time', 'end_time', 'minimum_order', 'fulfilment_type', 'ride_trip_type',
            'low_balance_threshold', 'auto_top_up_enabled', 'auto_top_up_amount', 'requested_at', 'sync_due_at',
        ];

        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($header) use ($row) {
                $value = (string) ($row[$header] ?? '');
                $escaped = str_replace('"', '""', $value);
                return '"' . $escaped . '"';
            }, $headers));
        }

        return [
            'summary' => [
                'pending_requests' => $rows->count(),
                'generated_at' => now()->toIso8601String(),
            ],
            'rows' => $rows->all(),
            'csv' => implode("\n", $lines),
        ];
    }

    public function markSynced(OfferSyncRequest $request, ?string $adminNotes = null): OfferSyncRequest
    {
        return DB::transaction(function () use ($request, $adminNotes) {
            $venue = $request->venue()->firstOrFail();
            $venue->update([
                'offer_review_status' => 'live',
            ]);

            $request->update([
                'status' => 'synced',
                'synced_at' => now(),
                'admin_notes' => $adminNotes ?: $request->admin_notes,
            ]);

            return $request->fresh(['merchant', 'venue', 'requestedBy']);
        });
    }

    public function rejectAndRevert(OfferSyncRequest $request, ?string $adminNotes = null): OfferSyncRequest
    {
        return DB::transaction(function () use ($request, $adminNotes) {
            $merchant = $request->merchant()->with('wallet')->firstOrFail();
            $venue = $request->venue()->firstOrFail();
            $previous = $request->previous_snapshot ?? [];

            if (! empty($previous['merchant'] ?? [])) {
                $merchant->update(array_filter([
                    'business_type' => Arr::get($previous, 'merchant.business_type'),
                ], fn ($value) => $value !== null));
            }

            if ($merchant->wallet && ! empty($previous['wallet'] ?? [])) {
                $merchant->wallet->update([
                    'low_balance_threshold' => Arr::get($previous, 'wallet.low_balance_threshold', $merchant->wallet->low_balance_threshold),
                    'auto_top_up_enabled' => (bool) Arr::get($previous, 'wallet.auto_top_up_enabled', $merchant->wallet->auto_top_up_enabled),
                    'auto_top_up_amount' => Arr::get($previous, 'wallet.auto_top_up_amount', $merchant->wallet->auto_top_up_amount),
                ]);
            }

            if (! empty($previous['venue'] ?? [])) {
                $venue->update([
                    'category' => Arr::get($previous, 'venue.category', $venue->category),
                    'offer_enabled' => (bool) Arr::get($previous, 'venue.offer_enabled', $venue->offer_enabled),
                    'offer_value' => Arr::get($previous, 'venue.offer_value', $venue->offer_value),
                    'offer_days' => Arr::wrap(Arr::get($previous, 'venue.offer_days', $venue->offer_days)),
                    'offer_start_time' => Arr::get($previous, 'venue.offer_start_time', $venue->offer_start_time),
                    'offer_end_time' => Arr::get($previous, 'venue.offer_end_time', $venue->offer_end_time),
                    'minimum_order' => Arr::get($previous, 'venue.minimum_order', $venue->minimum_order),
                    'fulfilment_type' => Arr::get($previous, 'venue.fulfilment_type', $venue->fulfilment_type),
                    'offer_type' => Arr::get($previous, 'venue.offer_type', $venue->offer_type),
                    'ride_trip_type' => Arr::get($previous, 'venue.ride_trip_type', $venue->ride_trip_type),
                    'offer_review_status' => 'changes_rejected',
                ]);
            }

            $request->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'admin_notes' => $adminNotes ?: $request->admin_notes,
            ]);

            return $request->fresh(['merchant', 'venue', 'requestedBy']);
        });
    }

    public function requestPayload(OfferSyncRequest $request): array
    {
        $snapshot = $request->requested_snapshot ?? [];

        return [
            'id' => $request->id,
            'merchant_id' => $request->merchant_id,
            'venue_id' => $request->venue_id,
            'status' => $request->status,
            'export_code' => $request->export_code,
            'changed_fields' => array_values($request->changed_fields ?? []),
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
            'offer_preview' => [
                'business_type' => Arr::get($snapshot, 'merchant.business_type'),
                'offer_enabled' => (bool) Arr::get($snapshot, 'venue.offer_enabled'),
                'offer_type' => Arr::get($snapshot, 'venue.offer_type'),
                'voucher_amount' => $this->formatDecimal(Arr::get($snapshot, 'venue.offer_value')),
                'offer_days' => Arr::wrap(Arr::get($snapshot, 'venue.offer_days')),
                'start_time' => $this->timePreview(Arr::get($snapshot, 'venue.offer_start_time')),
                'end_time' => $this->timePreview(Arr::get($snapshot, 'venue.offer_end_time')),
                'minimum_order' => $this->formatNullableDecimal(Arr::get($snapshot, 'venue.minimum_order')),
                'fulfilment_type' => Arr::get($snapshot, 'venue.fulfilment_type'),
                'ride_trip_type' => Arr::get($snapshot, 'venue.ride_trip_type'),
                'low_balance_threshold' => $this->formatDecimal(Arr::get($snapshot, 'wallet.low_balance_threshold')),
                'auto_top_up_enabled' => (bool) Arr::get($snapshot, 'wallet.auto_top_up_enabled'),
                'auto_top_up_amount' => $this->formatDecimal(Arr::get($snapshot, 'wallet.auto_top_up_amount')),
            ],
            'sync_due_at' => optional($request->sync_due_at)?->toIso8601String(),
            'synced_at' => optional($request->synced_at)?->toIso8601String(),
            'rejected_at' => optional($request->rejected_at)?->toIso8601String(),
            'admin_notes' => $request->admin_notes,
            'created_at' => optional($request->created_at)?->toIso8601String(),
            'is_overdue' => $request->status === 'pending' && $request->sync_due_at && $request->sync_due_at->isPast(),
        ];
    }

    private function timePreview(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $string = (string) $value;
        return strlen($string) >= 5 ? substr($string, 0, 5) : $string;
    }

    private function formatDecimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function formatNullableDecimal(mixed $value): ?string
    {
        return $value === null ? null : $this->formatDecimal($value);
    }
}
