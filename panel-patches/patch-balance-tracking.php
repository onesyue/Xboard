<?php
// Balance Tracking Patch — UserService::addBalance 同步扣 user_account.balance
//
// 月底 bot 清理 (telegram-bot/yue/service/clear_balance.py) 通过 user_account.balance
// 累计「派出去的签到/抽奖赠送」,然后从 v2_user.balance 中扣同等金额。
// 边界 bug: 当 v2_user.balance ≤ user_account.balance 累计时,会把整个 v2_user.balance
// 置 0,可能误吞用户充值或从 commission_balance 划转过来的钱。
//
// 收口: 在 PHP 唯一的 balance 消费入口 UserService::addBalance 注入 hook,
// 当 $balance < 0 (消费) 时同步扣 user_account.balance (floor 0,FIFO 先消费赠送)。
// 入金路径不动: 佣金划转走 UserController::transfer 直接 += (绕过 addBalance);
// 退款/admin 加余额走 addBalance 但是正数,hook 跳过; bot 派赠送是 batch SQL,
// 也不经 addBalance。
//
// 月底清理后 user_account.balance 反映「真实剩余未消费赠送」,clear_balance.py 的
// reset-to-0 分支不会再被触发。
//
// 幂等 marker: [Patch BAL]
// 前置条件: addBalance 已被 patch-security.sh 第 5 段包成 DB::transaction
// Run via: docker compose exec -T web php /www/patch-balance-tracking.php

$marker = "[Patch BAL]";
$path = "/www/app/Services/UserService.php";

if (!file_exists($path)) {
    echo "patch-balance-tracking: $path not found, skip\n";
    exit(0);
}

$src = file_get_contents($path);

if (strpos($src, $marker) !== false) {
    echo "patch-balance-tracking: UserService.php already patched\n";
    exit(0);
}

if (strpos($src, "function addBalance") === false) {
    echo "patch-balance-tracking: addBalance method not found, skip\n";
    exit(0);
}

if (strpos($src, "DB::transaction(function () use (\$userId, \$balance)") === false) {
    echo "patch-balance-tracking: addBalance not in DB::transaction form (run patch-security.sh first), skip\n";
    exit(1);
}

$anchor = "            if (!\$user->save()) {\n                return false;\n            }\n            return true;\n        });\n    }";

if (strpos($src, $anchor) === false) {
    echo "patch-balance-tracking: anchor not found in addBalance, skip\n";
    exit(1);
}

$replacement = "            if (!\$user->save()) {\n                return false;\n            }\n            // [Patch BAL] Sync user_account.balance — FIFO consume gift balance\n            if (\$balance < 0) {\n                \$amt = (int) abs(\$balance);\n                DB::table('user_account')\n                    ->where('account_id', \$userId)\n                    ->where('balance', '>', 0)\n                    ->update(['balance' => DB::raw(\"GREATEST(balance - {\$amt}, 0)\")]);\n            }\n            return true;\n        });\n    }";

$out = str_replace($anchor, $replacement, $src);

if ($out === $src) {
    echo "patch-balance-tracking: replace produced no change, skip\n";
    exit(1);
}

file_put_contents($path, $out);
echo "patch-balance-tracking: UserService.php addBalance hook installed\n";
