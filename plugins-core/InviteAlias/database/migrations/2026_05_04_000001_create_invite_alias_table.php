<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2_invite_alias —— 用户专属邀请别名（自定义邀请码 + 子域名）
 *
 * 全自助审核 + 两阶段提交 + 永久持有 + 活跃绑定生命周期
 *
 * 状态机：
 *   pending(0) → active(1) → dormant(2) → released(4)
 *                          → banned(3)  → released(4) [申诉拒绝]
 *                          → banned(3)  → active(1)   [申诉通过]
 *   pending 30min 过期 → released
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_invite_alias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');

            // alias_type: 1=invite_code, 2=isolated_sub (i.yue.to), 3=brand_sub (yue.to)
            //            4=page (预留, Linktree 风格), 5=team (预留, 工作空间)
            $table->unsignedTinyInteger('alias_type');

            $table->string('alias', 64);           // 保留大小写显示
            $table->string('alias_lower', 64);     // 大小写不敏感唯一约束
            $table->string('zone', 32)->default('-');  // 'i.yue.to' / 'yue.to' / '-'(invite_code 无 zone)

            // 状态: 0=pending 1=active 2=dormant 3=banned 4=released
            $table->unsignedTinyInteger('status')->default(0);

            $table->unsignedInteger('cost_points');

            // 两阶段提交相关
            $table->unsignedBigInteger('pending_expires_at')->nullable();

            // 生命周期
            $table->unsignedBigInteger('last_subscribed_at')->nullable();  // 持有人最近活跃订阅
            $table->unsignedBigInteger('dormant_at')->nullable();
            $table->unsignedBigInteger('released_at')->nullable();
            $table->unsignedBigInteger('banned_at')->nullable();
            $table->text('ban_reason')->nullable();

            // 风控 / 审计
            $table->string('register_ip', 45)->nullable();      // INET，IPv6 兼容
            $table->text('register_ua')->nullable();
            $table->json('audit_trail')->nullable();             // L1-L6 各层结果快照

            // 营销追踪指标（事件表为细粒度，这里是聚合）
            $table->unsignedBigInteger('click_count')->default(0);
            $table->unsignedBigInteger('conv_count')->default(0);

            // 转让支持（v3 留口）
            $table->boolean('transferable')->default(false);
            $table->unsignedInteger('transferred_from')->nullable();
            $table->unsignedBigInteger('transferred_at')->nullable();

            // 扩展字段（meta JSON 留给未来字段，避免 schema migration 频繁）
            $table->json('meta')->nullable();

            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');

            $table->index(['user_id', 'status'], 'idx_alias_user_status');
            $table->index(['alias_type', 'status'], 'idx_alias_type_status');
            $table->index(['status', 'last_subscribed_at'], 'idx_alias_lifecycle');
            $table->index('register_ip', 'idx_alias_register_ip');
        });

        // PG: 部分唯一索引 —— 同 zone 内活跃 alias 全局唯一
        // 包括 pending(0)/active(1)/dormant(2) 三态，防并发抢注
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX uq_alias_active_zone
                ON v2_invite_alias (zone, alias_lower)
                WHERE status IN (0, 1, 2)
            ");
            // 释放冷却期窗口查询索引
            DB::statement("
                CREATE INDEX idx_alias_released_cooldown
                ON v2_invite_alias (zone, alias_lower, released_at)
                WHERE status = 4
            ");
        } else {
            // MySQL fallback：用 generated column + 普通 unique
            DB::statement("
                CREATE UNIQUE INDEX uq_alias_active_zone
                ON v2_invite_alias (zone, alias_lower, status)
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_alias');
    }
};
