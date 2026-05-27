<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2_invite_alias_event —— 别名点击/转化事件
 *
 * 用途：
 *  - 用户中心展示「累计点击 / 转化数」
 *  - 反作弊：高点击零转化 → review_pending
 *  - 榜单：转化数 Top 10 alias 持有者
 *
 * 数据保留 90 天（接 yue-retention.timer 自动清理）。
 *
 * 隐私去标识化：ip / ua 只存 SHA256 前 8 字节 hash，不存原值
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_invite_alias_event', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alias_id');

            // 1=click 2=signup 3=first_pay (后两者由订单事件回填)
            $table->unsignedTinyInteger('event');

            // 转化时填，click 时为空
            $table->unsignedInteger('user_id')->nullable();

            // 去标识化指纹
            $table->char('ip_hash', 16);
            $table->char('ua_hash', 16)->nullable();

            $table->string('referer', 255)->nullable();
            $table->json('utm')->nullable();           // utm_source / utm_medium / utm_campaign

            $table->unsignedBigInteger('created_at');

            $table->index(['alias_id', 'event', 'created_at'], 'idx_alias_event');
            $table->index('created_at', 'idx_event_created');
        });

        // PG: created_at 索引使用 BRIN 节省空间（事件表写多读少，时序顺序）
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS idx_event_created");
            DB::statement("CREATE INDEX idx_event_created_brin ON v2_invite_alias_event USING brin (created_at)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_alias_event');
    }
};
