#!/usr/bin/env bash
# Patch XBoard Loon HY2 subscription output to include upload-bandwidth.
# Re-run after container image upgrades if upstream has not fixed it.
set -euo pipefail
cd /home/xboard/yue-to
cat /home/xboard/yue-to/patch-loon-upload-bandwidth.php | docker exec -i yue-to-web-1 sh -c 'cat > /tmp/patch-loon-upload-bandwidth.php'  # tmpfs-safe
docker exec yue-to-web-1 php /tmp/patch-loon-upload-bandwidth.php
docker exec yue-to-web-1 php -l /www/app/Protocols/Loon.php
