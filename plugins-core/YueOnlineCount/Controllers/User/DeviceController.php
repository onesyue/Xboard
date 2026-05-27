<?php

namespace Plugin\YueOnlineCount\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\DeviceStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Device Manager API for user front-end (2026-05-22 P1-E).
 *
 * GET  /api/v1/user/devices            — 当前用户 alive IP 列表 + last_seen
 * POST /api/v1/user/devices/reset-all  — 一键踢下线所有设备 (重置 UUID)
 */
class DeviceController extends Controller
{
    public function list(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail([401, 'unauthorized']);
        }

        $deviceService = app(DeviceStateService::class);
        $byUser = $deviceService->getUsersDevices([(int) $user->id]);
        $ips = isset($byUser[$user->id]) ? array_values($byUser[$user->id]) : [];

        $effLimit = (int) ($user->device_limit ?: 0);
        if ($effLimit <= 0 && $user->plan_id) {
            $plan = Plan::find($user->plan_id);
            if ($plan) {
                $effLimit = (int) $plan->device_limit;
            }
        }

        return $this->success([
            'ips'           => $ips,
            'count'         => count($ips),
            'limit'         => $effLimit,
            'over_limit'    => $effLimit > 0 && count($ips) > $effLimit,
            'last_online_at'=> $user->last_online_at?->timestamp,
        ]);
    }

    /**
     * Reset UUID → triggers UserObserver → NodeUserSyncJob (uuidChanged=true) →
     * NodeSyncService::notifyUserChanged → patch-reset-disconnect chain →
     * 节点端 sync.user.delta remove + add (P0-B rollout 后真断老连接)。
     *
     * 限速 1 次/分钟 防误点。
     */
    public function resetAll(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail([401, 'unauthorized']);
        }

        $rateKey = "device:reset_all_rate:{$user->id}";
        if (Cache::has($rateKey)) {
            return $this->fail([429, '操作太频繁，请 1 分钟后再试']);
        }
        Cache::put($rateKey, 1, 60);

        $newUuid = (string) Str::uuid();
        $user->uuid = $newUuid;
        if (!$user->save()) {
            return $this->fail([500, 'save failed']);
        }

        // 立即清 Redis device state (不等节点 push)
        try {
            Redis::del('user_devices:' . $user->id);
        } catch (\Throwable $e) {
            // non-fatal
        }

        Log::info("[YueOnlineCount] user {$user->id} reset-all-devices uuid={$newUuid}");

        return $this->success([
            'reset'    => true,
            'new_uuid' => $newUuid,
            'hint'     => '所有设备已断开。请在每台设备的「YueLink」点订阅旁边的刷新按钮，更新到新订阅。',
        ]);
    }
}
