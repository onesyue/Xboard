<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把 audit_trail / meta 从 json → jsonb
 *
 * 原因：UserAliasController::confirm 用 `audit_trail || '...'::jsonb` 拼接，
 *       PG 中只有 jsonb 支持 || 串联操作（json 不支持）。
 *
 * 修复：原地 ALTER 类型转换。表为空时直接转，无需 USING 子句但 PG 强制要求。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement("ALTER TABLE v2_invite_alias ALTER COLUMN audit_trail TYPE jsonb USING audit_trail::text::jsonb");
        DB::statement("ALTER TABLE v2_invite_alias ALTER COLUMN meta TYPE jsonb USING meta::text::jsonb");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement("ALTER TABLE v2_invite_alias ALTER COLUMN audit_trail TYPE json USING audit_trail::text::json");
        DB::statement("ALTER TABLE v2_invite_alias ALTER COLUMN meta TYPE json USING meta::text::json");
    }
};
