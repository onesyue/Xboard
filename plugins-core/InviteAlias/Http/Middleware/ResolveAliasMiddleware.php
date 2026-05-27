<?php

namespace Plugin\InviteAlias\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\AliasResolver;

/**
 * 子域名 → invite_code cookie 中间件
 *
 * 流程：
 *  1. 解析 host 是否为 alias 子域（i.yue.to 隔离 / yue.to 主域）
 *  2. 不是 → 直接 next，主站正常渲染
 *  3. 是 → 查 alias → 找到则设 aff_code cookie + 异步记 click event
 *  4. 无论是否命中，都继续 next 渲染主站（地址栏保持子域 = 反 aff 强度最大）
 *
 * 性能：5min Redis cache + 异步事件落库，对主站无 P95 影响
 */
class ResolveAliasMiddleware
{
    public function __construct(private AliasResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        // 仅 GET / HEAD 请求处理（POST/PUT 等是 API 调用，不需要 alias 归因）
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $host = $request->getHost();
        $parsed = $this->resolver->parseHost($host);

        if (!$parsed) {
            return $next($request);
        }

        $info = $this->resolver->resolve($parsed['zone'], $parsed['sub']);
        if (!$info) {
            // 未知 alias：fall through，主站正常渲染（不返 404，体验更好）
            return $next($request);
        }

        // 设 aff_code cookie，作用域 .yue.to 跨子域共享
        $cookieDomain = \Plugin\InviteAlias\Services\PluginConfig::get('cookie_domain', '.yue.to');
        $cookieDays   = (int) \Plugin\InviteAlias\Services\PluginConfig::get('cookie_max_age_days', 30);

        Cookie::queue(
            'aff_code',
            $info['invite_code'],
            $cookieDays * 24 * 60,  // minutes
            '/',
            $cookieDomain,
            true,    // secure
            true,    // httponly
            false,
            'Lax'
        );

        // 把 alias 信息挂到 request attribute，供下游 view / log 使用
        $request->attributes->set('invite_alias', [
            'alias_id'    => $info['alias_id'],
            'sub'         => $parsed['sub'],
            'zone'        => $parsed['zone'],
            'user_id'     => $info['user_id'],
            'invite_code' => $info['invite_code'],
        ]);

        // 异步记 click event（队列落库，不阻塞响应）
        try {
            \dispatch(new \Plugin\InviteAlias\Jobs\RecordAliasClickJob([
                'alias_id'  => $info['alias_id'],
                'ip'        => $request->ip(),
                'ua'        => $request->userAgent(),
                'referer'   => $request->headers->get('referer'),
                'utm'       => array_intersect_key($request->query(), array_flip([
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'
                ])),
            ]))->onQueue('low');
        } catch (\Throwable $e) {
            // 队列不可用时降级为直接 log，不影响主流程
            Log::info('[InviteAlias] click', [
                'alias_id' => $info['alias_id'],
                'sub'      => $parsed['sub'],
                'ip'       => $request->ip(),
            ]);
        }

        return $next($request);
    }
}
