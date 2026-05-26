#!/bin/bash
# Xboard 一键升级脚本
# 用法: cd /home/xboard/yue-to && bash upgrade.sh
#       SKIP_CHECK=1 bash upgrade.sh   # 跳过 pre-upgrade-check
#       SELF_TEST=1 bash upgrade.sh    # 不升级，只验证升级入口依赖 + 当前健康闸门
#
# 流程:
#   0. pre-upgrade-check.sh  — 阻塞升级：上游 CI 红/破坏性 migration/patch 锚点冲突
#   0.5 强制备份 .env/compose/patch/模板 + PostgreSQL dump + 当前 image inspect
#   1. pull production image (ghcr.io/onesyue/xboard:latest)
#   2. recreate 容器（redis 先起并等待 PONG，再起 web/ws/horizon）
#   3. artisan migrate
#   4-5.65b 源码补丁       — onesyue/xboard 镜像已内置；仅 SOURCE_PATCH_MODE=always 时重放旧 runtime patch
#   5.7 patch-singbox-placeholder.sh — verify upstream sing-box include/exclude support + template hygiene
#                                      (no PHP patch on ee2c12ed+; template uses include/exclude, not {all}/includes)
#   5.8 patch-subscribe-templates.sh — 用 git 仓库 xboard-templates/*.{yaml,json,conf} 同步 6 个 DB 模板
#                                      (DB 是缓存，git 是事实源；防上游 reseed/reset 重蹈兼容性翻车)
#   5.9 patch-xboard-nginx.sh    — 用 git 仓库 xboard-nginx/*.conf 同步 panel 主机 /home/nginx/conf.d/
#                                  (host 文件，git 是事实源；nginx -t 失败自动 rollback)
#   5.66 patch-invite-alias.sh   — 重放 InviteAlias 插件（plugin row + token + widget alias）
#   5.67 patch-subnode-mirror-trigger.sh — 强装 v2_server parent_id 子节点 mirror PG trigger
#                                          + 跑一次级联同步把上游 migration 期间漂移的子节点拉回
#   6. reset-disconnect      — onesyue/xboard 镜像已内置；仅 SOURCE_PATCH_MODE=always 时重放旧 runtime patch
#   7. harden-xboard-portal-theme.sh — 重放 Portal 去特征主题 wrapper + assets alias
#   8. post-upgrade health gate — HTTP/assets/Redis/sing-box/smoke/log sweep 全部过线
#
# 部署态 patch 幂等。同步源 → 生产: bash /opt/yueops/scripts/sync-xboard-patches.sh

set -Eeuo pipefail
cd /home/xboard/yue-to

IMAGE=${IMAGE:-ghcr.io/onesyue/xboard:latest}
SOURCE_PATCH_MODE=${SOURCE_PATCH_MODE:-auto} # auto | always | never
BACKUP_ROOT=${BACKUP_ROOT:-/var/backups/xboard-upgrade}
PG_DUMP_IMAGE=${PG_DUMP_IMAGE:-postgres:17-alpine}
PANEL_PUBLIC_URL=${PANEL_PUBLIC_URL:-https://my.yue.to}
PANEL_HOST=${PANEL_HOST:-my.yue.to}
POST_CHECK_SLEEP=${POST_CHECK_SLEEP:-20}
SMOKE_TIMEOUT=${SMOKE_TIMEOUT:-180}
LOCK_FILE=${LOCK_FILE:-/tmp/xboard-upgrade.lock}
BACKUP_DIR=""

fail() {
  echo "FATAL: $*" >&2
  exit 1
}

on_error() {
  local rc=$?
  echo "FATAL: upgrade failed at line $1 (rc=$rc)." >&2
  if [ -n "${BACKUP_DIR:-}" ]; then
    echo "Backup: $BACKUP_DIR" >&2
  fi
  exit "$rc"
}
trap 'on_error $LINENO' ERR

get_env() {
  local key="$1" value
  value=$(sed -n -E "s/^${key}=//p" .env 2>/dev/null | tail -n 1 | tr -d '\r')
  value="${value%\"}"; value="${value#\"}"
  value="${value%\'}"; value="${value#\'}"
  printf '%s' "$value"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required"
}

current_xboard_images() {
  docker compose config 2>/dev/null | awk '/image: .*xboard/ {print $2}' | sort -u
}

source_patches_needed() {
  case "$SOURCE_PATCH_MODE" in
    always) return 0 ;;
    never) return 1 ;;
    auto)
      if current_xboard_images | grep -q '^ghcr\.io/onesyue/xboard:'; then
        return 1
      fi
      return 0
      ;;
    *) fail "invalid SOURCE_PATCH_MODE=$SOURCE_PATCH_MODE (expected auto|always|never)" ;;
  esac
}

