#!/bin/bash
# Sync XBoard panel patches: bastion /opt/yueops/scripts/ → 66.55.76.208:/home/xboard/yue-to/
# Run on bastion: bash /opt/yueops/scripts/sync-xboard-patches.sh
#
# Note: local source `xboard-upgrade.sh` deploys as `upgrade.sh` on panel.

set -uo pipefail
if [ -r /etc/yueops-secrets.env ]; then
  # shellcheck disable=SC1091
  . /etc/yueops-secrets.env
fi
: "${SSHPASS:?FATAL: set SSHPASS or create /etc/yueops-secrets.env}"
export SSHPASS

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$SCRIPT_DIR}
PROD_HOST=66.55.76.208
PROD_DIR=/home/xboard/yue-to

[ -d "$SRC" ] || { echo "FATAL: $SRC missing"; exit 1; }

# Map: source filename:destination filename (identity unless renamed on deploy).
# Keep this bash-3 compatible because the local bastion may be macOS.
DEPLOY=(
  "patch-models.sh:patch-models.sh"
  "patch-security.sh:patch-security.sh"
  "patch-pgsql-stability.sh:patch-pgsql-stability.sh"
  "patch-pgsql-ilike.php:patch-pgsql-ilike.php"
  "patch-pgsql-runtime-compat.php:patch-pgsql-runtime-compat.php"
  "patch-verify-pgsql-casts.php:patch-verify-pgsql-casts.php"
  "patch-loon-upload-bandwidth.sh:patch-loon-upload-bandwidth.sh"
  "patch-loon-upload-bandwidth.php:patch-loon-upload-bandwidth.php"
  "patch-online-stats.php:patch-online-stats.php"
  "patch-online-stats-v2.php:patch-online-stats-v2.php"
  "patch-balance-tracking.php:patch-balance-tracking.php"
  "patch-commission-tier-hook.php:patch-commission-tier-hook.php"
  "patch-reset-disconnect.php:patch-reset-disconnect.php"
  "patch-classmeta-xbid.sh:patch-classmeta-xbid.sh"
  "patch-clashmeta-dangling-ref.sh:patch-clashmeta-dangling-ref.sh"
  "patch-invite-alias.sh:patch-invite-alias.sh"
  "patch-subnode-mirror-trigger.sh:patch-subnode-mirror-trigger.sh"
  "v2_server_subnode_mirror_trigger.sql:v2_server_subnode_mirror_trigger.sql"
  "patch-singbox-placeholder.sh:patch-singbox-placeholder.sh"
  "patch-subscribe-templates.sh:patch-subscribe-templates.sh"
  "patch-xboard-nginx.sh:patch-xboard-nginx.sh"
  "xboard-upgrade.sh:upgrade.sh"
  "harden-xboard-portal-theme.sh:harden-xboard-portal-theme.sh"
  "pre-upgrade-check.sh:pre-upgrade-check.sh"
  "restore-xboard-runtime-patches.sh:restore-xboard-runtime-patches.sh"
  "install-xboard-runtime-guard.sh:install-xboard-runtime-guard.sh"
  "check-xboard-runtime-integrity.sh:check-xboard-runtime-integrity.sh"
)

echo "=== Verifying source files ==="
for pair in "${DEPLOY[@]}"; do
  src="${pair%%:*}"
  if [ ! -f "$SRC/$src" ]; then
    echo "MISSING: $SRC/$src"
    exit 1
  fi
  echo "  ✓ $src ($(wc -l < $SRC/$src) lines)"
done

echo
echo "=== Syntax check (bash scripts only) ==="
for src in xboard-upgrade.sh pre-upgrade-check.sh harden-xboard-portal-theme.sh patch-models.sh patch-security.sh patch-pgsql-stability.sh patch-singbox-placeholder.sh patch-subscribe-templates.sh patch-xboard-nginx.sh restore-xboard-runtime-patches.sh install-xboard-runtime-guard.sh check-xboard-runtime-integrity.sh patch-loon-upload-bandwidth.sh patch-invite-alias.sh patch-subnode-mirror-trigger.sh; do
  bash -n "$SRC/$src" && echo "  ✓ $src bash syntax OK"
