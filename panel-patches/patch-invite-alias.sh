#!/usr/bin/env bash
# patch-invite-alias.sh
#
# InviteAlias 插件固化 —— 升级 XBoard 时自动恢复
#
# 流程：
#   1. xboard-plugins/InviteAlias/  → /home/xboard/yue-to/plugins/InviteAlias/
#   2. 跑 plugin migrations（路径 plugins/InviteAlias/database/migrations）
#   3. 确保 v2_plugins(code=invite_alias) 存在 + enabled
#   4. 自动生成 32-hex internal_token（缺失时；有则保留）
#   5. 发布 widget 资源到 /www/public/assets/u/invite-alias-widget.js
#   6. flush admin_settings cache + restart web/ws
#   7. route 注册数验证（≥14）
#
# 触发：
#   - upgrade.sh Step 5.66 自动调用
#   - 独立运行：cd /home/xboard/yue-to && bash patch-invite-alias.sh
#
# 幂等：rsync 检测变更；migrate --force 已应用跳过；plugin row firstOrCreate；token 仅缺失生成。
set -euo pipefail
cd /home/xboard/yue-to

PLUGIN=InviteAlias
SRC="/home/xboard/yue-to/xboard-plugins/$PLUGIN"
DST="/home/xboard/yue-to/plugins/$PLUGIN"

if [ ! -d "$SRC" ]; then
  echo "[InviteAlias] FATAL: $SRC missing"
  echo "  先在 bastion 跑：bash /opt/yueops/scripts/sync-xboard-patches.sh"
  exit 1
fi

echo "[1/7] sync $SRC/ → $DST/"
if command -v rsync >/dev/null 2>&1; then
  mkdir -p "$DST"
  rsync -a --delete \
    --exclude='.git*' \
    --exclude='*.swp' \
    --exclude='node_modules' \
    --exclude='.DS_Store' \
    "$SRC/" "$DST/"
else
  echo "  rsync not found; using tar fallback"
  rm -rf "$DST"
  mkdir -p "$DST"
  (
    cd "$SRC"
    tar --exclude='.git*' --exclude='*.swp' --exclude='node_modules' --exclude='.DS_Store' -cf - .
  ) | (
    cd "$DST"
    tar -xf -
  )
fi

echo "[2/7] run plugin migrations"
docker compose exec -T web php /www/artisan migrate \
  --path=plugins/$PLUGIN/database/migrations \
  --force || {
    echo "  ⚠ migrate exit non-zero (existing tables may be ok); continuing"
  }

echo "[3/7] ensure v2_plugins row enabled"
docker compose exec -T web php /www/artisan tinker --execute='
  $cfgPath = "/www/plugins/InviteAlias/config.json";
  $meta = file_exists($cfgPath) ? (json_decode(file_get_contents($cfgPath), true) ?: []) : [];
  $code = $meta["code"] ?? "invite_alias";
  $p = \App\Models\Plugin::firstOrNew(["code"=>$code]);
  $p->name = $meta["name"] ?? "InviteAlias";
  $p->type = $meta["type"] ?? "feature";
  $p->version = $meta["version"] ?? "1.0.0";
  $p->is_enabled = true;
  if ($p->config === null || $p->config === "" || $p->config === "[]") { $p->config = "{}"; }
  $p->save();
  echo "  plugin row code=$code enabled=true version=" . $p->version . "\n";
'

echo "[4/7] ensure internal_token"
docker compose exec -T web php /www/artisan tinker --execute='
  $p = \App\Models\Plugin::where("code","invite_alias")->first();
  if (!$p) { echo "  FATAL plugin row missing\n"; exit(1); }
  $cfg = is_string($p->config) ? (json_decode($p->config, true) ?: []) : (array)$p->config;
  $token = $cfg["internal_token"] ?? "";
  if (strlen($token) < 32) {
    $token = bin2hex(random_bytes(16));
    $cfg["internal_token"] = $token;
    $p->config = json_encode($cfg);
    $p->save();
    echo "  generated internal_token (len=32). Sync to yue bot .env: INVITE_ALIAS_INTERNAL_TOKEN=$token\n";
  } else {
    echo "  internal_token present (len=" . strlen($token) . ")\n";
  }
'

echo "[5/7] publish widget alias"
docker exec yue-to-web-1 sh -s <<'REMOTE'
set -eu
SRC=/www/plugins/InviteAlias/assets/invite-alias-widget.js
DST=/www/public/assets/u/invite-alias-widget.js
if [ -f "$SRC" ]; then
  mkdir -p $(dirname "$DST")
  cp "$SRC" "$DST"
  chown www:www "$DST" 2>/dev/null || true
  echo "  widget published: $DST ($(wc -c < $DST) bytes)"
else
  echo "  skip — $SRC not present"
fi
REMOTE

echo "[6/7] flush admin_settings cache + restart web/ws"
docker compose exec -T web php /www/artisan tinker --execute='
  \Illuminate\Support\Facades\Cache::forget("admin_settings");
  echo "  admin_settings cache flushed\n";
' || true
docker compose exec -T web php /www/artisan cache:clear || true
docker compose restart web ws

echo "[7/7] verify route registration (sleep 5s 等 Octane 重启)"
sleep 5
ROUTES=$(docker compose exec -T web php /www/artisan route:list 2>/dev/null | grep -c 'invite-alias' || true)
if [ "${ROUTES:-0}" -ge 14 ]; then
  echo "  ✓ $ROUTES invite-alias routes registered"
else
  echo "  ⚠ only $ROUTES invite-alias routes detected (expected ≥14)"
  echo "    检查：docker compose exec web php artisan route:list | grep invite-alias"
fi

echo "=== InviteAlias patch complete ==="
