#!/bin/sh
# Patch ClashMeta.php: cleanup dangling proxy-group references.
#
# Root cause (2026-05-25): array_filter() drops aggregator groups whose
# `proxies: []` ends up empty after regex-based filter resolution (e.g.
# "悦 · 🇭🇰 香港聚合" when user's plan has no HK nodes). But other groups
# like "悦 · 故障转移" / "$app_name" still reference the now-deleted name,
# causing mihomo "proxy group not found" on startCore (E006).
# telemetry: 30d 50 startup_fail rows, 45 traced to this single bug.
#
# Idempotent — marker check via grep "existingGroupNames" in handle().

FILE="/www/app/Protocols/ClashMeta.php"

if grep -Fq 'existingGroupNames' "$FILE"; then
    echo "[SKIP] ClashMeta.php already has dangling-ref cleanup"
    exit 0
fi

python3 - "$FILE" << 'PYEOF'
import sys

f = sys.argv[1]
src = open(f).read()

old = '''        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        $config = $this->buildRules($config);'''

new = '''        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        // P0b 修复 (2026-05-25): array_filter 删空组后清理悬空引用
        // 用户套餐没有某地区节点 → 聚合组 "悦 · 🇭🇰 香港聚合" 被过滤 →
        // 但 "悦 · 故障转移" / "$app_name" / "YouTube" 仍引用其名字 →
        // mihomo 解析 "proxy group not found" → startCore E006 失败
        // (telemetry: 30d 50 条 startup_fail 中 45 条为此根因)
        $existingGroupNames = array_column($config['proxy-groups'], 'name');
        $builtIns = ['DIRECT', 'REJECT', 'PASS', 'GLOBAL'];
        foreach ($config['proxy-groups'] as $k => $group) {
            if (empty($group['proxies']) || !is_array($group['proxies'])) continue;
            $config['proxy-groups'][$k]['proxies'] = array_values(array_filter(
                $group['proxies'],
                function ($p) use ($existingGroupNames, $proxies, $builtIns) {
                    if (in_array($p, $proxies, true)) return true;
                    if (in_array($p, $existingGroupNames, true)) return true;
                    if (in_array($p, $builtIns, true)) return true;
                    return false;
                }
            ));
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($g) {
            return !empty($g['proxies']);
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        $config = $this->buildRules($config);'''

if old not in src:
    if 'existingGroupNames' in src:
        print("[SKIP] already patched (marker present)")
        sys.exit(0)
    print(f"[ERROR] anchor not found in {f} — upstream changed?", file=sys.stderr)
    sys.exit(1)

patched = src.replace(old, new, 1)
open(f, 'w').write(patched)
hits = patched.count('existingGroupNames')
print(f"[OK] ClashMeta.php patched — dangling-ref cleanup ({hits} marker)")
PYEOF
