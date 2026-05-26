#!/bin/sh
# Patch ClashMeta.php to inject xb_server_id into each proxy entry.
# YueLink app reads this field from Clash YAML to populate fp→server binding
# in node_inventory telemetry events, enabling client quality SLI aggregation.
# Idempotent — safe to re-run.

FILE="/www/app/Protocols/ClashMeta.php"

if grep -q 'xb_server_id' "$FILE"; then
    echo "[SKIP] ClashMeta.php already has xb_server_id"
    exit 0
fi

python3 - "$FILE" << 'PYEOF'
import sys

f = sys.argv[1]
content = open(f).read()

# buildShadowsocks — $array['name'] = ... $array['type'] = 'ss'
content = content.replace(
    "        $array['name'] = $server['name'];\n        $array['type'] = 'ss';",
    "        $array['name'] = $server['name'];\n        $array['type'] = 'ss';\n        $array['xb_server_id'] = (int)($server['id'] ?? 0);"
)

# buildVmess
content = content.replace(
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'vmess',",
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'vmess',\n            'xb_server_id' => (int)($server['id'] ?? 0),"
)

# buildVless
content = content.replace(
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'vless',",
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'vless',\n            'xb_server_id' => (int)($server['id'] ?? 0),"
)

# buildTrojan
content = content.replace(
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'trojan',",
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'trojan',\n            'xb_server_id' => (int)($server['id'] ?? 0),"
)

# buildHysteria
content = content.replace(
    "        $array = [\n            'name' => $server['name'],\n            'server' => $server['host'],\n            'port' => $server['port'],\n            'sni' =>",
    "        $array = [\n            'name' => $server['name'],\n            'xb_server_id' => (int)($server['id'] ?? 0),\n            'server' => $server['host'],\n            'port' => $server['port'],\n            'sni' =>"
)

# buildTuic
content = content.replace(
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'tuic',",
    "        $array = [\n            'name' => $server['name'],\n            'type' => 'tuic',\n            'xb_server_id' => (int)($server['id'] ?? 0),"
)

open(f, 'w').write(content)

# Verify
hits = content.count('xb_server_id')
print(f"[OK] ClashMeta.php patched — {hits} xb_server_id injections")
PYEOF
