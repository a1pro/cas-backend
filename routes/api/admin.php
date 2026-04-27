<?php

use App\Http\Controllers\Api\Admin\AdminAreaLaunchAlertController;
use App\Http\Controllers\Api\Admin\AdminCouponController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminFraudController;
use App\Http\Controllers\Api\Admin\AdminIntegrationOperationsController;
use App\Http\Controllers\Api\Admin\AdminInformationController;
use App\Http\Controllers\Api\Admin\AdminOfferSyncController;
use App\Http\Controllers\Api\Admin\AdminProviderVoucherLinkController;
use App\Http\Controllers\Api\Admin\AdminVenueAddressChangeController;
use App\Http\Controllers\Api\Admin\AdminWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'dashboard']);
    Route::get('/integrations/playbook', [AdminIntegrationOperationsController::class, 'show']);
    Route::get('/merchants', [AdminDashboardController::class, 'merchants']);
    Route::put('/merchants/{merchant}', [AdminDashboardController::class, 'updateMerchant']);
    Route::delete('/merchants/{merchant}', [AdminDashboardController::class, 'deleteMerchant']);
    Route::get('/venues', [AdminDashboardController::class, 'venues']);
    Route::put('/venues/{venue}', [AdminDashboardController::class, 'updateVenue']);
    Route::delete('/venues/{venue}', [AdminDashboardController::class, 'deleteVenue']);
    Route::post('/venues/{venue}/approve', [AdminDashboardController::class, 'approveVenue']);
    Route::post('/venues/{venue}/reject', [AdminDashboardController::class, 'rejectVenue']);
    Route::get('/merchant-applications', [AdminDashboardController::class, 'pendingMerchants']);

    Route::get('/information/export', [AdminInformationController::class, 'export']);
    Route::get('/information', [AdminInformationController::class, 'index']);
    Route::post('/information', [AdminInformationController::class, 'store']);
    Route::put('/information/{venue}', [AdminInformationController::class, 'update']);
    Route::delete('/information/{venue}', [AdminInformationController::class, 'destroy']);
    Route::post('/information/{venue}/approve', [AdminInformationController::class, 'approve']);
    Route::post('/information/{venue}/publish', [AdminInformationController::class, 'publish']);
    Route::post('/information/{venue}/reject', [AdminInformationController::class, 'reject']);
    Route::post('/merchant-applications/{merchant}/approve', [AdminDashboardController::class, 'approveMerchant']);
    Route::post('/merchant-applications/{merchant}/reject', [AdminDashboardController::class, 'rejectMerchant']);

    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::post('/coupons', [AdminCouponController::class, 'store']);
    Route::post('/coupons/upload', [AdminCouponController::class, 'upload']);

    Route::get('/provider-voucher-links', [AdminProviderVoucherLinkController::class, 'index']);
    Route::get('/provider-voucher-links/template', [AdminProviderVoucherLinkController::class, 'template']);
    Route::get('/provider-voucher-links/export', [AdminProviderVoucherLinkController::class, 'export']);
    Route::get('/provider-voucher-links/venue-export', [AdminProviderVoucherLinkController::class, 'venueExport']);
    Route::post('/provider-voucher-links', [AdminProviderVoucherLinkController::class, 'store']);
    Route::put('/provider-voucher-links/{providerVoucherLink}', [AdminProviderVoucherLinkController::class, 'update']);
    Route::delete('/provider-voucher-links/{providerVoucherLink}', [AdminProviderVoucherLinkController::class, 'destroy']);
    Route::post('/provider-voucher-links/upload', [AdminProviderVoucherLinkController::class, 'upload']);

    Route::get('/address-change-requests', [AdminVenueAddressChangeController::class, 'index']);
    Route::post('/address-change-requests/{venueAddressChangeRequest}/approve', [AdminVenueAddressChangeController::class, 'approve']);
    Route::post('/address-change-requests/{venueAddressChangeRequest}/reject', [AdminVenueAddressChangeController::class, 'reject']);

    Route::get('/fraud/users', [AdminFraudController::class, 'index']);
    Route::post('/fraud/users/{user}/review', [AdminFraudController::class, 'review']);
    Route::post('/fraud/users/{user}/block', [AdminFraudController::class, 'block']);
    Route::post('/fraud/users/{user}/unblock', [AdminFraudController::class, 'unblock']);

    Route::get('/offer-sync/requests', [AdminOfferSyncController::class, 'index']);
    Route::get('/offer-sync/export', [AdminOfferSyncController::class, 'export']);
    Route::post('/offer-sync/requests/{offerSyncRequest}/mark-synced', [AdminOfferSyncController::class, 'markSynced']);
    Route::post('/offer-sync/requests/{offerSyncRequest}/reject', [AdminOfferSyncController::class, 'reject']);

    Route::get('/area-launch-alerts', [AdminAreaLaunchAlertController::class, 'index']);
    Route::get('/area-launch-alerts/{merchant}/preview', [AdminAreaLaunchAlertController::class, 'preview']);
    Route::post('/area-launch-alerts/{merchant}/send', [AdminAreaLaunchAlertController::class, 'send']);

    Route::get('/whatsapp/templates', [AdminWhatsAppController::class, 'index']);
    Route::post('/whatsapp/templates/starter-pack', [AdminWhatsAppController::class, 'starterPack']);
    Route::post('/whatsapp/templates', [AdminWhatsAppController::class, 'store']);
    Route::put('/whatsapp/templates/{template}', [AdminWhatsAppController::class, 'update']);
    Route::post('/whatsapp/templates/{template}/submit', [AdminWhatsAppController::class, 'submit']);
    Route::post('/whatsapp/templates/{template}/simulate-approval', [AdminWhatsAppController::class, 'simulateApproval']);
});
