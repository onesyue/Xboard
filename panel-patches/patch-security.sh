#!/bin/bash
# Xboard 安全修复 + PG 兼容 patch — 定点注入，幂等，不覆盖整文件
# 每次 upgrade.sh 升级后执行: bash patch-security.sh

set -e
cd /home/xboard/yue-to

echo '[security-patch] Applying security & bug fix patches...'

docker compose exec -T web php -r '
$chr = chr(39);

// === 1. QueryOperators: add isValidFieldName() ===
$f = file_get_contents("app/Traits/QueryOperators.php");
if (strpos($f, "isValidFieldName") === false) {
    $anchor = "trait QueryOperators\n{";
    $method = "trait QueryOperators\n{\n    /**\n     * Validate that a field name is safe for use in queries.\n     * Prevents SQL injection via user-controlled column names.\n     */\n    protected function isValidFieldName(string \$field): bool\n    {\n        return (bool) preg_match(".$chr."/^[a-zA-Z_][a-zA-Z0-9_]*(\\\\.[a-zA-Z_][a-zA-Z0-9_]*)?\$/".$chr.", \$field);\n    }\n";
    $f = str_replace($anchor, $method, $f);
    file_put_contents("app/Traits/QueryOperators.php", $f);
    echo "QueryOperators.php patched\n";
} else {
    echo "QueryOperators.php already patched\n";
}

// === 2. Admin controllers: add field validation ===
$controllers = [
    "app/Http/Controllers/V2/Admin/UserController.php",
    "app/Http/Controllers/V2/Admin/OrderController.php",
    "app/Http/Controllers/V2/Admin/CouponController.php",
    "app/Http/Controllers/V2/Admin/TicketController.php",
];
foreach ($controllers as $path) {
    $f = file_get_contents($path);
    $changed = false;

    // Add QueryOperators trait if not present
    if (strpos($f, "isValidFieldName") === false && strpos($f, "QueryOperators") === false) {
        // For controllers that dont use QueryOperators yet
        if (strpos($f, "use \\App\\Traits\\QueryOperators") === false && strpos($f, "use QueryOperators") === false) {
            $f = str_replace(
                "class " . basename($path, ".php") . " extends Controller\n{",
                "class " . basename($path, ".php") . " extends Controller\n{\n    use \\App\\Traits\\QueryOperators;",
                $f
            );
            $changed = true;
        }
    }

    // Add field validation before filter usage
    // Pattern: $field = $filter["id"] or $key = $filter["id"] without validation
    if (strpos($f, "isValidFieldName") === false) {
        // Handle $field = $filter["id"] pattern
        $f = preg_replace(
            "/(\\\$field = \\\$filter\[.id.\];)\n(\s+\\\$value = )/",
            "$1\n            if (!\$this->isValidFieldName(\$field)) return;\n$2",
            $f
        );
        // Handle $key = $filter["id"] pattern
        $f = preg_replace(
            "/(\\\$key = \\\$filter\[.id.\];)\n(\s+\\\$value = )/",
            "$1\n                if (!\$this->isValidFieldName(\$key)) return;\n$2",
            $f
        );
        // Handle sort $field = $sort["id"]
        $f = preg_replace(
            "/(\\\$field = \\\$sort\[.id.\];)\n(\s+\\\$direction = )/",
            "$1\n            if (!\$this->isValidFieldName(\$field)) return;\n$2",
            $f
        );
        // Handle sort $key = $sort["id"]
        $f = preg_replace(
            "/(\\\$key = \\\$sort\[.id.\];)\n(\s+\\\$value = \\\$sort)/",
            "$1\n                if (!\$this->isValidFieldName(\$key)) return;\n$2",
            $f
        );
        $changed = true;
    }

    if ($changed) {
        file_put_contents($path, $f);
        echo basename($path) . " patched\n";
    } else {
        echo basename($path) . " already patched\n";
    }
}

