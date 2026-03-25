<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('cors')->group(function () {
    require __DIR__ . '/api/auth.php';
    require __DIR__ . '/api/user.php';
    require __DIR__ . '/api/merchant.php';
    require __DIR__ . '/api/admin.php';
});
