<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\ApproveAddressChangeRequest;
use App\Http\Requests\Admin\ListAddressChangeRequest;
use App\Http\Requests\Admin\RejectAddressChangeRequest;
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

    public function index(ListAddressChangeRequest $request)
    {
        try {
            $validated = $request->validated();

            $summary = $this->addressApprovalService->adminDashboardPayload()['summary'];
            $items = $this->addressApprovalService->listPayloads(
                $validated['status'] ?? 'pending',
                (int) ($validated['limit'] ?? 25)
            );

            $data = [
                    'summary' => $summary,
                    'items' => $items,
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

    public function approve(ApproveAddressChangeRequest $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

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
            $requestPayload = $this->addressApprovalService->payload($record);
            $dashboard = $this->addressApprovalService->adminDashboardPayload();

            $data = [
                    'request' => $requestPayload,
                    'dashboard' => $dashboard,
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

    public function reject(RejectAddressChangeRequest $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

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
            $requestPayload = $this->addressApprovalService->payload($record);
            $dashboard = $this->addressApprovalService->adminDashboardPayload();

            $data = [
                    'request' => $requestPayload,
                    'dashboard' => $dashboard,
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
