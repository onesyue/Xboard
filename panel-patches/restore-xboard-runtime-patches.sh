#!/bin/bash
# Re-apply Yue.to XBoard runtime patches after image/container recreation.
# 2026-05-26: all docker cp into container were rewritten to stdin redirect
# (docker 29.x has a known incompatibility with tmpfs mounts that makes
# `docker cp` silently produce 'Could not find the file' inside the mount;
# stdin redirect via `docker exec -i ... cat > ...` is cross-version safe).
# Idempotent: cheap marker check first; only runs heavy patch sequence when a marker is missing.
set -Eeuo pipefail
cd /home/xboard/yue-to

LOCK_FILE=${LOCK_FILE:-/tmp/yue-to-runtime-patches.lock}
LOG_PREFIX=${LOG_PREFIX:-[runtime-patches]}
APP_SERVICES=${APP_SERVICES:-web ws horizon}

log() { printf "%s %s\n" "$LOG_PREFIX" "$*"; }

exec 9>"$LOCK_FILE"
flock -n 9 || { log "another restore is already running"; exit 0; }

service_cid() {
  docker compose ps -q "$1" 2>/dev/null | head -1
}

service_running() {
  local cid
  cid=$(service_cid "$1")
  [ -n "$cid" ] && [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || true)" = "true" ]
}

if ! service_running web; then
  log "web is not running; skip"
  exit 0
fi

check_container_markers() {
  local svc="$1" cid
  cid=$(service_cid "$svc")
  [ -n "$cid" ] || { echo "$svc:missing-container"; return 1; }
  docker exec "$cid" sh -lc '
    ok=0
    miss() { echo "$1"; ok=1; }
    grep -Eq "total_amount.*integer" /www/app/Models/Order.php || miss "Order.total_amount cast"
    grep -Eq "paid_total.*integer" /www/app/Models/Stat.php || miss "Stat.paid_total cast"
    grep -Eq "app_sign_streak.*integer" /www/app/Models/User.php || miss "User.app_sign_streak cast"
    grep -Eq "sell.*boolean" /www/app/Models/Plan.php || miss "Plan.sell cast"
    grep -Eq "last_reply_user_id.*integer" /www/app/Models/Ticket.php || miss "Ticket.last_reply_user_id cast"
    grep -Fq "[PG ILIKE patch]" /www/app/Traits/QueryOperators.php || miss "PG ILIKE patch"
    grep -Fq "isValidFieldName" /www/app/Traits/QueryOperators.php || miss "QueryOperators validation"
    grep -Fq "[Patch BAL]" /www/app/Services/UserService.php || miss "balance tracking patch"
    grep -Fq "upload-bandwidth" /www/app/Protocols/Loon.php || miss "Loon upload-bandwidth patch"
    grep -Fq "xb_server_id" /www/app/Protocols/ClashMeta.php || miss "ClashMeta xb_server_id patch"
    grep -Fq "existingGroupNames" /www/app/Protocols/ClashMeta.php || miss "ClashMeta dangling-ref cleanup"
    grep -Fq "clientVersion !== null" /www/app/Support/AbstractProtocol.php || miss "AbstractProtocol null guard"
    grep -Fq "pgsql" /www/app/Console/Commands/BackupDatabase.php || miss "BackupDatabase pgsql support"
	    grep -Eq "config.*array" /www/app/Models/Plugin.php || miss "Plugin config cast"
	    grep -Fq "is_array" /www/app/Services/Plugin/PluginManager.php || miss "PluginManager config array compat"
	    grep -Fq "is_array" /www/app/Services/Plugin/PluginConfigService.php || miss "PluginConfigService config array compat"
	    grep -Fq "is_array" /www/app/Traits/HasPluginConfig.php || miss "HasPluginConfig array compat"
	    exit "$ok"
	  ' 2>&1 | sed "s/^/$svc:/"
}

need_restore=0
for svc in $APP_SERVICES; do
  if service_running "$svc"; then
    if ! out=$(check_container_markers "$svc"); then
      need_restore=1
      [ -n "$out" ] && log "missing markers: $out"
    fi
  fi
done

if ! docker compose exec -T web test -s /www/public/assets/u/app-core.js >/dev/null 2>&1; then
  need_restore=1
  log "missing markers: web:Portal app-core asset"
fi

if ! docker compose exec -T web grep -Fq "portal-auth-contrast-fix" /www/storage/theme/Portal/dashboard.blade.php >/dev/null 2>&1; then
  need_restore=1
  log "missing markers: web:Portal harden-contrast marker"
fi

if [ "$need_restore" = "0" ]; then
  log "all runtime patch markers present"
  exit 0
fi

log "restoring runtime patches"

bash ./patch-models.sh
bash ./patch-security.sh
bash ./patch-pgsql-stability.sh

if [ -f ./patch-classmeta-xbid.sh ]; then
  cat patch-classmeta-xbid.sh | docker exec -i yue-to-web-1 sh -c "cat > /tmp/patch-classmeta-xbid.sh && sh /tmp/patch-classmeta-xbid.sh"
fi

if [ -f ./patch-clashmeta-dangling-ref.sh ]; then
  cat patch-clashmeta-dangling-ref.sh | docker exec -i yue-to-web-1 sh -c "cat > /tmp/patch-clashmeta-dangling-ref.sh && sh /tmp/patch-clashmeta-dangling-ref.sh"
fi


if [ -f ./patch-loon-upload-bandwidth.sh ]; then
  bash ./patch-loon-upload-bandwidth.sh
fi

if [ -f ./patch-reset-disconnect.php ]; then
  cat patch-reset-disconnect.php | docker exec -i yue-to-web-1 sh -c "cat > /www/patch-reset-disconnect.php && php /www/patch-reset-disconnect.php"
fi

if [ -f ./patch-online-stats-v2.php ]; then
  cat patch-online-stats-v2.php | docker exec -i yue-to-web-1 sh -c "cat > /www/patch-online-stats-v2.php && php /www/patch-online-stats-v2.php"
fi

if [ -f ./patch-subscribe-templates.sh ]; then
  bash ./patch-subscribe-templates.sh
fi

if [ -f ./patch-xboard-nginx.sh ]; then
  bash ./patch-xboard-nginx.sh
fi

if [ -f ./harden-xboard-portal-theme.sh ]; then
  LOCAL=1 bash ./harden-xboard-portal-theme.sh
fi

# Propagate patched app/plugin code to long-running sibling containers. The image and app tree are identical,
# but each container has its own writable layer, so patching web alone is not enough after a recreate.
for svc in ws horizon; do
  if service_running "$svc"; then
    cid=$(service_cid "$svc")
    log "syncing patched /www/app and plugins-core to $svc"
    docker exec yue-to-web-1 tar -C /www -cf - app plugins-core 2>/dev/null | docker exec -i "$cid" tar -C /www -xf -
  fi
done

docker compose restart web ws horizon
sleep 8
if [ -x ./check-xboard-runtime-integrity.sh ]; then
  bash ./check-xboard-runtime-integrity.sh
fi
log "restore complete"
