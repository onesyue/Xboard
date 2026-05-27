<?php

use Illuminate\Support\Facades\Route;
use Plugin\InviteAlias\Controllers\User\AliasController as UserAliasController;
use Plugin\InviteAlias\Controllers\Admin\AliasController as AdminAliasController;

// ─── 用户端 ───
Route::middleware(['user'])->prefix('api/v1/user/invite-alias')->group(function () {
    // 政策 / 当前价格 / 用户当前 alias 列表
    Route::get('/policy',   [UserAliasController::class, 'policy']);
    Route::get('/mine',     [UserAliasController::class, 'mine']);

    // L1-L5 实时校验（不扣分，仅查询是否可兑换）
    Route::post('/precheck', [UserAliasController::class, 'precheck']);

    // 两阶段提交：
    //   redeem-pending → 创建 pending alias，返回 alias_id（不扣分，由 TG bot 端扣后调 confirm）
    //   confirm        → 确认激活
    //   release-pending → 主动释放未确认（兜底，让 TG bot 在扣分失败时调用）
    Route::post('/redeem-pending',  [UserAliasController::class, 'redeemPending']);
    Route::post('/confirm',         [UserAliasController::class, 'confirm']);
    Route::post('/release-pending', [UserAliasController::class, 'releasePending']);

    // 申诉（被 ban 后 48h 内可调一次）
    Route::post('/appeal', [UserAliasController::class, 'appeal']);
});

// 内部接口：供 TG bot / yueops 调用，IP 白名单 + X-Internal-Token + _account_id
use Plugin\InviteAlias\Controllers\Internal\AliasController as InternalAliasController;
Route::prefix('api/internal/invite-alias')->group(function () {
    Route::post('/policy',          [InternalAliasController::class, 'policy']);
    Route::post('/mine',            [InternalAliasController::class, 'mine']);
    Route::post('/precheck',        [InternalAliasController::class, 'precheck']);
    Route::post('/redeem-pending',  [InternalAliasController::class, 'redeemPending']);
    Route::post('/confirm',         [InternalAliasController::class, 'confirm']);
    Route::post('/release-pending', [InternalAliasController::class, 'releasePending']);
    Route::post('/resolve',         [InternalAliasController::class, 'resolve']);
});

// ─── 管理端 ───
Route::middleware(['admin', 'log'])->prefix('api/v2/admin/invite-alias')->group(function () {
    Route::get('/list',     [AdminAliasController::class, 'list']);
    Route::get('/stats',    [AdminAliasController::class, 'stats']);
    Route::get('/{id}',     [AdminAliasController::class, 'detail']);
    Route::post('/{id}/ban',     [AdminAliasController::class, 'ban']);
    Route::post('/{id}/unban',   [AdminAliasController::class, 'unban']);
    Route::post('/{id}/dormant', [AdminAliasController::class, 'forceDormant']);
    Route::post('/{id}/refund',  [AdminAliasController::class, 'refund']);
});
