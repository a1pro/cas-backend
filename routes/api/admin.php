<?php

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'dashboard']);
    Route::get('/merchants', [AdminDashboardController::class, 'merchants']);
});
