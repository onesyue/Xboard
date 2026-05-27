<?php

use Illuminate\Support\Facades\Route;
use Plugin\CommissionTier\Controllers\User\TierController as UserTierController;
use Plugin\CommissionTier\Controllers\Admin\TierController as AdminTierController;

// 用户端
Route::middleware(['user'])->group(function () {
    Route::get('/api/v1/user/commission/tier', [UserTierController::class, 'info']);
});

// 管理端
Route::middleware(['admin', 'log'])->prefix('api/v2/admin/commission-tier')->group(function () {
    Route::get('/stats', [AdminTierController::class, 'stats']);
    Route::post('/recompute', [AdminTierController::class, 'recomputeNow']);
    Route::get('/user/{id}', [AdminTierController::class, 'userDetail']);
});
