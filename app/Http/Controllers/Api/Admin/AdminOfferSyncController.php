<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\OfferSyncRequest;
use App\Services\Merchant\MerchantOfferSyncService;
use Illuminate\Http\Request;

class AdminOfferSyncController extends BaseController
{
    public function __construct(
        private readonly MerchantOfferSyncService $offerSyncService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,synced,rejected,superseded'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success([
            'items' => $this->offerSyncService->listPayloads(
                $validated['status'] ?? 'pending',
                (int) ($validated['limit'] ?? 25)
            ),
        ]);
    }

    public function export(Request $request)
    {
        return $this->success($this->offerSyncService->exportPendingPayload());
    }

    public function markSynced(Request $request, OfferSyncRequest $offerSyncRequest)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($offerSyncRequest->status !== 'pending') {
            return $this->error('Only pending sync requests can be marked as synced.', 422);
        }

        $record = $this->offerSyncService->markSynced($offerSyncRequest, $validated['admin_notes'] ?? null);

        return $this->success([
            'request' => $this->offerSyncService->requestPayload($record),
            'dashboard' => $this->offerSyncService->adminDashboardPayload(),
        ], 'Offer sync request marked as synced.');
    }

    public function reject(Request $request, OfferSyncRequest $offerSyncRequest)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($offerSyncRequest->status !== 'pending') {
            return $this->error('Only pending sync requests can be rejected.', 422);
        }

        $record = $this->offerSyncService->rejectAndRevert($offerSyncRequest, $validated['admin_notes'] ?? null);

        return $this->success([
            'request' => $this->offerSyncService->requestPayload($record),
            'dashboard' => $this->offerSyncService->adminDashboardPayload(),
        ], 'Offer sync request rejected and previous settings restored.');
    }
}
