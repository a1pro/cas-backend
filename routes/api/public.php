<?php

use App\Http\Controllers\Api\PublicFlow\AffiliateInviteController;
use App\Http\Controllers\Api\PublicFlow\BnplCheckoutController;
use App\Http\Controllers\Api\PublicFlow\LeadGeneratorController;
use App\Http\Controllers\Api\PublicFlow\ProviderSettlementWebhookController;
use App\Http\Controllers\Api\PublicFlow\StripeWebhookController;
use App\Http\Controllers\Api\PublicFlow\TagButtonController;
use App\Http\Controllers\Api\PublicFlow\VenueDiscoveryController;
use App\Http\Controllers\Api\PublicFlow\WhatsAppConnectController;
use Illuminate\Support\Facades\Route;

Route::prefix('discovery')->group(function () {
    Route::get('/venues', [VenueDiscoveryController::class, 'index']);
});

Route::prefix('whatsapp')->group(function () {
    Route::get('/connect-link', [WhatsAppConnectController::class, 'show']);
});

Route::prefix('affiliate')->group(function () {
    Route::get('/{shareCode}', [AffiliateInviteController::class, 'show']);
});

Route::prefix('tags')->group(function () {
    Route::post('/', [TagButtonController::class, 'create']);
    Route::get('/{shareCode}', [TagButtonController::class, 'show']);
});

Route::prefix('lead-generator')->group(function () {
    Route::post('/', [LeadGeneratorController::class, 'store']);
});

Route::prefix('bnpl')->group(function () {
    Route::get('/options', [BnplCheckoutController::class, 'options']);
    Route::post('/orders', [BnplCheckoutController::class, 'store']);
    Route::get('/orders/{checkoutCode}', [BnplCheckoutController::class, 'show']);
    Route::post('/orders/{checkoutCode}/simulate-payment', [BnplCheckoutController::class, 'simulatePayment']);
});

Route::prefix('stripe')->group(function () {
    Route::post('/webhook', [StripeWebhookController::class, 'handle']);
});

Route::prefix('provider')->group(function () {
    Route::post('/webhook', [ProviderSettlementWebhookController::class, 'handle']);
});
