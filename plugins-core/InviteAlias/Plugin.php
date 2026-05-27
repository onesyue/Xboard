<?php

namespace Plugin\InviteAlias;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\InviteAlias\Http\Middleware\ResolveAliasMiddleware;
use Plugin\InviteAlias\Services\AliasResolver;

/**
 * 邀请别名插件 (Invite Alias)
 *
 * 三档积分兑换永久专属推广链接：
 *  - 888  : 自定义邀请码 ?aff=demo
 *  - 1888 : 隔离子域 demo.i.yue.to
 *  - 8888 : 主域子域 demo.yue.to (北极星)
 *
 * 全自助审核（六层防线）：
 *  L1 格式  → L2 黑名单 → L3 同形 → L4 AI → L5 风控 → L6 唯一性扣分落库
 *
 * 路由层 (v1)：
 *  - 用户访问 *.i.yue.to → nginx 302 → https://yue.to/?invite_code=<sub>&utm_source=alias
 *  - confirm 时同步 INSERT v2_invite_code 让 XBoard 标准 RegisterService 完成归属
 *  - 不依赖 Laravel middleware（Octane 状态隔离让 pushMiddleware 不可靠）
 *  - v1.5 升级：CF ACM ($10/mo) + 修 middleware 后改回 proxy_pass 保 subdomain UX
 *
 * 数据生命周期：
 *  - active → (90d 无订阅) → dormant → (再 180d) → released → (再 30d 冷却) → 开放申请
 *  - 违规 ban：零退；申诉通过：100% 恢复
 */
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // Middleware 注入由 Providers/PluginServiceProvider::register() 完成
        // 这里只处理 console 命令注册

        // 注册 console 命令（artisan / scheduler 在 console 上下文）
        if (app()->runningInConsole()) {
            app()->resolving(\Illuminate\Contracts\Console\Kernel::class, function ($console) {
                if (!method_exists($console, 'registerCommand')) return;
                foreach ([
                    \Plugin\InviteAlias\Commands\CleanupPendingCommand::class,
                    \Plugin\InviteAlias\Commands\LifecycleTickCommand::class,
                    \Plugin\InviteAlias\Commands\CtLogMonitorCommand::class,
                    \Plugin\InviteAlias\Commands\SafeBrowsingScanCommand::class,
                ] as $cmdClass) {
                    $console->registerCommand(app($cmdClass));
                }
            });
        }
    }

    /**
     * Cron schedule
     *
     * 03:30 - 生命周期推进（active → dormant → released）
     * 04:30 - Google Safe Browsing 扫所有 active alias（接 retention）
     * 05:30 - CT log 监控（hourly 任务的日级聚合）
     */
    public function schedule(Schedule $schedule): void
    {
        // 每分钟：清理超过 pending_ttl 的未确认 alias
        $schedule->command('invite-alias:cleanup-pending')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(2);

        // 每天 03:30：生命周期推进
        $schedule->command('invite-alias:lifecycle-tick')
            ->dailyAt('03:30')
            ->onOneServer()
            ->withoutOverlapping(60);

        // 每小时：CT log 异常证书签发监控
        $schedule->command('invite-alias:ct-log-monitor')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(30);

        // 每周一 04:30：Google Safe Browsing 扫描
        $schedule->command('invite-alias:safe-browsing-scan')
            ->weeklyOn(1, '04:30')
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
