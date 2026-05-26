#!/bin/bash
# patch-pgsql-stability.sh
# PostgreSQL 稳定性 tie-break 小修 —— 保持原主排序，仅追加 id (或 server_id/user_id) secondary orderBy。
# 目的：消除 MySQL InnoDB 默认 clustered-index 顺序下恰好工作、PG heap 下非确定的列表/分页/top-N 抖动。
#
# 固化策略：upgrade.sh 会在 Step 5 (patch-security.sh) 之后调用本脚本（Step 5.5）
# 幂等：每个 patch 用 strpos($src, $to) 做 marker 检查，已打过则 skip
# 不做：schema 变更、relation 全量 orderBy、产品级 refactor（如 route_ids 顺序保留）
#
# 11 处 tie-break patch：
#   B1  V2/Admin/OrderController      latest(created_at) + id desc
#   B2  V2/Admin/TicketController     latest(updated_at) + id desc
#   B3  V1/User/InviteController      CommissionLog desc + id desc
#   B4  V2/Admin/GiftCardController   exportCodes asc + id asc
#   B5a V1/User/GiftCardController    history desc + id desc
#   B5b V2/Admin/GiftCardController   codes paginate desc + id desc
#   B5c V2/Admin/GiftCardController   usages paginate desc + id desc
#   B5d V2/Admin/GiftCardController   templates list sort/created_at + id desc
#   B5e V2/Admin/CouponController     fetch desc + id desc
#   B6a V2/Admin/StatController       top10 server value desc + server_id desc
#   B6b V2/Admin/StatController       top10 user value desc + user_id desc

set -e
cd "$(dirname "$0")"

echo '[patch-pgsql-stability] Applying PG stability tie-break patches...'

docker compose exec -T web php -r '
$q = chr(39);
$patches = [];

// B1: Admin OrderController list — latest(created_at) 无 id tie-break
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/OrderController.php",
  "from"  => "->latest(".$q."created_at".$q.")",
  "to"    => "->latest(".$q."created_at".$q.")->orderBy(".$q."id".$q.", ".$q."desc".$q.")",
  "label" => "B1 OrderController.list",
];

// B2: Admin TicketController list — latest(updated_at) 无 id tie-break
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/TicketController.php",
  "from"  => "->latest(".$q."updated_at".$q.")",
  "to"    => "->latest(".$q."updated_at".$q.")->orderBy(".$q."id".$q.", ".$q."desc".$q.")",
  "label" => "B2 TicketController.list",
];

// B3: User InviteController commission details
$patches[] = [
  "path"  => "app/Http/Controllers/V1/User/InviteController.php",
  "from"  => "->where(".$q."get_amount".$q.", ".$q.">".$q.", 0)\n            ->orderBy(".$q."created_at".$q.", ".$q."DESC".$q.");",
  "to"    => "->where(".$q."get_amount".$q.", ".$q.">".$q.", 0)\n            ->orderBy(".$q."created_at".$q.", ".$q."DESC".$q.")\n            ->orderBy(".$q."id".$q.", ".$q."DESC".$q.");",
  "label" => "B3 InviteController.commission-details",
];

// B4: Admin GiftCardController exportCodes
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/GiftCardController.php",
  "from"  => "->orderBy(".$q."created_at".$q.", ".$q."asc".$q.")\n            ->get([".$q."code".$q."]);",
  "to"    => "->orderBy(".$q."created_at".$q.", ".$q."asc".$q.")\n            ->orderBy(".$q."id".$q.", ".$q."asc".$q.")\n            ->get([".$q."code".$q."]);",
  "label" => "B4 GiftCardController.exportCodes",
];

// B5a: V1 User GiftCardController history paginate
$patches[] = [
  "path"  => "app/Http/Controllers/V1/User/GiftCardController.php",
  "from"  => "->where(".$q."user_id".$q.", \$request->user()->id)\n            ->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->paginate(\$perPage);",
  "to"    => "->where(".$q."user_id".$q.", \$request->user()->id)\n            ->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->orderBy(".$q."id".$q.", ".$q."desc".$q.")\n            ->paginate(\$perPage);",
  "label" => "B5a V1/GiftCardController.history",
];

// B5b: V2 Admin GiftCardController codes paginate
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/GiftCardController.php",
  "from"  => "\$codes = \$query->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")->paginate(\$perPage);",
  "to"    => "\$codes = \$query->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")->orderBy(".$q."id".$q.", ".$q."desc".$q.")->paginate(\$perPage);",
  "label" => "B5b V2/GiftCardController.codes",
];

// B5c: V2 Admin GiftCardController usages paginate
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/GiftCardController.php",
  "from"  => "\$usages = \$query->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")->paginate(\$perPage);",
  "to"    => "\$usages = \$query->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")->orderBy(".$q."id".$q.", ".$q."desc".$q.")->paginate(\$perPage);",
  "label" => "B5c V2/GiftCardController.usages",
];

