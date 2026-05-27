<?php

namespace Plugin\CommissionTier\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 升降档 Telegram 推送
 *
 * 数据来源：
 *   user_account.user_id     —— Telegram chat_id
 *   user_account.account_id  —— XBoard v2_user.id
 *   plugin config.telegram_bot_token / telegram_group_id —— 复用 yue bot
 *
 * 静默规则：
 *   - 用户未绑定 telegram → 跳过
 *   - L4/L5 升档群播 24h 同人冷却（防止刷屏）
 */
class NotificationService
{
    public function notifyUpgrade(int $userId, int $fromLevel, int $toLevel, array $cfg): void
    {
        if (!($cfg['telegram_notify'] ?? true)) return;
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if (!$token) return;
        if ($toLevel <= $fromLevel) return;

        $tier = $this->tierByLevel($toLevel, $cfg['tiers']);
        if (!$tier) return;

        $email = (string) DB::table('v2_user')->where('id', $userId)->value('email');
        $maskedEmail = $this->maskEmail($email);

        // 私聊
        $chatId = $this->userChatId($userId);
        if ($chatId) {
            $msg = sprintf(
                "🎉 <b>返利等级升级</b>\n\n你已升至 <b>%s%s</b>！\n滚动 %d 天内的后续邀请订单按 <b>%d%%</b> 循环返利结算。\n\n— 悦通",
                $this->e((string) $tier['name']),
                !empty($tier['badge']) && $tier['badge'] !== '—' ? ' · ' . $this->e((string) $tier['badge']) : '',
                (int) ($cfg['window_days'] ?? 90),
                (int) $tier['rate']
            );
            $this->send($token, $chatId, $msg);
        }

        // L4/L5 群播
        if ($toLevel >= 4 && !empty($cfg['telegram_group_id'])) {
            if (!$this->groupCooldownPassed($userId)) return;
            $msg = sprintf(
                "👑 <b>%s%s</b> 解锁！\n用户 <code>%s</code> 突破 <b>%d%%</b> 返利档，滚动 %d 天已带来 ¥%s 邀请成交。\n\n你也来试试 → /邀请",
                $this->e((string) $tier['name']),
                !empty($tier['badge']) && $tier['badge'] !== '—' ? ' · ' . $this->e((string) $tier['badge']) : '',
                $this->e($maskedEmail),
                (int) $tier['rate'],
                (int) ($cfg['window_days'] ?? 90),
                $this->windowGmv($userId, (int) ($cfg['window_days'] ?? 90))
            );
            $this->send($token, (string) $cfg['telegram_group_id'], $msg);
            $this->markGroupCooldown($userId);
        }
    }

    public function notifyDemote(int $userId, int $fromLevel, int $toLevel, array $cfg): void
    {
        if (!($cfg['telegram_notify'] ?? true)) return;
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if (!$token) return;
        if ($toLevel >= $fromLevel) return;

        $tier = $this->tierByLevel($toLevel, $cfg['tiers']);
        if (!$tier) return;

        $chatId = $this->userChatId($userId);
        if (!$chatId) return;
        $msg = sprintf(
            "ℹ️ <b>返利等级调整</b>\n\n你的滚动 %d 天累计邀请成交已不足维持原档，当前等级：<b>%s%s</b> (%d%%)。\n重新达标即可回归。\n\n— 悦通",
            (int) ($cfg['window_days'] ?? 90),
            $this->e((string) $tier['name']),
            !empty($tier['badge']) && $tier['badge'] !== '—' ? ' · ' . $this->e((string) $tier['badge']) : '',
            (int) $tier['rate']
        );
        $this->send($token, $chatId, $msg);
    }

    private function tierByLevel(int $level, array $tiers): ?array
    {
        foreach ($tiers as $t) {
            if ((int) $t['level'] === $level) return $t;
        }
        return null;
    }

    private function userChatId(int $userId): ?string
    {
        $row = DB::table('user_account')
            ->where('account_id', $userId)
            ->whereNotNull('account_id')
            ->first();
        if ($row && $row->user_id) {
            return (string) $row->user_id;
        }

        $telegramId = DB::table('v2_user')->where('id', $userId)->value('telegram_id');
        return $telegramId ? (string) $telegramId : null;
    }

    private function windowGmv(int $userId, int $windowDays): string
    {
        $since = time() - $windowDays * 86400;
        $cents = (int) DB::table('v2_order')
            ->where('invite_user_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->where(function ($q) use ($since) {
                $q->where('paid_at', '>', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('paid_at')->where('created_at', '>', $since);
                    });
            })
            ->sum('total_amount'); /* 2026-05-27 policy: balance 不算返佣 GMV */
        return number_format($cents / 100, 0);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '***';
        [$u, $d] = explode('@', $email, 2);
        $masked = strlen($u) <= 3 ? '***' : substr($u, 0, 3) . str_repeat('*', max(2, strlen($u) - 3));
        return $masked . '@' . $d;
    }

    private function groupCooldownPassed(int $userId): bool
    {
        $key = 'commission_tier:group_cd:' . $userId;
        return !\Cache::has($key);
    }

    private function markGroupCooldown(int $userId): void
    {
        \Cache::put('commission_tier:group_cd:' . $userId, 1, now()->addDay());
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function send(string $token, string $chatId, string $text): void
    {
        try {
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => 'true',
                ]),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) {
                Log::warning('[CommissionTier] telegram send failed', ['chat' => $chatId, 'code' => $code, 'resp' => $resp]);
            }
        } catch (\Throwable $e) {
            Log::error('[CommissionTier] telegram exception', ['err' => $e->getMessage()]);
        }
    }
}
