#!/usr/bin/env php
<?php
// patch-online-stats-v2.php — 在线设备统计准确性 v2 (2026-05-22)
//
// 现状（patch-online-stats.php v1 已修了 admin 看板 1 处，但本质问题没解决）：
//   1. CleanupOnlineStatus 5min/次 → online_count 滞后最多 5 分钟
//   2. stale 窗口 10min → 节点 push 卡顿 / IPv4 切 v6 时旧值滞留太久
//   3. DeviceStateService::TTL=300s → 节点 push_interval=60s, 容许 5 次丢包过宽
//
// 配合节点端 P0-A idle TTL reaper（180s 清 idle IP）后, 面板侧也要收紧:
//   - cleanup:online-status: 5min → 1min（与节点 push_interval=60s 同频）
//   - CleanupOnlineStatus stale: 10min → 3min（容许 3 次 push 丢失）
//   - DeviceStateService::TTL:   300s → 180s（与节点端 idle TTL 一致）
//   - DeviceStateService::DB_THROTTLE: 10s → 30s（降低高频 push 写库压力）
//
// 幂等: 每处 patch 检测目标字符串再替换, 不会重复 apply。
// 适用: Xboard cedar2025/master 当前 (compose branch ee2c12ed)。

$count = 0;

// ─── 1. Kernel.php: cleanup:online-status 5min → 1min ─────────────────────
$file = '/www/app/Console/Kernel.php';
$f = file_get_contents($file);
$old1 = "\$schedule->command('cleanup:online-status')->everyFiveMinutes()->onOneServer();";
$new1 = "\$schedule->command('cleanup:online-status')->everyMinute()->onOneServer()->withoutOverlapping(2);";
if (strpos($f, $new1) !== false) {
    echo "Kernel.php cleanup schedule already 1min\n";
} elseif (strpos($f, $old1) !== false) {
    $f = str_replace($old1, $new1, $f);
    file_put_contents($file, $f);
    echo "Kernel.php cleanup 5min → 1min OK\n";
    $count++;
} else {
    echo "Kernel.php cleanup target not found (manual review)\n";
}

// ─── 2. CleanupOnlineStatus.php: stale 窗口 10min → 3min ──────────────────
$file = '/www/app/Console/Commands/CleanupOnlineStatus.php';
$f = file_get_contents($file);
$old2 = "now()->subMinutes(10)";
$new2 = "now()->subMinutes(3)";
if (strpos($f, $new2) !== false) {
    echo "CleanupOnlineStatus stale window already 3min\n";
} elseif (strpos($f, $old2) !== false) {
    $f = str_replace($old2, $new2, $f);
    file_put_contents($file, $f);
    echo "CleanupOnlineStatus 10min → 3min OK\n";
    $count++;
} else {
    echo "CleanupOnlineStatus target not found\n";
}

// ─── 3. DeviceStateService.php: Redis TTL 300s → 180s ─────────────────────
$file = '/www/app/Services/DeviceStateService.php';
$f = file_get_contents($file);
$old3 = "private const TTL = 300;";
$new3 = "private const TTL = 180;";
if (strpos($f, $new3) !== false) {
    echo "DeviceStateService::TTL already 180\n";
} elseif (strpos($f, $old3) !== false) {
    $f = str_replace($old3, $new3, $f);
    file_put_contents($file, $f);
    echo "DeviceStateService TTL 300 → 180 OK\n";
    $count++;
} else {
    echo "DeviceStateService TTL target not found\n";
}

// ─── 4. DeviceStateService::notifyUpdate: 加 30s DB throttle ───────────────
// 原版 throttle 代码被注释了（注释里有 setnx/expire 蓝图），导致每次节点 push 都
// 写 DB user.online_count，high-traffic 节点 push 100 用户/分钟 → DB 1.6 QPS 写
// hot loop。1min cron 拉齐后, 30s throttle 既保 DB 健康又不损失精度。
$file = '/www/app/Services/DeviceStateService.php';
$f = file_get_contents($file);
$newThrottle = "private const DB_THROTTLE = 30;";
if (strpos($f, $newThrottle) !== false) {
    echo "DeviceStateService::DB_THROTTLE already 30\n";
} elseif (preg_match('/private const DB_THROTTLE = \\d+;/', $f)) {
    $f = preg_replace('/private const DB_THROTTLE = \\d+;/', $newThrottle, $f, 1);
    file_put_contents($file, $f);
    echo "DeviceStateService DB_THROTTLE → 30 OK\n";
    $count++;
}
$old4 = "        // if (Redis::setnx(\$dbThrottleKey, 1)) {\n        //     Redis::expire(\$dbThrottleKey, self::DB_THROTTLE);\n\n            User::query()";
$new4 = "        if (Redis::setnx(\$dbThrottleKey, 1)) {\n            Redis::expire(\$dbThrottleKey, self::DB_THROTTLE);\n\n            User::query()";
$old4_close = "                ]);\n        // }\n    }\n}";
$new4_close = "                ]);\n        }\n    }\n}";
if (strpos($f, "if (Redis::setnx(\$dbThrottleKey, 1)) {") !== false && strpos($f, "// if (Redis::setnx") === false) {
    echo "notifyUpdate DB throttle already enabled\n";
} elseif (strpos($f, $old4) !== false && strpos($f, $old4_close) !== false) {
    $f = str_replace($old4, $new4, $f);
    $f = str_replace($old4_close, $new4_close, $f);
    file_put_contents($file, $f);
    echo "notifyUpdate DB throttle enabled (30s) OK\n";
    $count++;
} else {
    echo "notifyUpdate throttle target not found (already done or pattern drift)\n";
}

echo "=== patch-online-stats-v2 done, applied=$count ===\n";
exit(0);
