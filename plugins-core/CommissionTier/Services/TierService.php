<?php

namespace Plugin\CommissionTier\Services;

use App\Models\Order;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Tier 计算与状态服务
 *
 * 单一事实源：v2_order（in cents），按 invite_user_id 在滚动窗口内累计已完成邀请订单 GMV。
 * 缓存：Redis tier:{uid} TTL 1800s；订单完成/取消 hook 通过 dirty:{uid} 主动失效。
 * 落库：commission_tier_user 表镜像当前/峰值，daily RecomputeTiers 巡检维持。
 *
 * 关键不变量：
 *  - current_tier / getRate() 始终按当前滚动窗口成交额实时匹配，不能被历史镜像表抬高
 *  - peak_tier 是永久铭牌，但会被全量历史邀请成交额校准，避免旧配置/错误数据授予无法达到的高档
 */
class TierService
{
    public const REDIS_PREFIX = 'commission:tier:gmv:';
    public const DIRTY_PREFIX = 'commission:tier:dirty:gmv:';
    public const CACHE_TTL = 1800;
    public const DEFAULT_TIERS = [
        ['level' => 0, 'name' => '普通', 'threshold' => 0, 'rate' => 10, 'badge' => '—', 'color' => '#9ca3af'],
        ['level' => 1, 'name' => 'VIP1', 'threshold' => 20000, 'rate' => 18, 'badge' => '青铜', 'color' => '#cd7f32'],
        ['level' => 2, 'name' => 'VIP2', 'threshold' => 50000, 'rate' => 26, 'badge' => '白银', 'color' => '#c0c0c0'],
        ['level' => 3, 'name' => 'VIP3', 'threshold' => 100000, 'rate' => 35, 'badge' => '黄金', 'color' => '#fbbf24'],
        ['level' => 4, 'name' => 'VIP4', 'threshold' => 200000, 'rate' => 42, 'badge' => '铂金', 'color' => '#67e8f9'],
        ['level' => 5, 'name' => 'VIP5', 'threshold' => 400000, 'rate' => 50, 'badge' => '钻石', 'color' => '#a78bfa'],
    ];

    /**
     * 取插件 config（始终从 PluginManager 现拿，admin 改完即时生效）
     */
    public function config(): array
    {
        $pm = app(PluginManager::class);
        $plugin = $pm->getEnabledPlugins()['commission_tier'] ?? null;
        if (!$plugin) {
            return $this->defaultConfig(false);
        }
        $cfg = $plugin->getConfig();
        $tiersRaw = $cfg['tiers_json'] ?? [];
        $tiers = is_string($tiersRaw) ? (json_decode($tiersRaw, true) ?: []) : $tiersRaw;
        $tiers = $this->normalizeTiers(is_array($tiers) ? $tiers : []);

        return [
            'enabled' => $this->boolConfig($cfg['enabled'] ?? true),
            'dry_run' => $this->boolConfig($cfg['dry_run'] ?? false),
            'window_days' => max(1, (int) ($cfg['window_days'] ?? 90)),
            'demote_grace_days' => max(0, (int) ($cfg['demote_grace_days'] ?? 7)),
            'telegram_notify' => $this->boolConfig($cfg['telegram_notify'] ?? true),
            'telegram_bot_token' => trim((string) ($cfg['telegram_bot_token'] ?? '')),
            'telegram_group_id' => trim((string) ($cfg['telegram_group_id'] ?? '')),
            'tiers' => $tiers,
        ];
    }

