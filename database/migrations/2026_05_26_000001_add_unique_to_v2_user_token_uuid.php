<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add UNIQUE indexes to v2_user.token and v2_user.uuid.
 *
 * These columns are conceptually unique (the token is what subscription URLs
 * are keyed on; uuid is the user's network credential). They had no index of
 * any kind on a yueops production database that was carried over from v2board
 * — so concurrent INSERT could in theory race and produce duplicates. None
 * has ever been observed in 30k+ rows so the table is safe to constrain.
 *
 * Uses CREATE UNIQUE INDEX CONCURRENTLY (PostgreSQL) so production tables are
 * not locked. For MySQL, falls back to a plain ALTER (typically <1s on this
 * row count).
 *
 * Idempotent: skips if index already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        $indexes = [
            'idx_v2_user_token_unique' => 'token',
            'idx_v2_user_uuid_unique'  => 'uuid',
        ];

        foreach ($indexes as $name => $col) {
            if ($driver === 'pgsql') {
                $exists = DB::selectOne(
                    "SELECT 1 AS x FROM pg_indexes WHERE schemaname = 'public' AND indexname = ?",
                    [$name]
                );
                if ($exists) {
                    continue;
                }
                DB::statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON v2_user({$col})");
            } else {
                if (Schema::hasIndex('v2_user', $name)) {
                    continue;
                }
                DB::statement("ALTER TABLE v2_user ADD UNIQUE INDEX {$name} ({$col})");
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (['idx_v2_user_token_unique', 'idx_v2_user_uuid_unique'] as $name) {
            if ($driver === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            } else {
                DB::statement("ALTER TABLE v2_user DROP INDEX {$name}");
            }
        }
    }

    /**
     * CONCURRENTLY cannot run inside a transaction; Laravel migrations wrap
     * up() in a transaction by default unless this is disabled.
     */
    public $withinTransaction = false;
};
