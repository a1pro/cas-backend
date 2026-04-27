<?php

use App\Http\Controllers\Api\Merchant\MerchantDashboardController;
use App\Http\Controllers\Api\Merchant\MerchantInformationController;
use Illuminate\Support\Facades\Route;

Route::prefix('merchant')->middleware(['auth:sanctum', 'role:merchant'])->group(function () {
    Route::get('/dashboard', [MerchantDashboardController::class, 'dashboard']);
    Route::get('/offer-settings', [MerchantDashboardController::class, 'offerSettings']);
    Route::put('/offer-settings', [MerchantDashboardController::class, 'updateOfferSettings']);
    Route::get('/venue-profile', [MerchantDashboardController::class, 'venueProfile']);
    Route::put('/venue-profile', [MerchantDashboardController::class, 'updateVenueProfile']);
    Route::get('/venues', [MerchantDashboardController::class, 'venues']);
    Route::post('/venues', [MerchantDashboardController::class, 'createVenue']);
    Route::put('/venues/{venue}', [MerchantDashboardController::class, 'updateVenue']);
    Route::delete('/venues/{venue}', [MerchantDashboardController::class, 'deleteVenue']);
    Route::get('/information', [MerchantInformationController::class, 'index']);
    Route::post('/information', [MerchantInformationController::class, 'store']);
    Route::put('/information/{venue}', [MerchantInformationController::class, 'update']);
    Route::delete('/information/{venue}', [MerchantInformationController::class, 'destroy']);
    Route::get('/wallet/transactions', [MerchantDashboardController::class, 'walletTransactions']);
    Route::get('/vouchers', [MerchantDashboardController::class, 'vouchers']);
    Route::post('/vouchers', [MerchantDashboardController::class, 'createVoucher']);
    Route::post('/wallet/top-up', [MerchantDashboardController::class, 'topUp']);
    Route::post('/wallet/alerts/test', [MerchantDashboardController::class, 'sendTestLowBalanceAlert']);
    Route::post('/wallet/stripe-checkout', [MerchantDashboardController::class, 'createStripeTopUpCheckout']);
    Route::post('/wallet/stripe-checkout/{checkoutCode}/simulate-success', [MerchantDashboardController::class, 'simulateStripeTopUpSuccess']);
    Route::post('/vouchers/{voucher}/provider-event', [MerchantDashboardController::class, 'simulateProviderEvent']);
    Route::post('/vouchers/{voucher}/redeem', [MerchantDashboardController::class, 'redeemVoucher']);
});