    /**
     * 解析用户当前 tier（带缓存）
     *
     * @return array{level:int, rate:int, name:string, badge:string, color:string,
     *               current:int, next_threshold:?int, next_level:?int, peak_level:int}
     */
    public function resolve(int $userId, bool $bypassCache = false): array
    {
        if (!$bypassCache) {
            try {
                if (!Redis::exists(self::DIRTY_PREFIX . $userId)) {
                    $cached = Redis::get(self::REDIS_PREFIX . $userId);
                    if ($cached) {
                        $row = json_decode($cached, true);
                        if (is_array($row) && isset($row['level'])) {
                            return $row;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[CommissionTier] redis read failed, fallback to DB', [
                    'user_id' => $userId,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $cfg = $this->config();
        $tiers = $cfg['tiers'];
        $sum = $this->windowSum($userId, $cfg['window_days']);

        $matched = $this->matchTier($sum, $tiers);
        $stored = DB::table('commission_tier_user')->where('user_id', $userId)->first();
        $effectiveLevel = (int) $matched['level'];
        $effective = $matched;
        $next = $this->nextTier($effectiveLevel, $tiers);
        $peakLevel = $this->resolvePeakLevel($userId, $stored, $effectiveLevel, $tiers);

        $row = [
            'level' => (int) $effective['level'],
            'rate' => (int) $effective['rate'],
            'name' => (string) $effective['name'],
            'badge' => (string) ($effective['badge'] ?? ''),
            'color' => (string) ($effective['color'] ?? '#9ca3af'),
            'current' => $sum,
            'next_threshold' => $next ? (int) $next['threshold'] : null,
            'next_level' => $next ? (int) $next['level'] : null,
            'peak_level' => $peakLevel,
        ];

        try {
            Redis::setex(self::REDIS_PREFIX . $userId, self::CACHE_TTL, json_encode($row));
            Redis::del(self::DIRTY_PREFIX . $userId);
        } catch (\Throwable $e) {
            Log::warning('[CommissionTier] redis write failed', [
                'user_id' => $userId,
                'err' => $e->getMessage(),
            ]);
        }
        return $row;
    }

    public function markDirty(int $userId): void
    {
        try {
            Redis::setex(self::DIRTY_PREFIX . $userId, 3600, '1');
            Redis::del(self::REDIS_PREFIX . $userId);
        } catch (\Throwable $e) {
            Log::warning('[CommissionTier] redis mark dirty failed', [
                'user_id' => $userId,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 90d 滚动窗口邀请成交 GMV (cents)。
     *
     * 2026-05-27 policy: 任何余额（佣金 / 奖励 / 退款 recycled）都不算返佣基数 → tier 等级判定
     * 同口径，只统计 total_amount（网关实付）。
     */
    public function windowSum(int $userId, int $windowDays): int
    {
        $since = time() - $windowDays * 86400;
        return (int) DB::table('v2_order')
            ->where('invite_user_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->where(function ($q) use ($since) {
                $q->where('paid_at', '>', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('paid_at')->where('created_at', '>', $since);
                    });
            })
            ->sum('total_amount');
    }

    /**
     * 全量历史邀请成交 GMV (cents)，用于校准永久铭牌上限。
     * 同上 — 仅 total_amount。
     */
    public function lifetimeSum(int $userId): int
    {
        return (int) DB::table('v2_order')
            ->where('invite_user_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->sum('total_amount');
    }

    public function hasPersonalCommissionRate(mixed $user): bool
    {
        if (!$user || !isset($user->commission_rate)) {
            return false;
        }
        // 与 XBoard upstream 的 if ($inviter->commission_rate) 语义对齐：0 不视为个人覆盖。
        return (float) $user->commission_rate > 0;
    }

    public function windowSumBeforeOrder(Order $order, int $windowDays): int
    {
        $asOf = (int) ($order->paid_at ?: $order->created_at ?: time());
        $since = $asOf - $windowDays * 86400;
        $effectiveAt = 'COALESCE(NULLIF(paid_at, 0), created_at)';

        return (int) DB::table('v2_order')
            ->where('invite_user_id', (int) $order->invite_user_id)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('trade_no', '!=', (string) $order->trade_no)
            ->whereRaw("$effectiveAt > ?", [$since])
            ->where(function ($q) use ($effectiveAt, $asOf, $order) {
                $q->whereRaw("$effectiveAt < ?", [$asOf])
                    ->orWhere(function ($q2) use ($effectiveAt, $asOf, $order) {
                        $q2->whereRaw("$effectiveAt = ?", [$asOf])
                            ->where('id', '<', (int) $order->id);
                    });
            })
            ->sum('total_amount');
    }

    public function rateForOrderAt(Order $order, array $cfg): int
    {
        $sum = $this->windowSumBeforeOrder($order, (int) ($cfg['window_days'] ?? 90));
        return (int) $this->matchTier($sum, $cfg['tiers'] ?? self::DEFAULT_TIERS)['rate'];
    }

    /**
     * 倒序匹配最高满足档；无配置或全部不满足 → fallback level=0 / rate=admin_setting
     */
    public function matchTier(int $amountCents, array $tiers): array
    {
        // tiers 已按 threshold 升序
        $hit = null;
        foreach ($tiers as $t) {
            if ($amountCents >= (int) $t['threshold']) {
                $hit = $t;
            } else {
                break;
            }
        }
        if ($hit) {
            return $hit;
        }
        return [
            'level' => 0,
            'name' => '普通',
            'threshold' => 0,
            'rate' => (int) admin_setting('invite_commission', 10),
            'badge' => '—',
            'color' => '#9ca3af',
        ];
    }

    public function nextTier(int $currentLevel, array $tiers): ?array
    {
        foreach ($tiers as $t) {
            if ((int) $t['level'] > $currentLevel) {
                return $t;
            }
        }
        return null;
    }

    public function tierByLevel(int $level, array $tiers): ?array
    {
        foreach ($tiers as $t) {
            if ((int) $t['level'] === $level) {
                return $t;
            }
        }
        return null;
    }

    private function resolvePeakLevel(int $userId, mixed $stored, int $effectiveLevel, array $tiers): int
    {
        $storedCurrent = $stored ? (int) ($stored->current_tier ?? 0) : 0;
        $storedPeak = $stored ? (int) ($stored->peak_tier ?? 0) : 0;
        $candidate = max($storedPeak, $storedCurrent, $effectiveLevel);

        // 配置曾经改过门槛时，旧 peak/current 可能高于真实历史成交能支持的等级。
        $lifetimeLevel = (int) $this->matchTier($this->lifetimeSum($userId), $tiers)['level'];
        return min($candidate, $lifetimeLevel);
    }

    /**
     * 给 listener 用：取 commission rate（含个人 commission_rate 覆盖逻辑外置）
     */
    public function getRateForOrder(int $inviterId): int
    {
        $tier = $this->resolve($inviterId);
        return (int) $tier['rate'];
    }

    /**
     * 持久化镜像，由 RecomputeTiers daily command 调用
     *
     * @return array{action:string, from:int, to:int, peak:int}
     */
    public function syncToTable(int $userId): array
    {
        $cfg = $this->config();
        $tiers = $cfg['tiers'];
        $sum = $this->windowSum($userId, $cfg['window_days']);
        $matched = $this->matchTier($sum, $tiers);
        $newLevel = (int) $matched['level'];

        $row = DB::table('commission_tier_user')->where('user_id', $userId)->first();
        $now = time();

        if (!$row) {
            DB::table('commission_tier_user')->insert([
                'user_id' => $userId,
                'current_tier' => $newLevel,
                'current_amount' => $sum,
                'peak_tier' => $newLevel,
                'peak_at' => $newLevel > 0 ? $now : null,
                'upgraded_at' => $now,
                'last_demote_at' => null,
                'below_threshold_streak_days' => 0,
                'computed_at' => $now,
            ]);
            $this->markDirty($userId);
            return ['action' => 'init', 'from' => 0, 'to' => $newLevel, 'peak' => $newLevel];
        }

        $oldLevel = (int) $row->current_tier;
        $effectiveLevel = $newLevel;
        $action = $newLevel > $oldLevel ? 'upgrade' : ($newLevel < $oldLevel ? 'demote' : 'noop');
        $newPeak = $this->resolvePeakLevel($userId, $row, $effectiveLevel, $tiers);
        $update = [
            'current_tier' => $effectiveLevel,
            'current_amount' => $sum,
            'peak_tier' => $newPeak,
            'below_threshold_streak_days' => 0,
            'computed_at' => $now,
        ];
        if ($effectiveLevel > $oldLevel) {
            $update['upgraded_at'] = $now;
        }
        if ($effectiveLevel < $oldLevel) {
            $update['last_demote_at'] = $now;
        }
        if ($newPeak > (int) $row->peak_tier) {
            $update['peak_at'] = $now;
        }
        DB::table('commission_tier_user')->where('user_id', $userId)->update($update);
        $this->markDirty($userId);

        return ['action' => $action, 'from' => $oldLevel, 'to' => $effectiveLevel, 'peak' => $newPeak];
    }

    /**
     * 全站 active inviter 列表（窗口内有已完成邀请订单）
     */
    public function activeInviterIds(int $windowDays): array
    {
        $since = time() - $windowDays * 86400;
        return DB::table('v2_order')
            ->where('status', Order::STATUS_COMPLETED)
            ->where(function ($q) use ($since) {
                $q->where('paid_at', '>', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('paid_at')->where('created_at', '>', $since);
                    });
            })
            ->whereNotNull('invite_user_id')
            ->distinct()
            ->pluck('invite_user_id')
            ->map(fn($v) => (int) $v)
            ->toArray();
    }

    private function defaultConfig(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'dry_run' => false,
            'window_days' => 90,
            'demote_grace_days' => 7,
            'telegram_notify' => true,
            'telegram_bot_token' => '',
            'telegram_group_id' => '',
            'tiers' => self::DEFAULT_TIERS,
        ];
    }

    private function boolConfig(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    private function normalizeTiers(array $tiers): array
    {
        $rows = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !isset($tier['level'], $tier['threshold'], $tier['rate'])) {
                continue;
            }
            $level = max(0, (int) $tier['level']);
            $rows[$level] = [
                'level' => $level,
                'name' => trim((string) ($tier['name'] ?? ($level === 0 ? '普通' : 'VIP' . $level))),
                'threshold' => max(0, (int) $tier['threshold']),
                'rate' => max(0, min(100, (int) $tier['rate'])),
                'badge' => trim((string) ($tier['badge'] ?? '')),
                'color' => $this->normalizeColor((string) ($tier['color'] ?? '#9ca3af')),
            ];
        }

        if (!isset($rows[0])) {
            $rows[0] = self::DEFAULT_TIERS[0];
        }
        if (count($rows) <= 1) {
            foreach (self::DEFAULT_TIERS as $tier) {
                $rows[(int) $tier['level']] = $rows[(int) $tier['level']] ?? $tier;
            }
        }

        usort($rows, fn($a, $b) => $a['threshold'] <=> $b['threshold'] ?: $a['level'] <=> $b['level']);
        return $rows;
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) ? $color : '#9ca3af';
    }
}
