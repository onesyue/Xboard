<?php

namespace Plugin\InviteAlias\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Admin Telegram 告警统一入口
 *
 * 用途：
 *  - L4 AI 拒绝高风险 alias
 *  - 兑换异常 (扣分成功但 confirm 失败 + 自动退分)
 *  - cleanup-pending 大批 release（>50/分钟，可能有问题）
 *  - lifecycle dormant/release 大量推进
 *  - SafeBrowsing 命中
 *  - CT log 异常
 *
 * fail-silent：通知失败不影响主流程
 * fan-out：rate-limit 同 key 5 分钟只发一次（防刷屏）
 */
class AdminNotifier
{
    public static function send(string $title, array $context = [], string $rateLimitKey = ''): void
    {
        $token = PluginConfig::get('telegram_bot_token', '');
        $chatId = PluginConfig::get('telegram_admin_chat_id', '');
        if (!$token || !$chatId) return;

        // 限流（同 key 5 分钟发 1 次）
        if ($rateLimitKey) {
            $cacheKey = "invite_alias:notify:{$rateLimitKey}";
            if (\Cache::has($cacheKey)) return;
            \Cache::put($cacheKey, 1, 300);
        }

        $lines = ["🔔 *InviteAlias* {$title}", ''];
        foreach ($context as $k => $v) {
            $vs = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $vs = mb_substr($vs, 0, 200);
            $lines[] = "• `{$k}`: {$vs}";
        }
        $lines[] = '';
        $lines[] = '_' . date('Y-m-d H:i:s') . '_';

        try {
            Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => implode("\n", $lines),
                'parse_mode' => 'Markdown',
                'disable_notification' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] admin notify failed', ['err' => $e->getMessage()]);
        }
    }
}
