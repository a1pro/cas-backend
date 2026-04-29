<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\OfferSyncRequest;
use App\Services\Merchant\MerchantOfferSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOfferSyncController extends BaseController
{
    public function __construct(
        private readonly MerchantOfferSyncService $offerSyncService,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'status' => ['nullable', 'in:all,pending,synced,rejected,superseded'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $data = [
                    'items' => $this->offerSyncService->listPayloads(
                        $validated['status'] ?? 'pending',
                        (int) ($validated['limit'] ?? 25)
                    ),
                ];

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

    public function export(Request $request)
    {
        try {
            $data = $this->offerSyncService->exportPendingPayload();

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

    public function markSynced(Request $request, OfferSyncRequest $offerSyncRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'admin_notes' => ['nullable', 'string', 'max:500'],
            ]);

            if ($offerSyncRequest->status !== 'pending') {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Only pending sync requests can be marked as synced.',
                ], 422);
            }

            $record = $this->offerSyncService->markSynced($offerSyncRequest, $validated['admin_notes'] ?? null);

            $data = [
                    'request' => $this->offerSyncService->requestPayload($record),
                    'dashboard' => $this->offerSyncService->adminDashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Offer sync request marked as synced.',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, OfferSyncRequest $offerSyncRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'admin_notes' => ['nullable', 'string', 'max:500'],
            ]);

            if ($offerSyncRequest->status !== 'pending') {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Only pending sync requests can be rejected.',
                ], 422);
            }

            $record = $this->offerSyncService->rejectAndRevert($offerSyncRequest, $validated['admin_notes'] ?? null);

            $data = [
                    'request' => $this->offerSyncService->requestPayload($record),
                    'dashboard' => $this->offerSyncService->adminDashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Offer sync request rejected and previous settings restored.',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }
}