image_revision() {
  docker image inspect "$IMAGE" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' 2>/dev/null || true
}

save_image_state() {
  local suffix="$1"
  [ -n "${BACKUP_DIR:-}" ] || return 0
  docker image inspect "$IMAGE" > "$BACKUP_DIR/image-${suffix}.inspect.json" 2>/dev/null || true
  {
    echo "revision=$(image_revision)"
    docker image inspect "$IMAGE" --format 'repo_digests={{json .RepoDigests}}' 2>/dev/null || true
  } > "$BACKUP_DIR/image-${suffix}.txt"
}

snapshot_custom_db_state() {
  local suffix="$1" settings_out templates_out
  [ -n "${BACKUP_DIR:-}" ] || return 0

  settings_out="$BACKUP_DIR/custom-settings.${suffix}.json"
  templates_out="$BACKUP_DIR/subscribe-templates.${suffix}.json"

  docker compose exec -T web php > "$settings_out" <<'PHP'
<?php
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$patterns = [
    '%theme%', '%footer%', '%html%', '%custom%', '%customer%', '%service%',
    '%logo%', '%app_%', '%tos%', '%telegram%url%', '%telegram%link%',
    '%invite%'
];
$where = implode(' or ', array_fill(0, count($patterns), 'name ilike ?'));
$sensitive = "(key|token|secret|password|private|cert|mail|smtp|pay|stripe|epay|mch)";

$rows = DB::table('v2_settings')
    ->select('group', 'type', 'name', 'value')
    ->whereRaw("($where)", $patterns)
    ->whereRaw("name !~* ?", [$sensitive])
    ->orderBy('name')
    ->get()
    ->map(fn($r) => [
        'group' => $r->group,
        'type' => $r->type,
        'name' => $r->name,
        'value' => $r->value,
        'sha256' => hash('sha256', (string) $r->value),
    ])
    ->values();

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
PHP

  docker compose exec -T web php > "$templates_out" <<'PHP'
<?php
require "/www/vendor/autoload.php";
$app = require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('v2_subscribe_templates')
    ->select('name', 'content')
    ->orderBy('name')
    ->get()
    ->map(fn($r) => [
        'name' => $r->name,
        'bytes' => strlen((string) $r->content),
        'md5' => md5((string) $r->content),
    ])
    ->values();

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
PHP

  sha256sum "$settings_out" > "$settings_out.sha256"
  sha256sum "$templates_out" > "$templates_out.sha256"
}

assert_custom_settings_unchanged() {
  local before after
  [ -n "${BACKUP_DIR:-}" ] || return 0
  before="$BACKUP_DIR/custom-settings.before.json"
  after="$BACKUP_DIR/custom-settings.after.json"
  [ -f "$before" ] && [ -f "$after" ] || return 0

  if ! cmp -s "$before" "$after"; then
    diff -u "$before" "$after" > "$BACKUP_DIR/custom-settings.diff" || true
    fail "custom/theme/customer/footer settings changed unexpectedly; see $BACKUP_DIR/custom-settings.diff"
  fi
  echo "[ok] custom/theme/customer/footer settings unchanged"
}