done

echo
echo "=== Pushing to production $PROD_HOST ==="
for pair in "${DEPLOY[@]}"; do
  src="${pair%%:*}"
  dst="${pair#*:}"
  if sshpass -e scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$SRC/$src" "root@${PROD_HOST}:${PROD_DIR}/$dst" 2>/dev/null; then
    echo "  ✓ $src → ${PROD_HOST}:${PROD_DIR}/$dst"
  else
    echo "  ✗ $src failed"
    exit 1
  fi
done

echo
echo "=== Pushing xboard-templates/ ==="
if [ -d "$SRC/xboard-templates" ]; then
  sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST \
    "mkdir -p $PROD_DIR/xboard-templates"
  for f in "$SRC/xboard-templates"/*.{json,yaml,conf}; do
    [ -f "$f" ] || continue
    base=$(basename "$f")
    if sshpass -e scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$f" "root@${PROD_HOST}:${PROD_DIR}/xboard-templates/$base" 2>/dev/null; then
      echo "  ✓ xboard-templates/$base"
    else
      echo "  ✗ xboard-templates/$base failed"; exit 1
    fi
  done
else
  echo "  (skip — $SRC/xboard-templates not found)"
fi

echo
echo "=== Pushing xboard-nginx/ ==="
if [ -d "$SRC/xboard-nginx" ]; then
  sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST \
    "mkdir -p $PROD_DIR/xboard-nginx"
  for f in "$SRC/xboard-nginx"/*.conf; do
    [ -f "$f" ] || continue
    base=$(basename "$f")
    if sshpass -e scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$f" "root@${PROD_HOST}:${PROD_DIR}/xboard-nginx/$base" 2>/dev/null; then
      echo "  ✓ xboard-nginx/$base"
    else
      echo "  ✗ xboard-nginx/$base failed"; exit 1
    fi
  done
else
  echo "  (skip — $SRC/xboard-nginx not found)"
fi

# Restore exec bit (scp strips it)
sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST \
  "chmod +x $PROD_DIR/patch-models.sh $PROD_DIR/patch-security.sh $PROD_DIR/patch-pgsql-stability.sh $PROD_DIR/patch-singbox-placeholder.sh $PROD_DIR/patch-subscribe-templates.sh $PROD_DIR/patch-xboard-nginx.sh $PROD_DIR/upgrade.sh $PROD_DIR/harden-xboard-portal-theme.sh $PROD_DIR/pre-upgrade-check.sh $PROD_DIR/restore-xboard-runtime-patches.sh $PROD_DIR/install-xboard-runtime-guard.sh $PROD_DIR/check-xboard-runtime-integrity.sh $PROD_DIR/patch-loon-upload-bandwidth.sh $PROD_DIR/patch-invite-alias.sh $PROD_DIR/patch-subnode-mirror-trigger.sh"

echo
echo
echo "=== Installing runtime guard timer ==="
sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST "cd $PROD_DIR && bash install-xboard-runtime-guard.sh"

echo "=== Removing retired upstream patches ==="
sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST \
  "rm -f $PROD_DIR/patch-singbox-mobile-flags.sh"

echo
echo "=== Verification on production ==="
sshpass -e ssh -n -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PROD_HOST \
  "ls -la $PROD_DIR/patch-*.sh $PROD_DIR/patch-*.php $PROD_DIR/upgrade.sh $PROD_DIR/pre-upgrade-check.sh $PROD_DIR/restore-xboard-runtime-patches.sh $PROD_DIR/install-xboard-runtime-guard.sh $PROD_DIR/check-xboard-runtime-integrity.sh"

echo
echo "Sync complete. Next upgrade: ssh root@${PROD_HOST} 'cd ${PROD_DIR} && bash upgrade.sh'"
