<?php

use Plugin\Emby\Controllers\EmbyController;
use Illuminate\Support\Facades\Route;

// 挂载在 /api/v1/user/emby，使用 user 中间件（Sanctum Bearer token）
Route::middleware(['user'])->group(function () {
    Route::get('/api/v1/user/emby', [EmbyController::class, 'getEmby']);
});