make_backup() {
  local ts db_host db_port db_name db_user db_pass dump_name
  ts=$(date +%Y%m%d_%H%M%S)
  BACKUP_DIR="$BACKUP_ROOT/$ts"
  mkdir -p "$BACKUP_DIR"
  chmod 700 "$BACKUP_ROOT" "$BACKUP_DIR"

  echo "=== Step 0.5: Backup current deploy state ==="
  save_image_state "before"
  docker compose ps > "$BACKUP_DIR/compose-ps.before.txt" 2>&1 || true
  docker compose config > "$BACKUP_DIR/compose-config.before.yaml" 2>&1 || true

  tar --ignore-failed-read -czf "$BACKUP_DIR/xboard-config-and-patches.tgz" \
    .env compose.yaml compose.yml docker-compose.yml \
    patch-*.sh patch-*.php pre-upgrade-check.sh upgrade.sh harden-xboard-portal-theme.sh \
    restore-xboard-runtime-patches.sh check-xboard-runtime-integrity.sh install-xboard-runtime-guard.sh sync-xboard-patches.sh \
    xboard-templates xboard-nginx \
    > "$BACKUP_DIR/tar.stdout" 2> "$BACKUP_DIR/tar.stderr" || true

  db_host=$(get_env DB_HOST)
  db_port=$(get_env DB_PORT)
  db_name=$(get_env DB_DATABASE)
  db_user=$(get_env DB_USERNAME)
  db_pass=$(get_env DB_PASSWORD)
  db_port=${db_port:-5432}

  [ -n "$db_host" ] || fail "DB_HOST missing in .env; refusing to upgrade without DB backup"
  [ -n "$db_name" ] || fail "DB_DATABASE missing in .env; refusing to upgrade without DB backup"
  [ -n "$db_user" ] || fail "DB_USERNAME missing in .env; refusing to upgrade without DB backup"
  [ -n "$db_pass" ] || fail "DB_PASSWORD missing in .env; refusing to upgrade without DB backup"

  dump_name="${db_name}-preupgrade.dump"
  PGPASSWORD="$db_pass" docker run --rm --network host \
    -e PGPASSWORD \
    -v "$BACKUP_DIR:/backup" \
    "$PG_DUMP_IMAGE" \
    pg_dump -h "$db_host" -p "$db_port" -U "$db_user" -Fc "$db_name" -f "/backup/$dump_name"

  snapshot_custom_db_state "before"
  sha256sum "$BACKUP_DIR"/* > "$BACKUP_DIR/SHA256SUMS" 2>/dev/null || true
  echo "[ok] backup ready: $BACKUP_DIR"
}

wait_for_redis() {
  echo "Waiting for redis PONG..."
  for _ in $(seq 1 60); do
    if docker compose exec -T redis redis-cli ping 2>/dev/null | grep -q '^PONG$'; then
      echo "[ok] redis ready"
      return 0
    fi
    sleep 1
  done
  fail "redis did not become ready within 60s"
}

check_url() {
  local url="$1" min_bytes="$2" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 -o /dev/null -w '%{http_code} %{size_download}' "$url")
  code=${out%% *}
  size=${out##* }
  echo "  $code $size $url"
  [ "$code" = "200" ] || fail "HTTP check failed for $url (code=$code)"
  [ "$size" -ge "$min_bytes" ] || fail "HTTP check too small for $url (bytes=$size, want>=$min_bytes)"
}

check_origin_url() {
  local path="$1" min_bytes="$2" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 \
    --resolve "$PANEL_HOST:443:127.0.0.1" \
    -H "CF-Connecting-IP: 127.0.0.1" \
    -A "Mozilla/5.0" \
    -o /dev/null -w '%{http_code} %{size_download}' \
    "https://$PANEL_HOST$path")
  code=${out%% *}
  size=${out##* }
  echo "  origin $code $size https://$PANEL_HOST$path"
  [ "$code" = "200" ] || fail "origin HTTP check failed for $path (code=$code)"
  [ "$size" -ge "$min_bytes" ] || fail "origin HTTP check too small for $path (bytes=$size, want>=$min_bytes)"
}

check_public_url() {
  local url="$1" min_bytes="$2" out code size
  out=$(curl --http1.1 -ksS --connect-timeout 5 --max-time 20 \
    -A "Mozilla/5.0" \
    -o /dev/null -w '%{http_code} %{size_download}' "$url" || true)
  code=${out%% *}
  size=${out##* }
  echo "  public $code $size $url"
  if [ "$code" != "200" ] || [ "${size:-0}" -lt "$min_bytes" ]; then
    if [ "${PUBLIC_CHECK_REQUIRED:-0}" = "1" ]; then
      fail "public HTTP check failed for $url ($out)"
    fi
    echo "  [warn] public HTTP check failed for $url ($out)"
  fi
}

assert_service_running() {
  local svc="$1"
  docker compose ps "$svc" | grep -q 'Up' || fail "service $svc is not Up"
}

assert_redis_runtime() {
  local redis_host redis_port save dir stop policy
  redis_host=$(get_env REDIS_HOST)
  redis_port=$(get_env REDIS_PORT)
  [ "$redis_host" = "redis" ] || fail "REDIS_HOST must be redis, got '$redis_host'"
  [ "${redis_port:-6379}" = "6379" ] || fail "REDIS_PORT must be 6379, got '$redis_port'"

  save=$(docker compose exec -T redis redis-cli CONFIG GET save | awk 'NR==2{print}')
  dir=$(docker compose exec -T redis redis-cli CONFIG GET dir | awk 'NR==2{print}')
  stop=$(docker compose exec -T redis redis-cli CONFIG GET stop-writes-on-bgsave-error | awk 'NR==2{print}')
  policy=$(docker compose exec -T redis redis-cli CONFIG GET maxmemory-policy | awk 'NR==2{print}')

  [ -z "$save" ] || fail "redis save must be disabled, got '$save'"
  [ "$dir" = "/tmp" ] || fail "redis dir must be /tmp, got '$dir'"
  [ "$stop" = "no" ] || fail "redis stop-writes-on-bgsave-error must be no, got '$stop'"
  [ "$policy" = "allkeys-lru" ] || fail "redis maxmemory-policy must be allkeys-lru, got '$policy'"
  if docker compose config 2>/dev/null | grep -q '\.docker/.data/redis'; then
    fail "legacy redis /data bind mount still exists in compose.yaml"
  fi
  echo "[ok] redis runtime is TCP/no-RDB/allkeys-lru"
}

post_upgrade_check() {
  local patched_count n svc

  echo '=== Step 8: Post-upgrade health gate ==='
  save_image_state "after"
  docker compose ps > "${BACKUP_DIR:-/tmp}/compose-ps.after.txt" 2>&1 || true

  assert_service_running redis
  assert_service_running web
  assert_service_running ws
  assert_service_running horizon
  wait_for_redis
  assert_redis_runtime

  docker compose exec -T web test -s /www/public/assets/u/app-core.js \
    || fail "Portal /assets/u/app-core.js missing inside web container"
  patched_count=$(docker compose exec -T web sh -lc 'grep -c applyOutboundPlaceholders /www/app/Protocols/SingBox.php || true' | tr -d '[:space:]')
  [ "$patched_count" = "0" ] || fail "local SingBox.php placeholder patch still present"
  bash patch-singbox-placeholder.sh

	  echo "HTTP checks:"
	  check_url "http://127.0.0.1:8001/" 1000
	  check_url "http://127.0.0.1:8001/api/v1/guest/comm/config" 100
	  check_origin_url "/" 1000
	  check_origin_url "/assets/u/app-core.js" 100000
	  check_origin_url "/api/v1/guest/comm/config" 100
	  check_public_url "$PANEL_PUBLIC_URL/" 1000

  SMOKE_ONLY=1 timeout "$SMOKE_TIMEOUT" bash ./pre-upgrade-check.sh
  if [ -x ./check-xboard-runtime-integrity.sh ]; then
    bash ./check-xboard-runtime-integrity.sh
  fi
  snapshot_custom_db_state "after"
  assert_custom_settings_unchanged

  echo "Observing logs for ${POST_CHECK_SLEEP}s..."
  sleep "$POST_CHECK_SLEEP"
  for svc in web ws horizon redis; do
    n=$(docker compose logs --since "${POST_CHECK_SLEEP}s" "$svc" 2>&1 \
      | grep -Eci 'RedisException|MISCONF|Permission denied|fatal|panic|traceback|[[:space:]]500[[:space:]]+(GET|POST|PUT|PATCH|DELETE)' || true)
    echo "  $svc errors=$n"
    [ "$n" = "0" ] || fail "post-upgrade log sweep found $n error(s) in $svc"
  done

  echo "[ok] post-upgrade health gate passed"
}

require_cmd docker
require_cmd curl
require_cmd tar
require_cmd flock
require_cmd timeout

exec 9>"$LOCK_FILE"
flock -n 9 || fail "another upgrade is already running ($LOCK_FILE)"

if [ "${SELF_TEST:-0}" = "1" ]; then
  echo '=== Self-test: upgrade gate only (no pull/migrate/restart) ==='
  BACKUP_DIR=$(mktemp -d /tmp/xboard-upgrade-selftest.XXXXXX)
  snapshot_custom_db_state "before"
  post_upgrade_check
  exit 0
fi

if [ -z "${SKIP_CHECK:-}" ]; then
  echo '=== Step 0: Pre-upgrade safety check ==='
  bash ./pre-upgrade-check.sh || {
    rc=$?
    echo "pre-upgrade-check failed (rc=$rc). To override: SKIP_CHECK=1 bash upgrade.sh"
    exit $rc
  }
fi

make_backup

echo '=== Step 1: Pull latest images ==='
docker compose pull
save_image_state "pulled"

echo '=== Step 2: Recreate containers with redis-first startup ==='
docker compose up -d redis
wait_for_redis
docker compose up -d web ws horizon

echo '=== Step 3: Run migrations ==='
docker compose exec -T web php artisan migrate --force

if source_patches_needed; then
  echo '=== Step 4: Apply PG compatibility patches (Model $casts) ==='
  bash patch-models.sh

  echo '=== Step 5: Apply security + boolean + EPay + ILIKE patches ==='
  bash patch-security.sh

  echo '=== Step 5.5: Apply PG stability tie-break patches ==='
  bash patch-pgsql-stability.sh

  echo '=== Step 5.6: Inject xb_server_id into ClashMeta subscription ==='
  docker cp patch-classmeta-xbid.sh yue-to-web-1:/tmp/patch-classmeta-xbid.sh
  docker compose exec -T web sh /tmp/patch-classmeta-xbid.sh

  echo '=== Step 5.65: Patch CommissionTier invite display hook ==='
  docker cp patch-commission-tier-hook.php yue-to-web-1:/www/patch-commission-tier-hook.php
  docker compose exec -T web php /www/patch-commission-tier-hook.php

  echo '=== Step 5.65b: Patch Loon upload bandwidth output ==='
  if [ -f ./patch-loon-upload-bandwidth.sh ]; then
    bash ./patch-loon-upload-bandwidth.sh
  fi
else
  echo '=== Step 4-5.65b: Skip source runtime patches (built into onesyue/xboard image) ==='
fi

echo '=== Step 5.66: Re-deploy InviteAlias plugin (固化防丢) ==='
if [ -f ./patch-invite-alias.sh ]; then
  bash patch-invite-alias.sh
else
  echo '  (skip — patch-invite-alias.sh not present; sync via sync-xboard-patches.sh)'
fi

echo '=== Step 5.67: (Re)install v2_server subnode mirror PG trigger ==='
# 子节点 protocol_settings 必须 == 父；trigger 防 admin UI 误改 / 上游 migration 副作用
# 详见 memory/feedback_panel_subnode_mirror_main.md
if [ -f ./patch-subnode-mirror-trigger.sh ]; then
  bash patch-subnode-mirror-trigger.sh
else
  echo '  (skip — patch-subnode-mirror-trigger.sh not present; sync via sync-xboard-patches.sh)'
fi

echo '=== Step 5.7: Verify sing-box upstream include/exclude support ==='
bash patch-singbox-placeholder.sh

echo '=== Step 5.8: Sync 6 subscribe templates from xboard-templates/ ==='
bash patch-subscribe-templates.sh

echo '=== Step 5.9: Sync nginx confs from xboard-nginx/ ==='
bash patch-xboard-nginx.sh

if source_patches_needed; then
  echo '=== Step 6: Apply reset-subscription disconnect patch ==='
  docker cp patch-reset-disconnect.php yue-to-web-1:/www/patch-reset-disconnect.php
  docker compose exec -T web php /www/patch-reset-disconnect.php

  echo '=== Step 6.1: Apply online-stats v2 (cleanup freq + ttl + db throttle) ==='
  docker cp patch-online-stats-v2.php yue-to-web-1:/www/patch-online-stats-v2.php
  docker compose exec -T web php /www/patch-online-stats-v2.php

  docker compose restart web ws horizon
else
  echo '=== Step 6: Skip reset-subscription + online-stats runtime patches (built into image) ==='
fi

if [ -f ./harden-xboard-portal-theme.sh ]; then
  echo '=== Step 7: Re-apply Portal theme hardening ==='
  LOCAL=1 bash ./harden-xboard-portal-theme.sh
fi

if [ -f ./install-xboard-runtime-guard.sh ]; then
  echo '=== Step 7.5: Install runtime patch guard timer ==='
  bash ./install-xboard-runtime-guard.sh
fi

post_upgrade_check

echo '=== Upgrade complete! ==='
