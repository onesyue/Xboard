#!/usr/bin/env bash
# patch-subscribe-templates.sh
#
# 把 git 仓库 xboard-templates/ 下的 6 个订阅模板同步到 XBoard panel DB
# (`v2_subscribe_templates` row)。**git 是事实源**，DB 是派生缓存。
#
# 行为：
#   - 计算每个 template 文件 md5 vs DB 当前 row md5(content)
#   - 不一致：备份当前 row 到 v2_subscribe_templates_bak_<name>_<ts>，
#     然后 UPDATE 写入新内容，更新 updated_at = NOW()
#   - 一致：跳过 (no-op)
#   - 写入后 forget Redis 缓存
#
# 幂等：第二次跑全部 [skip]。
# 不依赖 panel 主机的 psql；通过 docker compose exec web php 走容器内 PHP +
# Laravel Eloquent (DB 配置由 .env 提供)。
#
# 用法（panel /home/xboard/yue-to/）：
#   bash patch-subscribe-templates.sh
#
set -euo pipefail
cd /home/xboard/yue-to

TEMPLATE_DIR="${TEMPLATE_DIR:-/home/xboard/yue-to/xboard-templates}"
[ -d "$TEMPLATE_DIR" ] || { echo "FATAL: $TEMPLATE_DIR not found" >&2; exit 1; }

declare -A TEMPLATE_FILE=(
  [singbox]="singbox.json"
  [clashmeta]="clashmeta.yaml"
  [clash]="clash.yaml"
  [stash]="stash.yaml"
  [surge]="surge.conf"
  [surfboard]="surfboard.conf"
)

# Validate file shapes
HAS_PYYAML=0
python3 -c 'import yaml' 2>/dev/null && HAS_PYYAML=1
[ $HAS_PYYAML -eq 0 ] && echo "  [warn] PyYAML not installed — yaml shape check skipped"

for name in "${!TEMPLATE_FILE[@]}"; do
  file="$TEMPLATE_DIR/${TEMPLATE_FILE[$name]}"
  [ -f "$file" ] || { echo "FATAL: $file missing" >&2; exit 1; }
  case "$file" in
    *.json)
      python3 -c "import json,sys;json.load(open(sys.argv[1]))" "$file" 2>/dev/null \
        || { echo "FATAL: $file is not valid JSON" >&2; exit 1; } ;;
    *.yaml)
      if [ $HAS_PYYAML -eq 1 ]; then
        python3 -c "import yaml,sys;yaml.safe_load(open(sys.argv[1]))" "$file" 2>/dev/null \
          || { echo "FATAL: $file is not valid YAML" >&2; exit 1; }
      else
        grep -q '^proxy-groups:' "$file" && grep -q '^rules:' "$file" \
          || { echo "FATAL: $file lacks clash-yaml shape" >&2; exit 1; }
      fi ;;
    *.conf)
      head -1 "$file" | grep -q '^#!MANAGED-CONFIG' || grep -q '^\[General\]' "$file" \
        || { echo "FATAL: $file lacks surge .conf shape" >&2; exit 1; } ;;
  esac
done

ts=$(date +%Y%m%d%H%M%S)

# Build a single PHP script that handles all sync logic atomically per row.
# Files are copied into the container once at /tmp/xboard-templates-sync/.
docker compose exec -T web rm -rf /tmp/xboard-templates-sync
docker compose exec -T web mkdir -p /tmp/xboard-templates-sync
# NOTE 2026-05-26: docker cp (and docker compose cp) cannot write into a
# tmpfs mount in docker engine 29.x. Use stdin redirect into docker exec
# as the only reliable cross-version workaround. See feedback memory.
for name in "${!TEMPLATE_FILE[@]}"; do
  cat "$TEMPLATE_DIR/${TEMPLATE_FILE[$name]}" | docker compose exec -T web sh -c "cat > /tmp/xboard-templates-sync/${TEMPLATE_FILE[$name]}"
done

cat > /tmp/sync-templates.php <<'PHP'
<?php
$ts = $argv[1] ?? date('YmdHis');
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plan = [
  'singbox'   => '/tmp/xboard-templates-sync/singbox.json',
  'clashmeta' => '/tmp/xboard-templates-sync/clashmeta.yaml',
  'clash'     => '/tmp/xboard-templates-sync/clash.yaml',
  'stash'     => '/tmp/xboard-templates-sync/stash.yaml',
  'surge'     => '/tmp/xboard-templates-sync/surge.conf',
  'surfboard' => '/tmp/xboard-templates-sync/surfboard.conf',
];

$db = app('db');
$cache = app('cache');
$changed = [];
$exitCode = 0;

foreach ($plan as $name => $path) {
  if (!is_file($path)) {
    fwrite(STDERR, "FATAL: $path missing inside container\n");
    $exitCode = 1; continue;
  }
  $fileContent = file_get_contents($path);
  $fileMd5 = md5($fileContent);
  $row = $db->table('v2_subscribe_templates')->where('name', $name)->first();
  if (!$row) {
    fwrite(STDERR, "FATAL: row name=$name missing in DB\n");
    $exitCode = 1; continue;
  }
  $dbMd5 = md5($row->content);
  if ($fileMd5 === $dbMd5) {
    echo "  [skip] $name in sync ($fileMd5)\n";
    continue;
  }
  $bak = "v2_subscribe_templates_bak_{$name}_{$ts}";
  $db->statement("CREATE TABLE IF NOT EXISTS {$bak} AS SELECT * FROM v2_subscribe_templates WHERE name = ?", [$name]);
  $db->table('v2_subscribe_templates')->where('name', $name)->update([
    'content'    => $fileContent,
    'updated_at' => now(),
  ]);
  $verifyMd5 = md5($db->table('v2_subscribe_templates')->where('name', $name)->value('content'));
  if ($verifyMd5 !== $fileMd5) {
    fwrite(STDERR, "FATAL: $name post-update md5 mismatch (file=$fileMd5 db=$verifyMd5)\n");
    $exitCode = 1; continue;
  }
  $cache->store('redis')->forget("subscribe_template:{$name}");
  echo "  [sync] $name $dbMd5 -> $fileMd5 (backup: $bak)\n";
  $changed[] = $name;
}

if ($changed) {
  echo "[ok] templates sync done. Changed: " . implode(', ', $changed) . "; backup_ts=$ts\n";
} else {
  echo "[ok] all 6 templates already in sync\n";
}
exit($exitCode);
PHP

cat /tmp/sync-templates.php | docker compose exec -T web sh -c "cat > /tmp/sync-templates.php"
docker compose exec -T web php /tmp/sync-templates.php "$ts"
