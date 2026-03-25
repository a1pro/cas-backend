<?php

use App\Http\Controllers\Api\User\UserDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->middleware(['auth:sanctum', 'role:user'])->group(function () {
    Route::get('/dashboard', [UserDashboardController::class, 'dashboard']);
    Route::get('/venues', [UserDashboardController::class, 'venues']);
    Route::post('/vouchers', [UserDashboardController::class, 'createVoucher']);
});
