<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\SendAreaLaunchAlertRequest;
use App\Models\Merchant;
use App\Services\Notifications\AreaLaunchAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAreaLaunchAlertController extends BaseController
{
    public function __construct(private readonly AreaLaunchAlertService $areaLaunchAlertService)
    {
    }

    public function index(Request $request)
    {
        try {
            $limit = (int) $request->integer('limit', 8);

            $data = $this->areaLaunchAlertService->dashboardPayload($limit);

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

    public function preview(Merchant $merchant)
    {
        try {
            $data = [
                    'preview' => $this->areaLaunchAlertService->previewForMerchant($merchant),
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

    public function send(SendAreaLaunchAlertRequest $request, Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $data = $this->areaLaunchAlertService->triggerForMerchant(
                    $merchant->fresh(['venues', 'wallet']),
                    $request->user(),
                    'manual_admin',
                    $validated['notes'] ?? null,
                );

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Area launch alert processed successfully.',
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
