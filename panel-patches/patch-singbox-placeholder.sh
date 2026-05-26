#!/usr/bin/env bash
# Verify XBoard SingBox upstream include/exclude support.
#
# As of cedar2025/Xboard ee2c12ed (2026-05-03), upstream SingBox.php supports
# template-level include/exclude/fallback. Keep our customization in the
# subscription template, not in upstream PHP.
#
# This script intentionally does not patch app/Protocols/SingBox.php on modern
# upstream. It fails closed if the upstream support is missing or if the local
# singbox template still contains legacy includes/excludes/{all} placeholders.

set -euo pipefail
cd /home/xboard/yue-to

target="app/Protocols/SingBox.php"
templates=(
  "xboard-templates/singbox.json"
  "rules/default.sing-box.json"
)

docker compose cp web:/www/$target /tmp/SingBox.php.before >/dev/null

if ! grep -q "\$include = \$outbound\['include'\] ?? null;" /tmp/SingBox.php.before \
  || ! grep -q "\$exclude = \$outbound\['exclude'\] ?? null;" /tmp/SingBox.php.before \
  || ! grep -q "function matchesPattern" /tmp/SingBox.php.before \
  || ! grep -q "function resolveFallback" /tmp/SingBox.php.before; then
  echo "FATAL: upstream SingBox.php does not expose include/exclude/fallback support." >&2
  echo "       Do not inject another PHP patch by default; upgrade to ee2c12ed+ or review upstream first." >&2
  exit 1
fi

for template in "${templates[@]}"; do
  [ -f "$template" ] || continue
  python3 - "$template" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
data = json.loads(path.read_text())
bad = []

for idx, outbound in enumerate(data.get("outbounds", [])):
    tag = outbound.get("tag", f"#{idx}")
    if "includes" in outbound:
        bad.append(f"{tag}: uses legacy includes")
    if "excludes" in outbound:
        bad.append(f"{tag}: uses legacy excludes")
    if "{all}" in (outbound.get("outbounds") or []):
        bad.append(f"{tag}: uses legacy {{all}} placeholder")

if bad:
    print(f"FATAL: {path} is not upstream-native:", file=sys.stderr)
    for item in bad:
        print(f"  - {item}", file=sys.stderr)
    sys.exit(1)
PY
done

docker compose exec -T web php -l /www/$target | head -3
echo "[ok] upstream SingBox include/exclude support present; no PHP patch applied"
