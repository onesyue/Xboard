<?php

namespace Plugin\InviteAlias\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Host → user invite_code 解析
 *
 * 边界规则：
 *  - i.yue.to 子域：xxx.i.yue.to → 解析 alias_type=2
 *  - yue.to 主域：xxx.yue.to → 解析 alias_type=3，但要排除官方子域（my/api/ws/...）
 *  - 任何 unknown alias 直通主站，不做 302
 *
 * 5 分钟 Redis 缓存。alias 状态变更（兑换 / ban / dormant）必须显式
 *  Cache::forget("invite_alias:resolve:{$zone}:{$sub}")
 */
class AliasResolver
{
    /**
     * 已被 Xboard / nginx 占用的官方主域子域
     * 必须与 nginx server_name + 实际部署同步
     */
    const OFFICIAL_YUE_TO_SUBS = [
        // 平台
        'my', 'api', 'ws', 'sso', 'panel', 'admin',
        // 官网/静态
        'www', 'assets', 'static', 'cdn', 'docs',
        // 服务
        'stream', 'mc', 'emby', 'video',
        // 邮件 / DNS / 系统
        'mail', 'smtp', 'pop', 'imap', 'ns', 'ns1', 'ns2', 'dns',
        // 隔离子域本身（防止 i.yue.to 被解析为 alias）
        'i',
        // 自家次级品牌
        'yueto', 'yuelink', 'yuevideo', 'yuebao', 'yueops',
    ];

    /**
     * 解析 host 头到 (zone, sub)；返回 null 表示不是 alias 子域
     *
     * @return array{zone:string, sub:string}|null
     */
    public function parseHost(string $host): ?array
    {
        $host = strtolower($host);

        // i.yue.to 隔离域：xxx.i.yue.to
        if (preg_match('/^([a-z0-9][a-z0-9-]{1,18}[a-z0-9])\.i\.yue\.to$/', $host, $m)) {
            return ['zone' => 'i.yue.to', 'sub' => $m[1]];
        }

        // 主域 yue.to：xxx.yue.to（排除官方）
        if (preg_match('/^([a-z0-9][a-z0-9-]{1,18}[a-z0-9])\.yue\.to$/', $host, $m)) {
            $sub = $m[1];
            if (in_array($sub, self::OFFICIAL_YUE_TO_SUBS, true)) {
                return null;
            }
            return ['zone' => 'yue.to', 'sub' => $sub];
        }

        return null;
    }

    /**
     * 查询 alias → user 信息
     *
     * @return array{user_id:int, invite_code:string, alias_id:int, status:int}|null
     */
    public function resolve(string $zone, string $sub): ?array
    {
        $ttl = (int) \Plugin\InviteAlias\Services\PluginConfig::get('redis_cache_ttl_seconds', 300);
        $cacheKey = "invite_alias:resolve:{$zone}:{$sub}";

        return Cache::remember($cacheKey, $ttl, function () use ($zone, $sub) {
            // XBoard 邀请码在 v2_invite_code（不是 v2_user.invite_code 列）
            // 取该用户最近一个 UNUSED 码作为归属
            //   ⚠ status 语义反直觉：false=UNUSED(可用)、true=USED(已废弃)
            //   handleInviteCode 查的是 status=false，必须对齐
            $row = DB::table('v2_invite_alias as a')
                ->leftJoin('v2_invite_code as c', function ($j) {
                    $j->on('a.user_id', '=', 'c.user_id')
                      ->where('c.status', '=', false);
                })
                ->where('a.zone', $zone)
                ->where('a.alias_lower', $sub)
                ->whereIn('a.status', [1, 2])
                ->orderBy('c.id', 'desc')
                ->select(
                    'a.user_id',
                    'c.code as invite_code',
                    'a.id as alias_id',
                    'a.status as alias_status',
                    'a.alias'
                )
                ->first();

            if (!$row) return null;

            // 兜底：用户没 code 就用 alias 本身（type=1 自定义邀请码）
            $arr = (array) $row;
            if (empty($arr['invite_code'])) {
                $arr['invite_code'] = $arr['alias'];
            }
            return $arr;
        });
    }

    /**
     * 缓存失效（在 alias 兑换/ban/dormant/释放时调用）
     */
    public function invalidate(string $zone, string $sub): void
    {
        Cache::forget("invite_alias:resolve:{$zone}:{$sub}");
    }
}
