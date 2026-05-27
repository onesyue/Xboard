<?php

namespace Plugin\InviteAlias\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\AliasResolver;
use Plugin\InviteAlias\Services\PluginConfig;

/**
 * Google Safe Browsing v4 扫描所有 active alias
 *
 * 命中即 dormant + 通知持有者 + 推 admin
 * 持有者 48h 内可申诉
 *
 * 频率：每周一次（每日跑一次也 OK，但 SB API quota 限制）
 *
 * 配置：
 *   admin_setting('invite_alias.safe_browsing_api_key') —— Google Cloud Console
 *   未配置时 fail-silent（不阻塞，只是没扫描）
 */
class SafeBrowsingScanCommand extends Command
{
    protected $signature = 'invite-alias:safe-browsing-scan';
    protected $description = '用 Google Safe Browsing 扫所有 active alias';

    public function handle(AliasResolver $resolver): int
    {
        $apiKey = PluginConfig::get('safe_browsing_api_key', '');
        if (!$apiKey) {
            $this->info('Safe Browsing API key 未配置，skip');
            return self::SUCCESS;
        }

        // 取所有 active 子域 alias 的完整 URL（type 1 邀请码无独立 URL，跳过）
        $aliases = DB::table('v2_invite_alias')
            ->whereIn('alias_type', [2, 3])
            ->where('status', 1)
            ->select('id', 'user_id', 'alias', 'alias_lower', 'zone')
            ->get();

        if ($aliases->isEmpty()) {
            $this->info('No active subdomain aliases');
            return self::SUCCESS;
        }

        // SB v4 lookup API 一次最多 500 URL
        $threats = [];
        foreach ($aliases->chunk(500) as $batch) {
            $entries = $batch->map(fn($a) => [
                'url' => "https://{$a->alias}.{$a->zone}/"
            ])->values()->all();

            try {
                $resp = Http::timeout(20)->post(
                    "https://safebrowsing.googleapis.com/v4/threatMatches:find?key={$apiKey}",
                    [
                        'client' => [
                            'clientId'      => 'yuelink-invite-alias',
                            'clientVersion' => '1.0.0',
                        ],
                        'threatInfo' => [
                            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                            'platformTypes'    => ['ANY_PLATFORM'],
                            'threatEntryTypes' => ['URL'],
                            'threatEntries'    => $entries,
                        ],
                    ]
                );

                if (!$resp->ok()) {
                    Log::warning('[InviteAlias] SB HTTP failure', ['status' => $resp->status()]);
                    continue;
                }

                $matches = $resp->json('matches') ?: [];
                foreach ($matches as $m) {
                    $threats[] = $m;
                }
            } catch (\Throwable $e) {
                Log::warning('[InviteAlias] SB exception', ['err' => $e->getMessage()]);
            }
        }

        if (empty($threats)) {
            $this->info('Clean: ' . $aliases->count() . ' aliases, 0 flagged');
            return self::SUCCESS;
        }

        $now = time();
        $dormantCount = 0;

        foreach ($threats as $t) {
            $url = $t['threat']['url'] ?? '';
            $type = $t['threatType'] ?? 'UNKNOWN';

            // URL → host → alias
            if (!preg_match('#https?://([^/]+)/#', $url, $m)) continue;
            $host = strtolower($m[1]);
            $alias = explode('.', $host)[0] ?? '';
            if (!$alias) continue;

            $row = DB::table('v2_invite_alias')
                ->where('alias_lower', $alias)
                ->where('status', 1)
                ->whereIn('alias_type', [2, 3])
                ->first();

            if (!$row) continue;

            // SafeBrowsing 命中视为高危，立即软封禁：alias→banned + invite_code→true
            // 申诉通过可由 admin unban 恢复
            DB::transaction(function () use ($row, $type, $now) {
                DB::table('v2_invite_alias')
                    ->where('id', $row->id)
                    ->where('status', 1)
                    ->update([
                        'status' => 3,  // banned（避免归属继续生效；申诉窗口仍开）
                        'banned_at' => $now,
                        'ban_reason' => "Google Safe Browsing flagged: {$type}",
                        'updated_at' => $now,
                    ]);
                DB::table('v2_invite_code')
                    ->where('user_id', $row->user_id)
                    ->where('code', $row->alias)
                    ->update(['status' => true, 'updated_at' => $now]);
            });

            $resolver->invalidate($row->zone, $row->alias_lower);
            $dormantCount++;

            Log::warning('[InviteAlias] SB flagged → dormant', [
                'alias_id' => $row->id,
                'host' => $host,
                'threat_type' => $type,
            ]);
        }

        $this->error("Flagged {$dormantCount} aliases as dormant");
        $this->notifyAdmin($threats, $dormantCount);

        return self::SUCCESS;
    }

    private function notifyAdmin(array $threats, int $count): void
    {
        $token = PluginConfig::get('telegram_bot_token', '');
        $chatId = PluginConfig::get('telegram_admin_chat_id', '');
        if (!$token || !$chatId) return;

        $lines = ["🚨 Safe Browsing 命中 {$count} 个 alias", ''];
        foreach (array_slice($threats, 0, 5) as $t) {
            $url = $t['threat']['url'] ?? '?';
            $type = $t['threatType'] ?? '?';
            $lines[] = "• {$url} ({$type})";
        }

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text'    => implode("\n", $lines),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] SB notify TG failed', ['err' => $e->getMessage()]);
        }
    }
}
