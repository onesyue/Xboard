<?php

namespace Plugin\InviteAlias\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 清理超时未 confirm 的 pending alias
 *
 * 触发：每分钟（schedule 注册）
 * 动作：所有 pending_expires_at < now 的 status=0 → status=4 (released)
 *
 * 必须存在的兜底：用户调 redeem-pending 后 TG bot 端扣分挂掉、网络中断、
 *               或用户 30 分钟内没完成扣分动作时，自动释放占位
 */
class CleanupPendingCommand extends Command
{
    protected $signature = 'invite-alias:cleanup-pending';
    protected $description = '清理超时未 confirm 的 pending alias';

    public function handle(): int
    {
        $now = time();

        $rows = DB::table('v2_invite_alias')
            ->where('status', 0)
            ->whereNotNull('pending_expires_at')
            ->where('pending_expires_at', '<', $now)
            ->select('id', 'user_id', 'alias', 'alias_type', 'zone', 'pending_expires_at')
            ->limit(500)
            ->get();

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $ids = $rows->pluck('id')->all();

        $affected = DB::table('v2_invite_alias')
            ->whereIn('id', $ids)
            ->where('status', 0)  // 双重确认（防 race）
            ->update([
                'status' => 4,        // released
                'released_at' => $now,
                'pending_expires_at' => null,
                'updated_at' => $now,
            ]);

        Log::info('[InviteAlias] cleanup-pending', [
            'released_count' => $affected,
            'sample_aliases' => $rows->take(5)->pluck('alias')->all(),
        ]);

        $this->info("Released {$affected} expired pending aliases");
        return self::SUCCESS;
    }
}