// === 3. Server middleware: hash_equals ===
$f = file_get_contents("app/Http/Middleware/Server.php");
if (strpos($f, "hash_equals") === false && strpos($f, "\$value !== admin_setting") !== false) {
    $f = str_replace(
        "\$value !== admin_setting(".$chr."server_token".$chr.")",
        "!hash_equals((string) admin_setting(".$chr."server_token".$chr."), (string) \$value)",
        $f
    );
    file_put_contents("app/Http/Middleware/Server.php", $f);
    echo "Server.php middleware patched\n";
} else {
    echo "Server.php middleware already patched\n";
}

// === 3b. ServerV2 middleware: hash_equals (ec1efb44 新增，同样缺 timing-safe 比较) ===
if (file_exists("app/Http/Middleware/ServerV2.php")) {
    $f = file_get_contents("app/Http/Middleware/ServerV2.php");
    if (strpos($f, "hash_equals") === false && strpos($f, "\$value !== admin_setting") !== false) {
        $f = str_replace(
            "\$value !== admin_setting(".$chr."server_token".$chr.")",
            "!hash_equals((string) admin_setting(".$chr."server_token".$chr."), (string) \$value)",
            $f
        );
        file_put_contents("app/Http/Middleware/ServerV2.php", $f);
        echo "ServerV2.php middleware patched\n";
    } else {
        echo "ServerV2.php middleware already patched\n";
    }
}

// === 4. OrderService: STATUS_PROCESSING -> TYPE_NEW_PURCHASE ===
$f = file_get_contents("app/Services/OrderService.php");
if (strpos($f, "Order::STATUS_PROCESSING => admin_setting(".$chr."new_order_event_id") !== false) {
    $f = str_replace(
        "Order::STATUS_PROCESSING => admin_setting(".$chr."new_order_event_id",
        "Order::TYPE_NEW_PURCHASE => admin_setting(".$chr."new_order_event_id",
        $f
    );
    file_put_contents("app/Services/OrderService.php", $f);
    echo "OrderService.php patched\n";
} else {
    echo "OrderService.php already patched\n";
}

// === 5. UserService: whereNull + addBalance transaction + banned false ===
$f = file_get_contents("app/Services/UserService.php");
$changed = false;

// Fix where("plan_id", NULL) -> whereNull
if (strpos($f, "where(".$chr."plan_id".$chr.", NULL)") !== false) {
    $f = str_replace("where(".$chr."plan_id".$chr.", NULL)", "whereNull(".$chr."plan_id".$chr.")", $f);
    $changed = true;
}
// Fix orWhere("expired_at", NULL) -> orWhereNull
if (strpos($f, "orWhere(".$chr."expired_at".$chr.", NULL)") !== false) {
    $f = str_replace("orWhere(".$chr."expired_at".$chr.", NULL)", "orWhereNull(".$chr."expired_at".$chr.")", $f);
    $changed = true;
}
// Fix addBalance: wrap in DB::transaction if not already
if (strpos($f, "function addBalance") !== false && strpos($f, "DB::transaction(function") === false) {
    // Add DB import if missing
    if (strpos($f, "use Illuminate\\Support\\Facades\\DB;") === false) {
        $f = str_replace(
            "use App\\Services\\Plugin\\HookManager;",
            "use App\\Services\\Plugin\\HookManager;\nuse Illuminate\\Support\\Facades\\DB;",
            $f
        );
    }
    $old = "public function addBalance(int \$userId, int \$balance): bool\n    {\n        \$user = User::lockForUpdate()->find(\$userId);\n        if (!\$user) {\n            return false;\n        }\n        \$user->balance = \$user->balance + \$balance;\n        if (\$user->balance < 0) {\n            return false;\n        }\n        if (!\$user->save()) {\n            return false;\n        }\n        return true;\n    }";
    $new = "public function addBalance(int \$userId, int \$balance): bool\n    {\n        return DB::transaction(function () use (\$userId, \$balance) {\n            \$user = User::lockForUpdate()->find(\$userId);\n            if (!\$user) {\n                return false;\n            }\n            \$user->balance = \$user->balance + \$balance;\n            if (\$user->balance < 0) {\n                return false;\n            }\n            if (!\$user->save()) {\n                return false;\n            }\n            return true;\n        });\n    }";
    if (strpos($f, $old) !== false) {
        $f = str_replace($old, $new, $f);
        $changed = true;
    }
}
if ($changed) {
    file_put_contents("app/Services/UserService.php", $f);
    echo "UserService.php patched\n";
} else {
    echo "UserService.php already patched\n";
}

