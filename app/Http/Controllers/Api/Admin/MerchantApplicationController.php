<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Services\Notifications\MerchantNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantApplicationController extends BaseController
{
    public function __construct(private readonly MerchantNotificationService $merchantNotificationService)
    {
    }

    public function index()
    {
        try {
            $data = Merchant::with(['user', 'wallet', 'venues'])->latest()->get();

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

    public function approve(Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            DB::transaction(function () use ($merchant) {
                $merchant->update([
                    'status' => 'active',
                    'approved_at' => now(),
                    'rejected_at' => null,
                ]);
                $merchant->user()->update(['is_active' => true]);
                $merchant->venues()->update(['is_active' => true]);
            });
            $this->merchantNotificationService->sendApprovalMail($merchant->fresh('user'));

            $data = $merchant->fresh(['user', 'wallet', 'venues']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant approved successfully',
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

    public function reject(Merchant $merchant, Request $request)
    {
        try {
            DB::beginTransaction();

            DB::transaction(function () use ($merchant, $request) {
                $merchant->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'approval_notes' => $request->string('notes')->toString() ?: 'Rejected by admin',
                ]);
                $merchant->user()->update(['is_active' => false]);
                $merchant->venues()->update(['is_active' => false]);
            });
            $data = $merchant->fresh(['user', 'wallet', 'venues']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant rejected',
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
