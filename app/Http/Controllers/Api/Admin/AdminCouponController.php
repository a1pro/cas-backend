<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\CreateCouponRequest;
use App\Http\Requests\Admin\UploadCouponsRequest;
use App\Models\Coupon;
use App\Services\Admin\CouponManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCouponController extends BaseController
{
    public function __construct(private readonly CouponManagementService $couponManagementService)
    {
    }

    public function index(Request $request)
    {
        try {
            $coupons = Coupon::with(['merchant:id,business_name', 'venue:id,name', 'creator:id,name'])
                ->latest()
                ->get();

            $data = [
                    'summary' => [
                        'total' => $coupons->count(),
                        'live' => $coupons->where('status', 'live')->count(),
                        'csv_uploaded' => $coupons->where('source', 'csv_upload')->count(),
                    ],
                    'items' => $coupons,
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

    public function store(CreateCouponRequest $request)
    {
        try {
            DB::beginTransaction();

            $coupon = $this->couponManagementService->create($request->validated(), $request->user());

            $data = $coupon->load(['merchant:id,business_name', 'venue:id,name', 'creator:id,name']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Coupon created successfully.',
                'data' => $data,
            ], 201);
        
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

    public function upload(UploadCouponsRequest $request)
    {
        try {
            DB::beginTransaction();

            $coupons = $this->couponManagementService->importFromCsv($request->file('file'), $request->user(), $request->validated());

            $couponIds = collect($coupons)->pluck('id')->all();

            $loadedCoupons = Coupon::with(['merchant:id,business_name', 'venue:id,name', 'creator:id,name'])
                ->whereIn('id', $couponIds)
                ->latest('id')
                ->get();

            $data = [
                    'message' => 'Coupons uploaded successfully.',
                    'count' => $loadedCoupons->count(),
                    'summary' => [
                        'total' => Coupon::count(),
                        'live' => Coupon::where('status', 'live')->count(),
                        'csv_uploaded' => Coupon::where('source', 'csv_upload')->count(),
                    ],
                    'items' => $loadedCoupons,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Coupons uploaded successfully.',
                'data' => $data,
            ], 201);
        
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