// B5d: V2 Admin GiftCardController templates list
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/GiftCardController.php",
  "from"  => "\$templates = \$query->orderBy(".$q."sort".$q.", ".$q."asc".$q.")\n            ->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->paginate(\$perPage);",
  "to"    => "\$templates = \$query->orderBy(".$q."sort".$q.", ".$q."asc".$q.")\n            ->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->orderBy(".$q."id".$q.", ".$q."desc".$q.")\n            ->paginate(\$perPage);",
  "label" => "B5d V2/GiftCardController.templates",
];

// B5e: V2 Admin CouponController fetch paginate
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/CouponController.php",
  "from"  => "->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->paginate(\$pageSize, [\"*\"], ".$q."page".$q.", \$current);",
  "to"    => "->orderBy(".$q."created_at".$q.", ".$q."desc".$q.")\n            ->orderBy(".$q."id".$q.", ".$q."desc".$q.")\n            ->paginate(\$pageSize, [\"*\"], ".$q."page".$q.", \$current);",
  "label" => "B5e V2/CouponController.fetch",
];

// B6a: StatController server top-10 — value DESC 无 tie-break
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/StatController.php",
  "from"  => "->groupBy(".$q."server_id".$q.")\n                ->orderBy(".$q."value".$q.", ".$q."DESC".$q.")\n                ->limit(10)",
  "to"    => "->groupBy(".$q."server_id".$q.")\n                ->orderBy(".$q."value".$q.", ".$q."DESC".$q.")\n                ->orderBy(".$q."server_id".$q.", ".$q."DESC".$q.")\n                ->limit(10)",
  "label" => "B6a StatController.top10-server",
];

// B6b: StatController user top-10 — value DESC 无 tie-break
$patches[] = [
  "path"  => "app/Http/Controllers/V2/Admin/StatController.php",
  "from"  => "->groupBy(".$q."user_id".$q.")\n                ->orderBy(".$q."value".$q.", ".$q."DESC".$q.")\n                ->limit(10)",
  "to"    => "->groupBy(".$q."user_id".$q.")\n                ->orderBy(".$q."value".$q.", ".$q."DESC".$q.")\n                ->orderBy(".$q."user_id".$q.", ".$q."DESC".$q.")\n                ->limit(10)",
  "label" => "B6b StatController.top10-user",
];

$applied = 0; $already = 0; $skipped = 0;
foreach ($patches as $p) {
  $f = $p["path"];
  if (!file_exists($f)) {
    echo "[pg-stability] " . $p["label"] . ": file missing, skip\n";
    $skipped++;
    continue;
  }
  $src = file_get_contents($f);
  if (strpos($src, $p["to"]) !== false) {
    echo "[pg-stability] " . $p["label"] . ": already patched\n";
    $already++;
    continue;
  }
  if (strpos($src, $p["from"]) === false) {
    echo "[pg-stability] " . $p["label"] . ": anchor not found, skip\n";
    $skipped++;
    continue;
  }
  $count = substr_count($src, $p["from"]);
  $new = str_replace($p["from"], $p["to"], $src);
  file_put_contents($f, $new);
  echo "[pg-stability] " . $p["label"] . ": patched (" . $count . " site)\n";
  $applied++;
}

echo "[pg-stability] Summary: applied=" . $applied . " already_patched=" . $already . " skipped=" . $skipped . "\n";
'

echo '[patch-pgsql-stability] Done. (Upgrade.sh Step 6 will restart web/ws/horizon; for standalone use: docker compose restart web ws horizon)'

# B7: Stat model numeric casts — PostgreSQL may hydrate integer columns as strings.
# Keep chart/API daily values numeric so frontend chart axes do not treat them as categories/strings.
echo '[pg-stability] B7 Stat model numeric casts...'
docker compose exec -T web php -r '
$f = "app/Models/Stat.php";
if (!file_exists($f)) { echo "[pg-stability] B7 Stat.php missing, skip\n"; exit; }
$s = file_get_contents($f);
$q = chr(39);
$marker = $q . "paid_total" . $q . " => " . $q . "integer" . $q;
if (strpos($s, $marker) !== false) { echo "[pg-stability] B7 Stat.php numeric casts already patched\n"; exit; }
$needle = "        " . $q . "updated_at" . $q . " => " . $q . "timestamp" . $q;
$replace = $needle . ",\n        " . $q . "record_at" . $q . " => " . $q . "integer" . $q . ",\n        " . $q . "paid_total" . $q . " => " . $q . "integer" . $q . ",\n        " . $q . "paid_count" . $q . " => " . $q . "integer" . $q . ",\n        " . $q . "commission_total" . $q . " => " . $q . "integer" . $q . ",\n        " . $q . "commission_count" . $q . " => " . $q . "integer" . $q;
if (strpos($s, $needle) === false) { echo "[pg-stability] B7 Stat.php anchor missing, skip\n"; exit(1); }
file_put_contents($f, str_replace($needle, $replace, $s));
echo "[pg-stability] B7 Stat.php numeric casts patched\n";
'
