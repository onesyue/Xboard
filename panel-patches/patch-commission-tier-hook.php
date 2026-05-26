<?php
// CommissionTier Hook Patch — InviteController::fetch 注入 user.invite.commission_rate filter
//
// 让 CommissionTier 插件能 override 默认 invite_commission 为 VIP tier rate。
// 改动两处：
// 1) fetch() 内、$user->commission_rate 个人覆盖之后，加 else 分支，
//    走 HookManager::filter 让插件返回 tier rate。
// 2) return 前走 user.invite.fetch.response filter，把 tier 字段塞进原生响应。
//
// 个人率仍然最高优先：if ($user->commission_rate) 不动 → 走个人率
// 否则 → filter hook（无插件时返回原值，等价于现在的 admin_setting('invite_commission')）
//
// 幂等 markers: [Patch CT Rate], [Patch CT Response]
// Run via: docker exec yue-to-web-1 php /www/patch-commission-tier-hook.php

$rateMarker = "[Patch CT Rate]";
$responseMarker = "[Patch CT Response]";
$path = "/www/app/Http/Controllers/V1/User/InviteController.php";

if (!file_exists($path)) {
    echo "patch-commission-tier-hook: $path not found, skip\n";
    exit(0);
}

$src = file_get_contents($path);
$changed = false;

if (strpos($src, $rateMarker) === false) {
    $anchor = "        if (\$user->commission_rate) {\n            \$commission_rate = \$user->commission_rate;\n        }\n        \$uncheck_commission_balance";

    if (strpos($src, $anchor) === false) {
        echo "patch-commission-tier-hook: rate anchor not found\n";
        exit(1);
    }

    $replacement = "        if (\$user->commission_rate) {\n            \$commission_rate = \$user->commission_rate;\n        } else {\n            // [Patch CT Rate] CommissionTier filter — VIP tier 动态覆盖默认 invite_commission\n            \$commission_rate = (int) \\App\\Services\\Plugin\\HookManager::filter('user.invite.commission_rate', \$commission_rate, \$user);\n        }\n        \$uncheck_commission_balance";

    $src = str_replace($anchor, $replacement, $src);
    $changed = true;
}

if (strpos($src, $responseMarker) === false) {
    $anchor = "        return \$this->success(\$data);\n";
    if (strpos($src, $anchor) === false) {
        echo "patch-commission-tier-hook: response anchor not found\n";
        exit(1);
    }

    $replacement = "        // [Patch CT Response] CommissionTier enriches native invite response with tier details\n        \$data = \\App\\Services\\Plugin\\HookManager::filter('user.invite.fetch.response', \$data, \$user);\n        return \$this->success(\$data);\n";
    $src = str_replace($anchor, $replacement, $src);
    $changed = true;
}

if (!$changed) {
    echo "patch-commission-tier-hook: already patched\n";
    exit(0);
}

if (file_put_contents($path, $src) === false) {
    echo "patch-commission-tier-hook: write failed\n";
    exit(1);
}
echo "patch-commission-tier-hook: OK\n";
