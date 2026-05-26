#!/bin/bash
# Install/refresh the systemd timer that restores Yue.to XBoard runtime patches after container recreation.
set -Eeuo pipefail
cd /home/xboard/yue-to

[ -x ./restore-xboard-runtime-patches.sh ] || { echo "FATAL: restore-xboard-runtime-patches.sh missing or not executable" >&2; exit 1; }

cat > /etc/systemd/system/yue-to-runtime-patches.service <<'UNIT'
[Unit]
Description=Restore Yue.to XBoard runtime patches after container recreation
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
WorkingDirectory=/home/xboard/yue-to
ExecStart=/home/xboard/yue-to/restore-xboard-runtime-patches.sh
TimeoutStartSec=300
UNIT

cat > /etc/systemd/system/yue-to-runtime-patches.timer <<'UNIT'
[Unit]
Description=Periodic Yue.to XBoard runtime patch marker check

[Timer]
OnBootSec=2min
OnUnitActiveSec=2min
AccuracySec=30s
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now yue-to-runtime-patches.timer >/dev/null
systemctl is-enabled yue-to-runtime-patches.timer >/dev/null
systemctl list-timers --all yue-to-runtime-patches.timer --no-pager
