<?php

use App\Http\Controllers\Api\WhatsApp\ChatSessionController;
use App\Http\Controllers\Api\WhatsApp\ProviderWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->group(function () {
    Route::post('/sessions', [ChatSessionController::class, 'start']);
    Route::get('/sessions/{session}', [ChatSessionController::class, 'show']);
    Route::post('/sessions/{session}/messages', [ChatSessionController::class, 'message']);
    Route::post('/sessions/{session}/location', [ChatSessionController::class, 'location']);
    Route::post('/sessions/{session}/select-venue', [ChatSessionController::class, 'selectVenue']);
    Route::get('/360dialog/webhook', [ProviderWebhookController::class, 'verify']);
    Route::post('/360dialog/webhook', [ProviderWebhookController::class, 'handle']);
});