// === 6. CouponService: lockForUpdate in transaction ===
$f = file_get_contents("app/Services/CouponService.php");
if (strpos($f, "->lockForUpdate()\n            ->first();") !== false && strpos($f, "DB::transaction") === false) {
    // Remove lockForUpdate from constructor
    $f = str_replace(
        "Coupon::where(".$chr."code".$chr.", \$code)\n            ->lockForUpdate()\n            ->first()",
        "Coupon::where(".$chr."code".$chr.", \$code)->first()",
        $f
    );
    file_put_contents("app/Services/CouponService.php", $f);
    echo "CouponService.php constructor patched\n";
} else {
    echo "CouponService.php already patched\n";
}

// === 7. ThemeService: ZipSlip protection ===
$f = file_get_contents("app/Services/ThemeService.php");
if (strpos($f, "ZipSlip") === false && strpos($f, "\$zip->extractTo(\$tmpPath)") !== false) {
    $f = str_replace(
        "\$zip->extractTo(\$tmpPath);",
        "// Validate zip entries against ZipSlip (path traversal)\n            for (\$i = 0; \$i < \$zip->numFiles; \$i++) {\n                \$entryName = \$zip->getNameIndex(\$i);\n                if (str_contains(\$entryName, ".$chr."..".$chr.")) {\n                    \$zip->close();\n                    throw new Exception(".$chr."Invalid file path detected in theme package".$chr.");\n                }\n            }\n            \$zip->extractTo(\$tmpPath);",
        $f
    );
    file_put_contents("app/Services/ThemeService.php", $f);
    echo "ThemeService.php patched\n";
} else {
    echo "ThemeService.php already patched\n";
}

// === 8. Boolean fixes: where("banned", 0) -> false, where("show", 1) -> true ===
// Includes TelegramService (CRITICAL: admin notify uses where("is_admin", 1))
$bool_files = [
    "app/Services/UserService.php",
    "app/Services/ServerService.php",
    "app/Services/TrafficResetService.php",
    "app/Services/TelegramService.php",
    "app/Console/Commands/CheckTrafficExceeded.php",
    "app/Console/Commands/ResetTraffic.php",
    "app/Observers/PlanObserver.php",
    "app/Observers/ServerRouteObserver.php",
    "app/Http/Controllers/V1/User/KnowledgeController.php",
    "app/Http/Controllers/V1/User/OrderController.php",
];
$bool_cols = ["banned","show","enable","is_admin","is_staff","renew","sell",
              "is_enabled","rate_time_enable","remind_expire","remind_traffic",
              "commission_auto_check"];
foreach ($bool_files as $path) {
    if (!file_exists($path)) continue;
    $f = file_get_contents($path);
    $orig = $f;
    foreach ($bool_cols as $col) {
        $f = str_replace("where(".$chr.$col.$chr.", 0)", "where(".$chr.$col.$chr.", false)", $f);
        $f = str_replace("where(".$chr.$col.$chr.", 1)", "where(".$chr.$col.$chr.", true)", $f);
    }
    if ($f !== $orig) {
        file_put_contents($path, $f);
        echo basename($path) . " boolean patched\n";
    }
}

echo "\n[security-patch] All patches applied.\n";
'

# === PG ILIKE: Replace 'like' with 'ilike' in admin search controllers ===
# PostgreSQL LIKE is case-sensitive; ILIKE is case-insensitive (matches MySQL default).
echo '[pg-ilike] Applying PG ILIKE patches...'
docker cp patch-pgsql-ilike.php yue-to-web-1:/www/patch-pgsql-ilike.php
docker compose exec -T web php /www/patch-pgsql-ilike.php

