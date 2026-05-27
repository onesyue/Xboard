<?php

namespace Plugin\InviteAlias\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\PluginConfig;

/**
 * CT (Certificate Transparency) Log 监控
 *
 * 用途：检测 *.i.yue.to 是否被签发我们没授权的证书（subdomain takeover 早期信号）
 *
 * 数据源：crt.sh 公开 CT log API
 *
 * 我们已知的合法签发者：Let's Encrypt (R10/R11/R12 + ISRG Root X1/X2)
 *
 * 触发：每小时一次。发现新增 issuer 不在白名单 → 推 admin Telegram 告警
 */
class CtLogMonitorCommand extends Command
{
    protected $signature = 'invite-alias:ct-log-monitor';
    protected $description = '监控 CT log 检测异常 *.i.yue.to 证书签发';

    /** Let's Encrypt 中级 CA 关键词白名单 */
    const ISSUER_WHITELIST = [
        "Let's Encrypt",
        'R10', 'R11', 'R12', 'R13', 'R14',
        'E5', 'E6', 'E7', 'E8',
        'ISRG Root X1', 'ISRG Root X2',
    ];

    public function handle(): int
    {
        try {
            $resp = Http::timeout(15)
                ->get('https://crt.sh/', [
                    'q'      => '%.i.yue.to',
                    'output' => 'json',
                ]);

            if (!$resp->ok()) {
                $this->warn("crt.sh HTTP {$resp->status()}");
                return self::SUCCESS;
            }

            $entries = $resp->json() ?: [];
            if (!is_array($entries)) {
                $this->warn('crt.sh 返回非数组');
                return self::SUCCESS;
            }

            $seenKey = 'invite_alias:ct:seen_ids';
            $seen = Cache::get($seenKey, []);
            if (!is_array($seen)) $seen = [];

            $newAlerts = [];
            $newSeen   = $seen;
            $cutoff = time() - 86400;  // 只看最近 24 小时

            foreach ($entries as $e) {
                $id     = (int) ($e['id'] ?? 0);
                $issuer = (string) ($e['issuer_name'] ?? '');
                $names  = (string) ($e['name_value'] ?? '');
                $loggedAt = strtotime($e['entry_timestamp'] ?? '') ?: 0;

                if ($id <= 0) continue;
                if ($loggedAt < $cutoff) continue;
                if (in_array($id, $seen, true)) continue;

                $newSeen[] = $id;

                $whitelisted = false;
                foreach (self::ISSUER_WHITELIST as $w) {
                    if (stripos($issuer, $w) !== false) {
                        $whitelisted = true;
                        break;
                    }
                }

                if (!$whitelisted) {
                    $newAlerts[] = [
                        'id' => $id,
                        'issuer' => $issuer,
                        'names' => $names,
                        'logged_at' => $e['entry_timestamp'] ?? '',
                    ];
                }
            }

            // 限制 cache 大小（最多 500 条）
            if (count($newSeen) > 500) {
                $newSeen = array_slice($newSeen, -500);
            }
            Cache::put($seenKey, $newSeen, 86400 * 7);

            if (!empty($newAlerts)) {
                $this->error('Detected ' . count($newAlerts) . ' suspicious certificates');
                Log::warning('[InviteAlias] CT log alerts', ['alerts' => $newAlerts]);

                $this->notifyAdmin($newAlerts);
            } else {
                $this->info('CT log clean (' . count($entries) . ' entries scanned)');
            }

        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] ct-log-monitor exception', ['err' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }

    private function notifyAdmin(array $alerts): void
    {
        $token   = PluginConfig::get('telegram_bot_token', '');
        $chatId  = PluginConfig::get('telegram_admin_chat_id', '');
        if (!$token || !$chatId) return;

        $lines = ["🚨 *InviteAlias CT log 异常*", '', "检测到 *.i.yue.to 非授权证书签发："];
        foreach (array_slice($alerts, 0, 5) as $a) {
            $names = mb_substr((string) ($a['names'] ?? ''), 0, 80);
            $lines[] = "• issuer: `{$a['issuer']}`";
            $lines[] = "  names: `{$names}`";
            $lines[] = "  logged: {$a['logged_at']}";
        }
        if (count($alerts) > 5) {
            $lines[] = '...另外 ' . (count($alerts) - 5) . ' 条';
        }

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => implode("\n", $lines),
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] CT alert TG send failed', ['err' => $e->getMessage()]);
        }
    }
}
