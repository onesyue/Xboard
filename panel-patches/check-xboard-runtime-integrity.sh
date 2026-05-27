#!/bin/bash
# Read-only integrity gate for Yue.to XBoard runtime patches and derived assets.
set -Eeuo pipefail
cd /home/xboard/yue-to

PANEL_PUBLIC_URL=${PANEL_PUBLIC_URL:-https://my.yue.to}
PANEL_HOST=${PANEL_HOST:-my.yue.to}
APP_SERVICES=${APP_SERVICES:-web ws horizon}
FAIL=0

ok() { printf '[ok] %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*" >&2; FAIL=1; }
warn() { printf '[warn] %s\n' "$*" >&2; }

cid_of() { docker compose ps -q "$1" 2>/dev/null | head -1; }
service_running() {
  local cid
  cid=$(cid_of "$1")
  [ -n "$cid" ] && [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || true)" = "true" ]
}

check_service() {
  local svc="$1" cid="$2"
  docker exec "$cid" sh -lc '
    set -eu
    grep -Eq "total_amount.*integer" /www/app/Models/Order.php
    grep -Eq "paid_total.*integer" /www/app/Models/Stat.php
    grep -Eq "app_sign_streak.*integer" /www/app/Models/User.php
    grep -Eq "sell.*boolean" /www/app/Models/Plan.php
    grep -Eq "last_reply_user_id.*integer" /www/app/Models/Ticket.php
    grep -Fq "[PG ILIKE patch]" /www/app/Traits/QueryOperators.php
    grep -Fq "isValidFieldName" /www/app/Traits/QueryOperators.php
    grep -Fq "[Patch BAL]" /www/app/Services/UserService.php
    grep -Fq "upload-bandwidth" /www/app/Protocols/Loon.php
    grep -Fq "xb_server_id" /www/app/Protocols/ClashMeta.php
    grep -Fq "[is_me compat]" /www/app/Http/Controllers/V2/Admin/TicketController.php
    grep -Eq "config.*array" /www/app/Models/Plugin.php
    grep -Fq "clientVersion !== null" /www/app/Support/AbstractProtocol.php
	    grep -Fq "pgsql" /www/app/Console/Commands/BackupDatabase.php
	    grep -Fq "is_array" /www/app/Services/Plugin/PluginManager.php
	    grep -Fq "is_array" /www/app/Services/Plugin/PluginConfigService.php
	    grep -Fq "is_array" /www/app/Traits/HasPluginConfig.php
	    php -l /www/app/Protocols/Loon.php >/dev/null
	  ' >/dev/null 2>&1 && ok "$svc runtime markers" || fail "$svc runtime markers missing"
}

for svc in $APP_SERVICES; do
  if service_running "$svc"; then
    check_service "$svc" "$(cid_of "$svc")"
  else
    fail "$svc is not running"
  fi
done

theme_mounts=$(docker compose config 2>/dev/null | grep -c 'target: /www/storage/theme' || true)
if [ "${theme_mounts:-0}" -ge 3 ]; then
  ok "compose storage/theme mounts ($theme_mounts)"
else
  fail "compose storage/theme mounts missing ($theme_mounts/3)"
fi

if service_running web; then
  docker cp patch-verify-pgsql-casts.php yue-to-web-1:/www/patch-verify-pgsql-casts.php >/dev/null
  if docker compose exec -T web php /www/patch-verify-pgsql-casts.php >/tmp/yue-pg-casts-check.out 2>&1; then
    ok "PG numeric casts"
  else
    cat /tmp/yue-pg-casts-check.out >&2
    fail "PG numeric casts"
  fi

  docker compose exec -T web test -s /www/public/assets/u/app-core.js >/dev/null 2>&1 \
    && ok "Portal app-core asset" || fail "Portal app-core asset missing"
  docker compose exec -T web test -s /www/public/assets/u/ux-state.js >/dev/null 2>&1 \
    && ok "Portal ux-state asset" || fail "Portal ux-state asset missing"
  docker compose exec -T web grep -Fq "portal-auth-contrast-fix" /www/storage/theme/Portal/dashboard.blade.php >/dev/null 2>&1 \
    && ok "Portal auth hardening" || fail "Portal auth hardening missing"

  # 2026-05-27: /tmp 是 tmpfs (compose 里 size=512m)，docker compose cp 不可靠 → 改 storage/ 持久路径
  TPL_CHECK_DIR=/www/storage/framework/cache/yueops-tpl-check
  docker compose exec -T web rm -rf "$TPL_CHECK_DIR" >/dev/null
  docker compose exec -T web mkdir -p "$TPL_CHECK_DIR" >/dev/null
  for f in xboard-templates/*.{json,yaml,conf}; do
    [ -f "$f" ] || continue
    docker compose cp "$f" "web:$TPL_CHECK_DIR/$(basename "$f")" >/dev/null
  done
  if docker compose exec -T web php <<PHP >/tmp/yue-template-check.out 2>&1
<?php
require "/www/vendor/autoload.php";
\$app = require "/www/bootstrap/app.php";
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
\$plan = [
  'singbox' => 'singbox.json',
  'clashmeta' => 'clashmeta.yaml',
  'clash' => 'clash.yaml',
  'stash' => 'stash.yaml',
  'surge' => 'surge.conf',
  'surfboard' => 'surfboard.conf',
];
\$bad = [];
foreach (\$plan as \$name => \$file) {
  \$tmpPath = "${TPL_CHECK_DIR}/\$file";
  if (!is_file(\$tmpPath)) { \$bad[] = "\$name:tmp-missing"; continue; }
  \$db = DB::table('v2_subscribe_templates')->where('name', \$name)->value('content');
  if (\$db === null) { \$bad[] = "\$name:db-missing"; continue; }
  if (md5_file(\$tmpPath) !== md5(\$db)) { \$bad[] = "\$name:md5-drift"; }
}
if (\$bad) { echo implode("\\n", \$bad), "\\n"; exit(1); }
echo "templates OK\\n";
PHP
  then
    ok "subscribe templates DB sync"
  else
    cat /tmp/yue-template-check.out >&2
    fail "subscribe templates DB drift"
  fi
fi

for src in xboard-nginx/*.conf; do
  [ -f "$src" ] || continue
  dest="/home/nginx/conf.d/$(basename "$src")"
  if [ -f "$dest" ] && cmp -s "$src" "$dest"; then
    ok "nginx $(basename "$src") sync"
  else
    fail "nginx $(basename "$src") drift"
  fi
done

docker exec nginx nginx -t >/tmp/yue-nginx-check.out 2>&1 && ok "nginx syntax" || { cat /tmp/yue-nginx-check.out >&2; fail "nginx syntax"; }

systemctl is-enabled yue-to-runtime-patches.timer >/dev/null 2>&1 && ok "runtime guard timer enabled" || fail "runtime guard timer not enabled"
systemctl is-active yue-to-runtime-patches.timer >/dev/null 2>&1 && ok "runtime guard timer active" || fail "runtime guard timer not active"

check_url() {
  local label="$1" url="$2" min_bytes="$3" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 -o /dev/null -w '%{http_code} %{size_download}' "$url" || true)
  code=${out%% *}; size=${out##* }
  if [ "$code" = "200" ] && [ "${size:-0}" -ge "$min_bytes" ]; then
    ok "$label HTTP $code $size"
  else
    fail "$label HTTP $out"
  fi
}

check_origin_url() {
  local label="$1" path="$2" min_bytes="$3" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 \
    --resolve "$PANEL_HOST:443:127.0.0.1" \
    -H "CF-Connecting-IP: 127.0.0.1" \
    -A "Mozilla/5.0" \
    -o /dev/null -w '%{http_code} %{size_download}' \
    "https://$PANEL_HOST$path" || true)
  code=${out%% *}; size=${out##* }
  if [ "$code" = "200" ] && [ "${size:-0}" -ge "$min_bytes" ]; then
    ok "$label HTTP $code $size"
  else
    fail "$label HTTP $out"
  fi
}

check_public_url() {
  local label="$1" url="$2" min_bytes="$3" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 \
    -A "Mozilla/5.0" \
    -o /dev/null -w '%{http_code} %{size_download}' "$url" || true)
  code=${out%% *}; size=${out##* }
  if [ "$code" = "200" ] && [ "${size:-0}" -ge "$min_bytes" ]; then
    ok "$label HTTP $code $size"
  elif [ "${PUBLIC_CHECK_REQUIRED:-0}" = "1" ]; then
    fail "$label HTTP $out"
  else
    warn "$label public HTTP $out"
  fi
}

check_url "backend login" "http://127.0.0.1:8001/" 1000
check_origin_url "origin login" "/" 1000
check_origin_url "origin app-core" "/assets/u/app-core.js" 100000
check_origin_url "origin favicon" "/favicon.ico" 1000
check_public_url "public login" "$PANEL_PUBLIC_URL/" 1000

if [ "$FAIL" = "0" ]; then
  echo "[ok] XBoard runtime integrity gate passed"
else
  echo "[FAIL] XBoard runtime integrity gate failed" >&2
fi
exit "$FAIL"
