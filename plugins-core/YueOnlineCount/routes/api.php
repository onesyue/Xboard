<?php

use Illuminate\Support\Facades\Route;
use Plugin\YueOnlineCount\Controllers\User\DeviceController;

Route::middleware(['user'])->prefix('api/v1/user/devices')->group(function () {
    Route::get('/',          [DeviceController::class, 'list']);
    Route::post('/reset-all', [DeviceController::class, 'resetAll']);
});
