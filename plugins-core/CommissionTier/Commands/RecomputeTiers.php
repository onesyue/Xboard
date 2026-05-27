<?php

namespace Plugin\CommissionTier\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\CommissionTier\Services\NotificationService;
use Plugin\CommissionTier\Services\TierService;

/**
 * 每日 03:30 重算所有 active inviter 的 tier
 *
 * 1. 升档：即时生效
 * 2. 降档：累计 below_threshold_streak_days，达到 demote_grace_days 才降一级
 * 3. peak_tier 单调递增
 * 4. 同时回收：表里有但已退出窗口（≥ window 天没有任何返利记录）的用户也跑一次 syncToTable，
 *    会按 amount=0 走完整 demote 流程，避免老数据卡在高档不掉
 */
class RecomputeTiers extends Command
{
    protected $signature = 'commission-tier:recompute {--dry : Print actions but do not write}';
    protected $description = '重算所有 inviter 的 VIP tier 状态（每日定时调用）';

    public function handle(): int
    {
        $pm = app(PluginManager::class);
        if (!isset($pm->getEnabledPlugins()['commission_tier'])) {
            $this->warn('[commission-tier:recompute] plugin disabled, skip');
            return 0;
        }

        $svc = app(TierService::class);
        $cfg = $svc->config();

        $activeIds = $svc->activeInviterIds($cfg['window_days']);
        $existingIds = DB::table('commission_tier_user')->pluck('user_id')->map(fn($v) => (int) $v)->toArray();
        $allIds = array_unique(array_merge($activeIds, $existingIds));

        $stats = ['init' => 0, 'upgrade' => 0, 'demote' => 0, 'pending_demote' => 0, 'noop' => 0];
        $dry = (bool) $this->option('dry');

        foreach ($allIds as $uid) {
            try {
                if ($dry) {
                    $sum = $svc->windowSum($uid, $cfg['window_days']);
                    $matched = $svc->matchTier($sum, $cfg['tiers']);
                    $stored = (int) DB::table('commission_tier_user')->where('user_id', $uid)->value('current_tier');
                    $action = $matched['level'] > $stored ? 'upgrade' : ($matched['level'] < $stored ? 'demote_pending' : 'noop');
                    $this->line(sprintf('uid=%d sum=%d level=%d→%d action=%s', $uid, $sum, $stored, $matched['level'], $action));
                    continue;
                }
                $r = $svc->syncToTable($uid);
                $stats[$r['action']] = ($stats[$r['action']] ?? 0) + 1;

                if (in_array($r['action'], ['upgrade', 'demote'], true)) {
                    Log::info('[CommissionTier] tier change', [
                        'user_id' => $uid,
                        'action' => $r['action'],
                        'from' => $r['from'],
                        'to' => $r['to'],
                        'peak' => $r['peak'],
                    ]);
                    if ($r['action'] === 'upgrade') {
                        app(NotificationService::class)->notifyUpgrade($uid, $r['from'], $r['to'], $cfg);
                    } else {
                        app(NotificationService::class)->notifyDemote($uid, $r['from'], $r['to'], $cfg);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('[CommissionTier] recompute error', ['user_id' => $uid, 'err' => $e->getMessage()]);
            }
        }

        $this->info('[commission-tier:recompute] done: ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
        return 0;
    }
}
