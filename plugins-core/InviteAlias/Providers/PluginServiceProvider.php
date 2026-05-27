<?php

namespace Plugin\InviteAlias\Providers;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Plugin\InviteAlias\Http\Middleware\ResolveAliasMiddleware;

/**
 * 插件 Service Provider —— XBoard PluginManager::registerServiceProvider 自动注册
 *
 * v1 现状：middleware 注入实测在 Octane Swoole 下不可靠（kernel 状态隔离），
 *          实际 v1 路由层走 nginx 302。本注入保留作 v1.5 升级的"无成本启用点"。
 *
 * v1.5 升级路径：
 *   1. 验证 Octane state preservation 配置
 *   2. 改 nginx i-yue-to.conf 从 `return 302` 回 `proxy_pass http://yue_backend`
 *   3. 此 middleware 自动开始工作，渲染主站 + 设 cookie，地址栏保持子域
 */
class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // v1: 注入做了，但 Octane 下中间件不会进 pipeline (已知)
        // v1.5: 修 Octane state 配置 + nginx 改 proxy_pass 后此处自动生效
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware') &&
            !$this->kernelHasMiddleware($kernel, ResolveAliasMiddleware::class)) {
            $kernel->prependMiddleware(ResolveAliasMiddleware::class);
        }
    }

    public function boot(): void
    {
        // intentionally empty — Plugin::boot() 已经处理别的
    }

    private function kernelHasMiddleware($kernel, string $class): bool
    {
        try {
            $ref = new \ReflectionClass($kernel);
            $prop = $ref->getProperty('middleware');
            $prop->setAccessible(true);
            $list = (array) $prop->getValue($kernel);
            return in_array($class, $list, true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
