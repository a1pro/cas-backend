<?php

use App\Http\Controllers\Api\Merchant\MerchantDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('merchant')->middleware(['auth:sanctum', 'role:merchant'])->group(function () {
    Route::get('/dashboard', [MerchantDashboardController::class, 'dashboard']);
    Route::post('/wallet/top-up', [MerchantDashboardController::class, 'topUp']);
    Route::post('/vouchers/{voucher}/redeem', [MerchantDashboardController::class, 'redeemVoucher']);
});
