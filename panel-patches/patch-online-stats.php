<?php
// === 悦视频/面板 在线设备统计修复 ===
// 根因：v2_user.t 被订阅/登录等非代理活动刷新，不代表真在用代理；
//       online_count 断连后不清零。
// 修复：按 last_online_at>NOW()-10min AND online_count>0 统计。
// 影响：app/Http/Controllers/V2/Admin/StatController.php 两处

$chr = chr(39);
$path = "app/Http/Controllers/V2/Admin/StatController.php";
$f = file_get_contents($path);
$orig = $f;

// Old pattern (两处相同):
// $onlineDevices = User::where('t', '>=', time() - 600)
//     ->sum('online_count');
// $onlineUsers = User::where('t', '>=', time() - 600)
//     ->count();

$old_devices = "User::where(" . $chr . "t" . $chr . ", " . $chr . ">=" . $chr . ", time() - 600)\n            ->sum(" . $chr . "online_count" . $chr . ");";
$new_devices = "User::where(" . $chr . "last_online_at" . $chr . ", " . $chr . ">=" . $chr . ", now()->subMinutes(10))\n            ->where(" . $chr . "online_count" . $chr . ", " . $chr . ">" . $chr . ", 0)\n            ->sum(" . $chr . "online_count" . $chr . ");";

$old_users = "User::where(" . $chr . "t" . $chr . ", " . $chr . ">=" . $chr . ", time() - 600)\n            ->count();";
$new_users = "User::where(" . $chr . "last_online_at" . $chr . ", " . $chr . ">=" . $chr . ", now()->subMinutes(10))\n            ->where(" . $chr . "online_count" . $chr . ", " . $chr . ">" . $chr . ", 0)\n            ->count();";

$f = str_replace($old_devices, $new_devices, $f);
$f = str_replace($old_users, $new_users, $f);

if ($f !== $orig) {
    file_put_contents($path, $f);
    echo "StatController.php online-stats patched\n";
} else {
    echo "StatController.php already patched (or pattern not found)\n";
}
