<?php

namespace Plugin\InviteAlias\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\AliasResolver;
use Plugin\InviteAlias\Services\PluginConfig;

/**
 * 别名生命周期推进
 *
 * active → dormant：持有人 last_subscribed_at 距今 > 90 天 (config 可调)
 * dormant → released：进入 dormant 又 > 180 天
 * 无活跃订阅判断：v2_user.expired_at < NOW
 *
 * 每日 03:30 调度，幂等
 */
class LifecycleTickCommand extends Command
{
    protected $signature = 'invite-alias:lifecycle-tick {--dry : Dry run, do not write}';
    protected $description = '推进 alias 生命周期 (active → dormant → released)';

    public function handle(AliasResolver $resolver): int
    {
        $now = time();
        $dormantAfter  = (int) PluginConfig::get('dormant_after_inactive_days', 90);
        $releaseAfter  = (int) PluginConfig::get('release_after_dormant_days', 180);
        $dry = (bool) $this->option('dry');

        // ─── Step 1: 同步 last_subscribed_at（从 v2_user.expired_at 推导） ───
        // expired_at NULL 或 expired_at > now：用户活跃 → last_subscribed = now
        // expired_at <= now：用户过期 → last_subscribed 不变（自然往前推进）
        $synced = DB::update("
            UPDATE v2_invite_alias a
            SET last_subscribed_at = ?
            FROM v2_user u
            WHERE a.user_id = u.id
              AND a.status IN (1, 2)
              AND (u.expired_at IS NULL OR u.expired_at > ?)
        ", [$now, $now]);

        // ─── Step 1.5: dormant(2) → active(1) 复活（用户重新订阅） ───
        // 缺这一步会导致：D90 lapsed → dormant；D100 续费 → 永远停在 dormant；
        // D270 即使在订阅 → 仍因 dormant_at < releaseCutoff 被释放
        // 必须在 Step 2/3 之前跑，否则同一个 tick 里 last_subscribed 刚被刷新还是会被判 dormant
        $toRevive = DB::table('v2_invite_alias as a')
            ->join('v2_user as u', 'a.user_id', '=', 'u.id')
            ->where('a.status', 2)
            ->where(function ($q) use ($now) {
                $q->whereNull('u.expired_at')
                  ->orWhere('u.expired_at', '>', $now);
            })
            ->select('a.id', 'a.user_id', 'a.alias', 'a.alias_lower', 'a.zone')
            ->limit(1000)
            ->get();

        $revivedCount = 0;
        foreach ($toRevive as $row) {
            if (!$dry) {
                DB::transaction(function () use ($row, $now) {
                    DB::table('v2_invite_alias')
                        ->where('id', $row->id)
                        ->where('status', 2)
                        ->update([
                            'status' => 1,
                            'dormant_at' => null,
                            'last_subscribed_at' => $now,
                            'updated_at' => $now,
                        ]);
                    // 防御性：active 阶段 v2_invite_code 必须是 status=false 才能归属。
                    // 正常 dormant 流不动 invite_code（只 release 才动），但 banned→appeal→
                    // 之后再被人 forceDormant 这种边界，invite_code 可能残留 status=true，
                    // 复活时一并恢复，确保归属链完整
                    DB::table('v2_invite_code')
                        ->where('user_id', $row->user_id)
                        ->where('code', $row->alias)
                        ->where('status', true)
                        ->update(['status' => false, 'updated_at' => $now]);
                });
                if ($row->zone !== '-') {
                    $resolver->invalidate($row->zone, $row->alias_lower);
                }
            }
            $revivedCount++;
        }

        // ─── Step 2: active(1) → dormant(2) ───
        $dormantCutoff = $now - $dormantAfter * 86400;
        $toDormant = DB::table('v2_invite_alias')
            ->where('status', 1)
            ->where(function ($q) use ($dormantCutoff) {
                $q->where('last_subscribed_at', '<', $dormantCutoff)
                  ->orWhereNull('last_subscribed_at');
            })
            ->select('id', 'zone', 'alias_lower', 'user_id', 'alias')
            ->limit(1000)
            ->get();

        $dormantCount = 0;
        foreach ($toDormant as $row) {
            if (!$dry) {
                DB::table('v2_invite_alias')
                    ->where('id', $row->id)
                    ->where('status', 1)
                    ->update([
                        'status' => 2,
                        'dormant_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($row->zone !== '-') {
                    $resolver->invalidate($row->zone, $row->alias_lower);
                }
            }
            $dormantCount++;
        }

        // ─── Step 3: dormant(2) → released(4) ───
        $releaseCutoff = $now - $releaseAfter * 86400;
        $toReleased = DB::table('v2_invite_alias')
            ->where('status', 2)
            ->where('dormant_at', '<', $releaseCutoff)
            ->whereNotNull('dormant_at')
            ->select('id', 'zone', 'alias_lower')
            ->limit(1000)
            ->get();

        $releasedCount = 0;
        // released 阶段：必须同步禁用 v2_invite_code.status=true，否则归属仍然有效
        $toReleasedFull = DB::table('v2_invite_alias')
            ->whereIn('id', $toReleased->pluck('id'))
            ->select('id', 'user_id', 'alias', 'alias_lower', 'zone')
            ->get();

        foreach ($toReleasedFull as $row) {
            if (!$dry) {
                DB::transaction(function () use ($row, $now) {
                    DB::table('v2_invite_alias')
                        ->where('id', $row->id)
                        ->where('status', 2)
                        ->update([
                            'status' => 4,
                            'released_at' => $now,
                            'updated_at' => $now,
                        ]);
                    DB::table('v2_invite_code')
                        ->where('user_id', $row->user_id)
                        ->where('code', $row->alias)
                        ->update(['status' => true, 'updated_at' => $now]);
                });
                if ($row->zone !== '-') {
                    $resolver->invalidate($row->zone, $row->alias_lower);
                }
            }
            $releasedCount++;
        }

        Log::info('[InviteAlias] lifecycle-tick', [
            'dry' => $dry,
            'synced_active' => $synced,
            'revived' => $revivedCount,
            'to_dormant' => $dormantCount,
            'to_released' => $releasedCount,
            'dormant_threshold_days' => $dormantAfter,
            'release_threshold_days' => $releaseAfter,
        ]);

        $this->info(sprintf('synced=%d  revived=%d  →dormant=%d  →released=%d  (dry=%s)',
            $synced, $revivedCount, $dormantCount, $releasedCount, $dry ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
