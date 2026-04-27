<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\CreateCouponRequest;
use App\Http\Requests\Admin\UploadCouponsRequest;
use App\Models\Coupon;
use App\Services\Admin\CouponManagementService;
use Illuminate\Http\Request;

class AdminCouponController extends BaseController
{
    public function __construct(private readonly CouponManagementService $couponManagementService)
    {
    }

    public function index(Request $request)
    {
        $coupons = Coupon::with(['merchant:id,business_name', 'venue:id,name', 'creator:id,name'])
            ->latest()
            ->get();

        return $this->success([
            'summary' => [
                'total' => $coupons->count(),
                'live' => $coupons->where('status', 'live')->count(),
                'csv_uploaded' => $coupons->where('source', 'csv_upload')->count(),
            ],
            'items' => $coupons,
        ]);
    }

    public function store(CreateCouponRequest $request)
    {
        $coupon = $this->couponManagementService->create($request->validated(), $request->user());

        return $this->success(
            $coupon->load(['merchant:id,business_name', 'venue:id,name', 'creator:id,name']),
            'Coupon created successfully.',
            201
        );
    }

    public function upload(UploadCouponsRequest $request)
    {
        $coupons = $this->couponManagementService->importFromCsv($request->file('file'), $request->user(), $request->validated());

        $couponIds = collect($coupons)->pluck('id')->all();

        $loadedCoupons = Coupon::with(['merchant:id,business_name', 'venue:id,name', 'creator:id,name'])
            ->whereIn('id', $couponIds)
            ->latest('id')
            ->get();

        return $this->success([
            'message' => 'Coupons uploaded successfully.',
            'count' => $loadedCoupons->count(),
            'summary' => [
                'total' => Coupon::count(),
                'live' => Coupon::where('status', 'live')->count(),
                'csv_uploaded' => Coupon::where('source', 'csv_upload')->count(),
            ],
            'items' => $loadedCoupons,
        ], 'Coupons uploaded successfully.', 201);
    }
}
