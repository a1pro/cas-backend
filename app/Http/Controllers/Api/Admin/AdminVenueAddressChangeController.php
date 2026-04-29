<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\VenueAddressChangeRequest;
use App\Services\Merchant\VenueAddressApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVenueAddressChangeController extends BaseController
{
    public function __construct(
        private readonly VenueAddressApprovalService $addressApprovalService,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'status' => ['nullable', 'in:all,pending,approved,rejected,superseded'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $data = [
                    'summary' => $this->addressApprovalService->adminDashboardPayload()['summary'],
                    'items' => $this->addressApprovalService->listPayloads(
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

    public function approve(Request $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'admin_notes' => ['nullable', 'string', 'max:500'],
            ]);

            if ($venueAddressChangeRequest->status !== 'pending') {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Only pending address change requests can be approved.',
                ], 422);
            }

            $record = $this->addressApprovalService->approve($venueAddressChangeRequest, $validated['admin_notes'] ?? null);

            $data = [
                    'request' => $this->addressApprovalService->payload($record),
                    'dashboard' => $this->addressApprovalService->adminDashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue address change approved successfully.',
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

    public function reject(Request $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'admin_notes' => ['nullable', 'string', 'max:500'],
            ]);

            if ($venueAddressChangeRequest->status !== 'pending') {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Only pending address change requests can be rejected.',
                ], 422);
            }

            $record = $this->addressApprovalService->reject($venueAddressChangeRequest, $validated['admin_notes'] ?? null);

            $data = [
                    'request' => $this->addressApprovalService->payload($record),
                    'dashboard' => $this->addressApprovalService->adminDashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue address change rejected successfully.',
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