# === 在线设备统计修复 — t字段虚高，改为last_online_at+online_count>0 ===
echo '[online-stats] Applying online-stats patch...'
docker cp patch-online-stats.php yue-to-web-1:/www/patch-online-stats.php
docker compose exec -T web php /www/patch-online-stats.php

# === Balance Tracking: addBalance 同步扣 user_account.balance (FIFO 消费赠送) ===
# 收口: 月底 bot 清理 user_account.balance 累计赠送,边界场景下会误吞充值/佣金划转的钱。
# 在 PHP 唯一消费入口 addBalance 注入 hook,消费 v2_user.balance 时同步扣赠送跟踪表。
# 详见 patch-balance-tracking.php 头部注释。前置: 第 5 段已把 addBalance 包成 transaction。
echo '[balance-tracking] Applying balance tracking patch...'
docker cp patch-balance-tracking.php yue-to-web-1:/www/patch-balance-tracking.php
docker compose exec -T web php /www/patch-balance-tracking.php

# ==========================================
# Fix 8: PG NULL compatibility — version_compare receives null clientVersion
# MySQL returns empty string for missing fields, PG returns NULL
# Without this fix: 1000+ deprecation warnings/day in PHP 8.2+
# ==========================================
if [ -f app/Support/AbstractProtocol.php ]; then
  # Line 165/185: add null guard before version_compare
  sed -i "s/if (\$requiredVersion !== '0.0.0' && version_compare(\$this->clientVersion/if (\$requiredVersion !== '0.0.0' \&\& \$this->clientVersion !== null \&\& version_compare(\$this->clientVersion/g" app/Support/AbstractProtocol.php
  # Line 209: use null coalescing
  sed -i "s/version_compare(\$this->clientVersion, \$minVersion/version_compare(\$this->clientVersion ?? '0.0.0', \$minVersion/g" app/Support/AbstractProtocol.php
  echo "[patch-security] Fixed AbstractProtocol.php version_compare null issue"
fi

