<?php

namespace Plugin\YueOnlineCount;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Carbon;

/**
 * YueOnlineCount — 在线设备统计增强 + 设备管理
 *
 * 历史 (v1.0):
 *   inject online_count + last_online_at into user.subscribe.response
 *   STALE_AFTER_SECONDS = 600 (与 yuebot 同口径)
 *
 * v2.0 (2026-05-22 P1-E):
 *   - STALE_AFTER_SECONDS 600 → 180 (与 P0-C 节点端 idle TTL 一致)
 *   - 新增 API: GET /api/v1/user/devices + POST /api/v1/user/devices/reset-all
 *   - 新增前端 widget (yue-online-count-widget.js) 注入用户中心
 *   - 通过 harden-xboard-portal-theme.sh 拼到 ux-state.js
 *
 * 与 yuebot DAO 联动: STALE_AFTER_SECONDS 必须与
 *   /opt/telegram-bot/yue/dao/v2_user.py 的 DEVICE_ONLINE_STALE_SECONDS 一致。
 *   2026-05-22: 双侧同步收紧 600 → 180 (P0-C governance)。
 */
class Plugin extends AbstractPlugin
{
    private const STALE_AFTER_SECONDS = 180;

    public function boot(): void
    {
        $this->filter('user.subscribe.response', function ($user) {
            $id = request()->user()?->id;
            if (!$id) {
                return $user;
            }
            $row = User::query()
                ->whereKey($id)
                ->select(['online_count', 'last_online_at'])
                ->first();
            if (!$row) {
                return $user;
            }
            $online = (int) ($row->online_count ?? 0);
            $lastSeen = $row->last_online_at;
            try {
                $fresh = $lastSeen
                    && Carbon::parse($lastSeen)->diffInSeconds(now()) <= self::STALE_AFTER_SECONDS;
            } catch (\Throwable $e) {
                $fresh = false;
            }
            $user['online_count'] = $fresh ? $online : 0;
            if ($lastSeen) {
                $user['last_online_at'] = $lastSeen;
            }
            return $user;
        });
    }
}
