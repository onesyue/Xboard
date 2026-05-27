<?php

namespace Plugin\InviteAlias\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * 异步落库 alias click event
 *
 * 调用方：ResolveAliasMiddleware
 * 队列：low（不影响主业务）
 * 失败：默默忽略（事件丢失不影响功能，只影响统计）
 */
class RecordAliasClickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $p = $this->payload;
        $now = time();

        // 去标识化：IP / UA → SHA256 前 8 字节
        $ipHash = strtoupper(substr(hash('sha256', (string) ($p['ip'] ?? '')), 0, 16));
        $uaHash = !empty($p['ua'])
            ? strtoupper(substr(hash('sha256', (string) $p['ua']), 0, 16))
            : null;

        DB::table('v2_invite_alias_event')->insert([
            'alias_id' => (int) ($p['alias_id'] ?? 0),
            'event' => 1,  // 1=click
            'user_id' => null,
            'ip_hash' => $ipHash,
            'ua_hash' => $uaHash,
            'referer' => isset($p['referer']) ? mb_substr((string) $p['referer'], 0, 255) : null,
            'utm' => !empty($p['utm']) ? json_encode($p['utm']) : null,
            'created_at' => $now,
        ]);

        // 同步推进 alias 聚合 click_count（写多读少，防 unbound）
        DB::table('v2_invite_alias')
            ->where('id', (int) ($p['alias_id'] ?? 0))
            ->increment('click_count');
    }

    public function failed(\Throwable $e): void
    {
        // 事件丢失不告警，只 debug 日志
        \Log::debug('[InviteAlias] click event failed silently', [
            'err' => $e->getMessage(),
            'payload' => $this->payload,
        ]);
    }
}