# ==========================================
# Fix 9: BackupDatabase PG support
# Original only supports MySQL/SQLite, fails silently on PG
# ==========================================
if [ -f app/Console/Commands/BackupDatabase.php ]; then
  # Add PG support between sqlite and else blocks
  grep -q "pgsql" app/Console/Commands/BackupDatabase.php
  if [ $? -ne 0 ]; then
    sed -i "/备份失败.*sqlite.*mysql/i\\
            }elseif(config('database.default') === 'pgsql'){\\
                \$dbConfig = config('database.connections.pgsql');\\
                \$databaseBackupPath = storage_path('backup/' . now()->format('Y-m-d_H-i-s') . '_' . \$dbConfig['database'] . '_database_backup.sql');\\
                \$this->info(\"1️⃣：开始备份PostgreSQL\");\\
                \$env = ['PGPASSWORD' => \$dbConfig['password']];\\
                \$cmd = new Process(['pg_dump', '-h', \$dbConfig['host'], '-p', (string)\$dbConfig['port'], '-U', \$dbConfig['username'], '-Fc', \$dbConfig['database'], '-f', \$databaseBackupPath], null, \$env);\\
                \$cmd->setTimeout(600);\\
                \$cmd->run();\\
                if (!\$cmd->isSuccessful()) { \$this->error('PG备份失败: ' . \$cmd->getErrorOutput()); return; }\\
                \$this->info(\"2️⃣：PostgreSQL备份完成\");" app/Console/Commands/BackupDatabase.php
    echo "[patch-security] Added PG backup support to BackupDatabase"
  fi
fi

# ==========================================
# Fix 10: Plugin model add config => array cast (PG jsonb compatibility)
# ==========================================
if [ -f app/Models/Plugin.php ]; then
  if ! grep -q "'config' => 'array'" app/Models/Plugin.php; then
    sed -i "s/'is_enabled' => 'boolean'/'is_enabled' => 'boolean',\n        'config' => 'array'/" app/Models/Plugin.php
    echo "[patch-security] Added config => array cast to Plugin model"
  fi
fi

# ==========================================
# Fix 11: PluginManager + HasPluginConfig json_decode compatibility
# ==========================================
if [ -f app/Services/Plugin/PluginManager.php ]; then
  sed -i 's/\$values = json_decode(\$dbPlugin->config, true) ?: \[\]/\$values = is_array(\$dbPlugin->config) ? \$dbPlugin->config : (json_decode(\$dbPlugin->config, true) ?: [])/' app/Services/Plugin/PluginManager.php 2>/dev/null
  echo "[patch-security] Fixed PluginManager json_decode"
fi
if [ -f app/Traits/HasPluginConfig.php ]; then
  sed -i 's/return json_decode(\$plugin->config, true) ?? \[\]/return is_array(\$plugin->config) ? \$plugin->config : (json_decode(\$plugin->config, true) ?? [])/' app/Traits/HasPluginConfig.php 2>/dev/null
  echo "[patch-security] Fixed HasPluginConfig json_decode"
fi

# ==========================================
# Fix 12: Admin TicketController is_me compat
# 上游把 TicketMessage 的 is_me 改名为 is_from_user/is_from_admin（Model $appends），
# 但 admin 前端 bundle 仍然读 e.is_me → undefined → 全部消息渲染 mr-auto（左对齐）。
# 在 fetchTicketById 里给 messages 注入 is_me = is_from_admin（管理员视角，"我"=管理员回复）。
# 用户端 LiquidGlass 不受影响，因为 MessageResource 自己映射 is_me。
# ==========================================
docker compose exec -T web php -r '
$f = "app/Http/Controllers/V2/Admin/TicketController.php";
if (!file_exists($f)) { echo "[patch-security] TicketController.php not found, skip Fix 12\n"; exit; }
$src = file_get_contents($f);
if (strpos($src, "[is_me compat]") !== false) {
  echo "[patch-security] TicketController is_me compat already patched\n";
  exit;
}
$q = chr(39);
$anchor = "        \$result = \$ticket->toArray();\n        \$result[".$q."user".$q."] = UserController::transformUserData(\$ticket->user);";
$replace = "        \$result = \$ticket->toArray();\n        // [is_me compat] upstream renamed is_me to is_from_user/is_from_admin but admin bundle still reads is_me\n        if (isset(\$result[".$q."messages".$q."]) && is_array(\$result[".$q."messages".$q."])) {\n            foreach (\$result[".$q."messages".$q."] as &\$__m) { \$__m[".$q."is_me".$q."] = !empty(\$__m[".$q."is_from_admin".$q."]); }\n            unset(\$__m);\n        }\n        \$result[".$q."user".$q."] = UserController::transformUserData(\$ticket->user);";
if (strpos($src, $anchor) === false) {
  echo "[patch-security] TicketController anchor not found (upstream may have changed), skip Fix 12\n";
  exit;
}
file_put_contents($f, str_replace($anchor, $replace, $src));
echo "[patch-security] TicketController is_me compat patched\n";
'

# ==========================================
# Fix 13: Ticket message ORDER BY id (PostgreSQL stable ordering)
# 上游 TicketController::fetch L30 + Ticket::messages/message relation 无 ORDER BY。
# MySQL InnoDB 默认按 clustered-index(id) 返回恰好正确；PG 按 heap 物理顺序，同秒
# created_at 碰撞时翻面，表现为工单气泡"偶发不是一问一答"。用 id ASC 做稳定排序键。
# ==========================================
docker compose exec -T web php -r '
$q = chr(39);
$targets = [
  [
    "path"   => "app/Http/Controllers/V1/User/TicketController.php",
    "from"   => "TicketMessage::where(" . $q . "ticket_id" . $q . ", \$ticket->id)->get();",
    "to"     => "TicketMessage::where(" . $q . "ticket_id" . $q . ", \$ticket->id)->orderBy(" . $q . "id" . $q . ")->get();",
    "marker" => "->orderBy(" . $q . "id" . $q . ")->get();",
  ],
  [
    "path"   => "app/Models/Ticket.php",
    "from"   => "return \$this->hasMany(TicketMessage::class, " . $q . "ticket_id" . $q . ", " . $q . "id" . $q . ");",
    "to"     => "return \$this->hasMany(TicketMessage::class, " . $q . "ticket_id" . $q . ", " . $q . "id" . $q . ")->orderBy(" . $q . "id" . $q . ");",
    "marker" => "hasMany(TicketMessage::class, " . $q . "ticket_id" . $q . ", " . $q . "id" . $q . ")->orderBy(" . $q . "id" . $q . ")",
  ],
];
foreach ($targets as $cfg) {
  $f = $cfg["path"];
  if (!file_exists($f)) { echo "[patch-security] Fix13: $f not found, skip\n"; continue; }
  $src = file_get_contents($f);
  if (strpos($src, $cfg["marker"]) !== false) {
    echo "[patch-security] " . basename($f) . " ticket-order already patched\n";
    continue;
  }
  if (strpos($src, $cfg["from"]) === false) {
    echo "[patch-security] " . basename($f) . " ticket-order anchor not found, skip\n";
    continue;
  }
  $before = substr_count($src, $cfg["from"]);
  $new = str_replace($cfg["from"], $cfg["to"], $src);
  $after = substr_count($new, $cfg["from"]);
  file_put_contents($f, $new);
  echo "[patch-security] " . basename($f) . " ticket-order patched (" . ($before - $after) . " site)\n";
}
'

# ==========================================
# Fix 14: Telegram Plugin sendTicketNotify — 配合 Fix 13 的 relation orderBy 回归修复
# Fix 13 给 Ticket::messages/message relation 加了 ->orderBy("id")，导致原来的
# $ticket->messages()->latest()->first() 变成 ORDER BY id ASC, created_at DESC LIMIT 1
# → 返回最早消息而不是最新消息，工单 TG 通知内容错误。
# 用 ->reorder("id", "desc") 显式重置 order clause。
# ==========================================
docker compose exec -T web php -r '
$q = chr(39);
// 上游 c0b6ee1 (2026-04-18) 把 plugins/ → plugins-core/，PluginManager 优先 corePath。
// 同时打两个路径：plugins-core/ 是当前活路径，plugins/ 仅留作历史 volume 兼容。
$paths = ["plugins-core/Telegram/Plugin.php", "plugins/Telegram/Plugin.php"];
$from = "\$ticket->messages()->latest()->first();";
$to   = "\$ticket->messages()->reorder(".$q."id".$q.", ".$q."desc".$q.")->first();";
$marker = "->reorder(".$q."id".$q.", ".$q."desc".$q.")->first()";
$any = false;
foreach ($paths as $f) {
  if (!file_exists($f)) { echo "[patch-security] Fix14: $f not found, skip\n"; continue; }
  $src = file_get_contents($f);
  if (strpos($src, $marker) !== false) {
    echo "[patch-security] $f telegram-latest-msg already patched\n";
    $any = true; continue;
  }
  if (strpos($src, $from) === false) {
    echo "[patch-security] $f telegram-latest-msg anchor not found, skip\n";
    continue;
  }
  file_put_contents($f, str_replace($from, $to, $src));
  echo "[patch-security] $f telegram-latest-msg patched (1 site)\n";
  $any = true;
}
if (!$any) {
  echo "[patch-security] Fix14 FAIL: no Telegram plugin file patched\n";
  exit(1);
}
'

# === PG runtime compatibility patches that must run inside the container ===
echo '[pg-runtime-compat] Applying PG runtime compatibility patches...'
docker cp patch-pgsql-runtime-compat.php yue-to-web-1:/www/patch-pgsql-runtime-compat.php
docker compose exec -T web php /www/patch-pgsql-runtime-compat.php
