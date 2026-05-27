<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2_invite_code.code 加 partial unique（仅 status=false 也即 UNUSED 时唯一）
 *
 * 业务约束：active (UNUSED) 邀请码必须全局唯一，否则 RegisterService::handleInviteCode
 *   .first() 取第一个匹配，归属可能错乱。
 *
 * status=true (USED) 的码可重复（XBoard 原生用法：消费后保留历史可被复用作不同用户的码）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        // 先检查是否有违反新约束的数据
        $dups = DB::select("
            SELECT code, COUNT(*) AS cnt
            FROM v2_invite_code
            WHERE status = false
            GROUP BY code
            HAVING COUNT(*) > 1
        ");

        if (!empty($dups)) {
            // 不抛错，只警告。运维需手工处理后再上线
            \Log::warning('[InviteAlias] v2_invite_code has duplicates in status=false', ['dups' => $dups]);
            // 临时方案：保留 user_id 最大的（最近的），其他改为 status=true
            foreach ($dups as $d) {
                DB::statement("
                    UPDATE v2_invite_code
                    SET status = true, updated_at = ?
                    WHERE code = ? AND status = false
                      AND id NOT IN (
                          SELECT id FROM v2_invite_code WHERE code = ? AND status = false
                          ORDER BY id DESC LIMIT 1
                      )
                ", [time(), $d->code, $d->code]);
            }
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_code_active
            ON v2_invite_code (code)
            WHERE status = false
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement("DROP INDEX IF EXISTS uq_invite_code_active");
    }
};
