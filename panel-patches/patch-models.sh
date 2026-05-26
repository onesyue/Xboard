#!/bin/bash
# Xboard Model $casts patch — 修复 PG numeric/bigint 返回 string 导致 JS 字符串拼接
# 每次 docker compose pull 升级后执行: bash patch-models.sh
# 幂等：已 patch 的字段不会重复添加

set -e
cd /home/xboard/yue-to

echo "[patch] Patching Xboard Models for PostgreSQL compatibility..."

docker compose exec -T web php -r '
$chr = chr(39);

function patch($file, $anchor, $adds) {
    global $chr;
    $f = file_get_contents($file);
    $changed = false;
    foreach($adds as $pair) {
        $field = is_array($pair) ? $pair[0] : $pair;
        $type  = is_array($pair) ? $pair[1] : "integer";
        if(strpos($f, $chr.$field.$chr." =>") === false) {
            $f = str_replace($anchor, $anchor.",\n        ".$chr.$field.$chr." => ".$chr.$type.$chr, $f);
            $changed = true;
        }
    }
    if($changed) file_put_contents($file, $f);
    echo basename($file)." patched\n";
}

// Order — 金额字段 + 状态/外键 (PG bigint→string 修复)
patch("app/Models/Order.php",
    "handling_amount".$chr." => ".$chr."integer".$chr,
    ["total_amount","discount_amount","balance_amount","refund_amount",
     "surplus_amount","commission_balance","actual_commission_balance",
     "status","type","commission_status","user_id","payment_id","plan_id",
     "coupon_id","invite_user_id","paid_at"]);

// User — 流量/余额/ID + 时间字段 + 折扣
patch("app/Models/User.php",
    "commission_rate".$chr." => ".$chr."float".$chr,
    ["balance","commission_balance","discount",
     "u","d","transfer_enable","t",["app_sign_balance","float"],
     "telegram_id","plan_id","group_id","speed_limit","device_limit","online_count",
     "expired_at","last_login_at","last_reset_at","next_reset_at",
     "app_sign_streak","archived_orders","reset_count","invite_user_id","last_login_ip"]);

// Payment — 手续费 + sort
patch("app/Models/Payment.php",
    "enable".$chr." => ".$chr."boolean".$chr,
    [["handling_fee_percent","float"],["handling_fee_fixed","float"],"sort"]);

// Coupon — 优惠券金额/限制 + 时间字段
patch("app/Models/Coupon.php",
    "show".$chr." => ".$chr."boolean".$chr,
    [["value","float"],"limit_use","limit_use_with_user","type",
     "started_at","ended_at"]);

// CommissionLog — 佣金金额 + 用户外键
patch("app/Models/CommissionLog.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["order_amount","get_amount","user_id","invite_user_id"]);

// Stat — 统计计数
patch("app/Models/Stat.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["order_total","commission_total","paid_total",
     "record_at","order_count","commission_count","paid_count","register_count","invite_count"]);

// Plan — 容量/速度 + sort
patch("app/Models/Plan.php",
    "reset_traffic_method".$chr." => ".$chr."integer".$chr,
    ["transfer_enable","capacity_limit","speed_limit","device_limit","sort",["sell","boolean"]]);

// Server — 倍率/端口 + parent_id (HK1-4 子节点关系) + sort
// 上游 fe62542b 已加 transfer_enable/u/d/machine_id integer cast，不再重复
patch("app/Models/Server.php",
    "rate_time_enable".$chr." => ".$chr."boolean".$chr,
    [["rate","float"],"server_port","parent_id","sort"]);

// Ticket — 状态/级别/外键 (CRITICAL: AI 客服 reply_status 比较)
patch("app/Models/Ticket.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["level","reply_status","status","user_id","last_reply_user_id"]);

// TicketMessage — 工单归属
patch("app/Models/TicketMessage.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["ticket_id","user_id"]);

// AdminAuditLog — 管理员审计
patch("app/Models/AdminAuditLog.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["admin_id"]);

// Notice — 排序
patch("app/Models/Notice.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["sort"]);

// InviteCode — PV + 用户
patch("app/Models/InviteCode.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["pv","user_id"]);

// StatUser — 用户流量统计
patch("app/Models/StatUser.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    [["server_rate","float"],"u","d"]);

// StatServer — 服务器流量统计
patch("app/Models/StatServer.php",
    "updated_at".$chr." => ".$chr."timestamp".$chr,
    ["u","d","server_id","record_at"]);
'

# Fix: online_count 排序 NULL 问题 — COALESCE(online_count, 0)
docker compose exec -T web php -r '
$file = "app/Http/Controllers/V2/Admin/UserController.php";
$f = file_get_contents($file);
$old = "\$builder->orderBy(\$field, \$direction);";
$new = "// online_count may be NULL — treat as 0 so NULLs sort correctly\n            if (\$field === \"online_count\") {\n                \$builder->orderByRaw(\"COALESCE(online_count, 0) \" . \$direction);\n            } else {\n                \$builder->orderBy(\$field, \$direction);\n            }";
if (strpos($f, "COALESCE(online_count") === false && strpos($f, $old) !== false) {
    $f = str_replace($old, $new, $f);
    file_put_contents($file, $f);
    echo "UserController online_count sort patched\n";
} else {
    echo "UserController online_count sort already patched or not found\n";
}
'

echo "[patch] Verifying PG numeric model casts..."
docker cp patch-verify-pgsql-casts.php yue-to-web-1:/www/patch-verify-pgsql-casts.php
docker compose exec -T web php /www/patch-verify-pgsql-casts.php

echo "[patch] Restarting Octane..."
docker compose restart web ws
echo "[patch] Done! All models patched."
