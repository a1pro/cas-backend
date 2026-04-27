<?php

use App\Http\Controllers\Api\User\UserDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->middleware(['auth:sanctum', 'role:user'])->group(function () {
    Route::get('/dashboard', [UserDashboardController::class, 'dashboard']);
    Route::patch('/provider-profile', [UserDashboardController::class, 'updateProviderProfile']);
    Route::patch('/affiliate/payout-profile', [UserDashboardController::class, 'updatePayoutProfile']);
    Route::post('/affiliate/payout-profile/onboarding', [UserDashboardController::class, 'startPayoutOnboarding']);
    Route::post('/affiliate/payout-profile/simulate-approval', [UserDashboardController::class, 'simulatePayoutApproval']);
    Route::post('/affiliate/payouts', [UserDashboardController::class, 'createPayoutRun']);
    Route::post('/affiliate/payouts/{payoutCode}/simulate-paid', [UserDashboardController::class, 'simulatePayoutRun']);
    Route::post('/affiliate/test-alert', [UserDashboardController::class, 'sendAffiliateTestAlert']);
    Route::get('/venues', [UserDashboardController::class, 'venues']);
    Route::post('/vouchers', [UserDashboardController::class, 'createVoucher']);
});
