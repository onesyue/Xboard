<?php

namespace Plugin\CommissionTier;

use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\CommissionTier\Services\TierService;

/**
 * 邀请返利 VIP 等级插件
 *
 * 核心 hook：listen 'order.create.after'
 *  - 时机：OrderService::createFromRequest 内 transaction，order 已 save，commission_balance 已被
 *    OrderService::setInvite 按 admin_setting/inviter.commission_rate 算过一遍。
 *  - 我们覆写 commission_balance + 再 save，保证后续 CheckCommission 入账时按新 rate。
 *
 * 设计原则：
 *  - 个人 commission_rate 非空 → 跳过（KOL/渠道合伙人通道独立）
 *  - dry_run=true → 只算 tier 写日志、不改 order
 *  - 关闭插件 → admin_setting('commission_first_time_enable') 与 'invite_commission' 决定行为
 */
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('order.create.after', [$this, 'onOrderCreated'], 10);
        $this->listen('order.open.after', [$this, 'onOrderOpened'], 20);
        $this->listen('order.cancel.after', [$this, 'onOrderCanceled'], 20);

        // 让 InviteController::fetch 的"佣金比例"原生显示 tier rate
        $this->filter('user.invite.commission_rate', [$this, 'overrideInviteRate'], 10);

        // 给前端邀请页注入完整 tier 信息字段（LiquidGlass 自带 stat 组件读出来即原生显示）
        $this->filter('user.invite.fetch.response', [$this, 'enrichInviteResponse'], 10);
    }

    /**
     * Filter: 替换 InviteController::fetch 默认 commission_rate 为 tier rate
     */
    public function overrideInviteRate($currentRate, $user)
    {
        if (!$user) return $currentRate;
        $svc = app(TierService::class);
        $cfg = $svc->config();
        if (!$cfg['enabled']) return $currentRate;
        // 个人 commission_rate 已被上游 if 分支处理，这里只覆盖默认 admin_setting 路径
        $tier = $svc->resolve((int) $user->id);
        return (int) $tier['rate'];
    }

    /**
     * Filter: 把 tier 详情塞进 invite/fetch 响应（前端读到即可展示）
     */
    public function enrichInviteResponse(array $data, $user): array
    {
        if (!$user) return $data;
        $svc = app(TierService::class);
        $cfg = $svc->config();
        if (!$cfg['enabled']) return $data;
        $tier = $svc->resolve((int) $user->id);
        $data['tier'] = [
            'level' => $tier['level'],
            'name' => $tier['name'],
            'badge' => $tier['badge'],
            'color' => $tier['color'],
            'rate' => $tier['rate'],
            'current_amount' => $tier['current'],
            'next_level' => $tier['next_level'],
            'next_threshold' => $tier['next_threshold'],
            'gap_to_next' => $tier['next_threshold'] !== null
                ? max(0, $tier['next_threshold'] - $tier['current'])
                : null,
            'peak_level' => $tier['peak_level'],
            'window_days' => $cfg['window_days'],
            'tiers' => array_map(fn($t) => [
                'level' => (int) $t['level'],
                'name' => $t['name'],
                'badge' => $t['badge'] ?? '',
                'color' => $t['color'] ?? '#9ca3af',
                'threshold' => (int) $t['threshold'],
                'rate' => (int) $t['rate'],
            ], $cfg['tiers']),
        ];
        return $data;
    }

    public function onOrderCreated(mixed $payload): void
    {
        $order = $payload instanceof Order ? $payload : null;
        if (!$order || !$order->invite_user_id) {
            return;
        }

        $svc = app(TierService::class);
        $cfg = $svc->config();
        if (!$cfg['enabled']) {
            return;
        }

        $inviter = User::find($order->invite_user_id);
        if (!$inviter) {
            return;
        }

        // 个人定制率优先
        if (!is_null($inviter->commission_rate) && $inviter->commission_rate !== '') {
            return;
        }

        $tier = $svc->resolve((int) $inviter->id);
        // 2026-05-27 policy: 任何余额（佣金 / 奖励 / 退款 recycled）都不算返佣基数。
        // order.create.after 触发时 handleUserBalance 已把 balance_amount 从 total_amount 扣掉，
        // 这里 $order->total_amount 即"网关实付"部分（gateway-only），正是返佣基数。
        $newCommission = (int) round(((int) ($order->total_amount ?? 0)) * $tier['rate'] / 100);

        if ($cfg['dry_run']) {
            Log::info('[CommissionTier] dry-run', [
                'order_id' => $order->id,
                'inviter_id' => $inviter->id,
                'tier' => $tier['level'],
                'rate' => $tier['rate'],
                'old_commission' => $order->commission_balance,
                'new_commission' => $newCommission,
            ]);
            return;
        }

        $oldRaw = $order->commission_balance;
        $oldCommission = (int) round((float) $oldRaw);
        // 始终覆写：上游 setInvite 用 float 乘法（admin_setting/100），会留 .5 残余；
        // commission_log 入账按原始 commission_balance，不取整 → 必须强制 int 化避免后续 +500 bonus 叠加放大
        if ($oldCommission === $newCommission && is_int($oldRaw)) {
            return;
        }

        $order->commission_balance = $newCommission;
        $order->save();

        Log::info('[CommissionTier] override commission', [
            'order_id' => $order->id,
            'inviter_id' => $inviter->id,
            'tier' => $tier['level'],
            'rate' => $tier['rate'],
            'old' => $oldCommission,
            'new' => $newCommission,
        ]);
    }

    /**
     * 订单完成后：标记 inviter tier 缓存失效，让下一单按最新 GMV 判档
     */
    public function onOrderOpened(mixed $payload): void
    {
        $order = $payload instanceof Order ? $payload : null;
        if (!$order || !$order->invite_user_id) {
            return;
        }

        $svc = app(TierService::class);
        $cfg = $svc->config();
        if (!$cfg['enabled']) {
            return;
        }

        $svc->markDirty((int) $order->invite_user_id);

        // 2026-05-29: 付款完成(order.open.after)时权威重算佣金，收口 create 时偶发的 race
        // (部分订单佣金按含余额的原始总额计算 → 多返佣 / 全余额单 total_amount=0 也返佣)。
        // 此时 $order->total_amount 已是最终「网关实付」。单调安全：
        //   - 只对「本来就计佣」(commission_balance>0) 的订单纠正金额，绝不凭空创造佣金；
        //   - 全余额单(网关实付=0)→ 佣金归 0；个人定制率用户不动。
        $cur = (int) round((float) ($order->commission_balance ?? 0));
        if ($cur <= 0) {
            return;
        }
        $inviter = User::find($order->invite_user_id);
        if (!$inviter) {
            return;
        }
        if (!is_null($inviter->commission_rate) && $inviter->commission_rate !== '') {
            return;
        }
        $tier = $svc->resolve((int) $inviter->id);
        $correct = (int) round(((int) ($order->total_amount ?? 0)) * $tier['rate'] / 100);
        if ($cur === $correct) {
            return;
        }
        if ($cfg['dry_run']) {
            Log::info('[CommissionTier] open-recompute dry-run', [
                'order_id' => $order->id, 'inviter_id' => $inviter->id,
                'tier' => $tier['level'], 'rate' => $tier['rate'],
                'gateway_paid' => (int) ($order->total_amount ?? 0),
                'old_commission' => $cur, 'new_commission' => $correct,
            ]);
            return;
        }
        $order->commission_balance = $correct;
        $order->save();
        Log::info('[CommissionTier] open-recompute override', [
            'order_id' => $order->id, 'inviter_id' => $inviter->id,
            'tier' => $tier['level'], 'rate' => $tier['rate'],
            'gateway_paid' => (int) ($order->total_amount ?? 0),
            'old' => $cur, 'new' => $correct,
        ]);
    }

    /**
     * 订单取消 → 标 dirty 让下次查询重算
     */
    public function onOrderCanceled(mixed $payload): void
    {
        $order = $payload instanceof Order ? $payload : null;
        if (!$order || !$order->invite_user_id) {
            return;
        }
        app(TierService::class)->markDirty((int) $order->invite_user_id);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('commission-tier:recompute')
            ->dailyAt('03:30')
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
