#!/bin/bash
# Pre-upgrade safety check for XBoard panel.
# Runs before upgrade.sh to detect breaking upstream changes early.
#
# Exit codes:
#   0 = safe to proceed
#   1 = environment / network error
#   2 = upstream Docker Build failed (upgrade would be no-op, blocks upgrade)
#   3 = user aborted at risk prompt
#
# Bypass: SKIP_CHECK=1 bash upgrade.sh
#
# Deploy target: /home/xboard/yue-to/pre-upgrade-check.sh (panel host)
# Source of truth: /opt/yueops/scripts/pre-upgrade-check.sh (bastion)
set -euo pipefail

REPO="cedar2025/Xboard"
IMAGE="ghcr.io/cedar2025/xboard:latest"
SCRIPTS_DIR="${SCRIPTS_DIR:-/home/xboard/yue-to}"

RED=$'\033[0;31m'; YEL=$'\033[1;33m'; GRN=$'\033[0;32m'; NC=$'\033[0m'
info() { printf '%s\n' "$*"; }
warn() { printf '%b%s%b\n' "$YEL" "$*" "$NC"; }
err()  { printf '%b%s%b\n' "$RED" "$*" "$NC" >&2; }
ok()   { printf '%b%s%b\n' "$GRN" "$*" "$NC"; }

command -v jq >/dev/null   || { err "jq not installed. apt install -y jq"; exit 1; }
command -v curl >/dev/null || { err "curl not installed."; exit 1; }

# --- SMOKE_ONLY=1: skip image/migrations checks, jump straight to client smoke ---
# Useful for ad-hoc post-deploy validation: bash pre-upgrade-check.sh
#   with SMOKE_ONLY=1, runs only the client-compat section.
if [ "${SMOKE_ONLY:-0}" = "1" ]; then
  info "(SMOKE_ONLY mode — skipping image/migration/conflict checks)"
  goto_smoke=1
fi

if [ "${goto_smoke:-0}" = "1" ]; then
  # jump past the image/migration block
  :
else

# 1. Current image revision (label set by upstream CI)
CURRENT=$(docker image inspect "$IMAGE" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' 2>/dev/null || true)
if [ -z "${CURRENT:-}" ]; then
  err "Can't read revision from $IMAGE. docker image may be stale or missing label."
  exit 1
fi

# 2. Upstream master HEAD
UPSTREAM=$(curl -sfL "https://api.github.com/repos/${REPO}/commits/master" 2>/dev/null | jq -r '.sha // empty')
if [ -z "${UPSTREAM:-}" ]; then
  err "Can't fetch upstream HEAD (github api failed, rate-limited, or network down)."
  exit 1
fi

info "Current image: ${CURRENT:0:8}"
info "Upstream HEAD: ${UPSTREAM:0:8}"

if [ "$CURRENT" = "$UPSTREAM" ]; then
  ok "✓ Already on latest upstream. Nothing to upgrade."
  exit 0
fi

# 3. Upstream Docker Build status for HEAD commit
BUILD_LINE=$(curl -sfL "https://api.github.com/repos/${REPO}/actions/runs?head_sha=${UPSTREAM}&per_page=5" 2>/dev/null \
             | jq -r '[.workflow_runs[] | select(.name == "Docker Build and Publish")][0] // empty | "\(.status) \(.conclusion // "null")"')

info ""
info "=== Upstream Docker Build (${UPSTREAM:0:8}) ==="
case "$BUILD_LINE" in
  "completed success")
    ok "  ✓ $BUILD_LINE" ;;
  "in_progress null"|"queued null"|"waiting null")
    warn "  🔄 $BUILD_LINE — image :latest not yet updated. Wait or SKIP_CHECK=1 to proceed anyway."
    ;;
  "completed failure"|"completed cancelled"|"completed timed_out")
    err "  ❌ $BUILD_LINE — upstream CI failed, :latest is stale. upgrade.sh would be a no-op."
    err "  Watch: https://github.com/${REPO}/actions"
    exit 2
    ;;
  ""|*null*)
    warn "  ? No Docker Build run for this commit yet. May just be freshly pushed — retry in ~30s."
    ;;
  *)
    warn "  ? Unexpected status: '$BUILD_LINE'" ;;
esac

# 4. Commit list
COMPARE=$(curl -sfL "https://api.github.com/repos/${REPO}/compare/${CURRENT}...${UPSTREAM}" 2>/dev/null)
if [ -z "$COMPARE" ] || ! echo "$COMPARE" | jq -e '.commits' >/dev/null 2>&1; then
  err "compare API failed (current commit may have been rebased off upstream)."
  exit 1
fi
COMMIT_COUNT=$(echo "$COMPARE" | jq -r '.commits | length')

info ""
info "=== Pending commits ($COMMIT_COUNT) ==="
echo "$COMPARE" | jq -r '.commits[] | "  \(.sha[0:8])  \(.commit.author.date|.[:10])  \(.commit.message|split("\n")[0])"'

# 5. Files changed in this span
CHANGED=$(echo "$COMPARE" | jq -r '.files[].filename' | sort -u)

# 6. Migrations (breaking DDL risk)
MIGRATIONS=$(echo "$CHANGED" | grep -E '^database/migrations/' || true)
MIG_COUNT=$(printf '%s' "$MIGRATIONS" | grep -c . || true)

# 7. Files our patches touch — auto-extracted from patch scripts
#    Matches: app/... plugins/... resources/... database/... ending in .php/.yaml/.json
if [ -d "$SCRIPTS_DIR" ]; then
  PATCHED=$(cat "$SCRIPTS_DIR"/patch-*.sh "$SCRIPTS_DIR"/patch-*.php 2>/dev/null \
            | grep -ohE '(app|plugins|resources|database)/[a-zA-Z0-9/_.-]+\.(php|yaml|json)' \
            | sort -u)
else
  PATCHED=""
fi

if [ -n "$PATCHED" ]; then
  CONFLICTS=$(echo "$CHANGED" | while read -r f; do
    [ -z "$f" ] && continue
    if printf '%s\n' "$PATCHED" | grep -Fxq -- "$f"; then
      echo "$f"
    fi
  done)
  CONFLICT_COUNT=$(printf '%s' "$CONFLICTS" | grep -c . || true)
else
  CONFLICTS=""
  CONFLICT_COUNT=0
fi

# 8. Risk report
info ""
info "=== Risk analysis ==="
if [ "$MIG_COUNT" -gt 0 ]; then
  warn "  ⚠️  $MIG_COUNT new migration(s) — BREAKING DDL RISK:"
  printf '%s\n' "$MIGRATIONS" | sed 's/^/     • /'
else
  ok "  ✓ No new migrations"
fi

if [ "$CONFLICT_COUNT" -gt 0 ]; then
  warn "  ⚠️  $CONFLICT_COUNT patched-file(s) changed upstream — PATCH ANCHOR MAY SILENTLY FAIL:"
  printf '%s\n' "$CONFLICTS" | sed 's/^/     • /'
else
  ok "  ✓ No patched-file conflicts"
fi

# 9. Go/no-go prompt (only when risky)
if [ "$MIG_COUNT" -gt 0 ] || [ "$CONFLICT_COUNT" -gt 0 ]; then
  info ""
  warn "Review the risks above before running upgrade.sh."
  warn "Recent example (2026-04-18): ticket reply_status semantic swap required coordinated code"
  warn "changes in yue bot + checkin-api before migration ran."
  info ""
  if [ -t 0 ]; then
    read -rp "Continue anyway? [y/N] " REPLY
  else
    REPLY="${REPLY:-n}"
  fi
  case "$REPLY" in
    y|Y|yes|YES) ok "✓ Proceed acknowledged." ;;
    *) warn "Aborted by user."; exit 3 ;;
  esac
fi

fi  # end of non-SMOKE_ONLY block

# 10. Client-compat smoke: render a real subscription for the 3 YAML/JSON
#     formats (singbox / clashmeta / stash) and structurally validate it.
#     Catches: deprecated sing-box schema, leaked {all} / includes placeholder,
#     stash nameserver-policy.value type drift.
info ""
info "=== Client-compat smoke ==="
SMOKE_TOKEN=""
# Use artisan tinker (panel doesn't have psql client; web container has Laravel)
SMOKE_TOKEN=$(cd /home/xboard/yue-to && docker compose exec -T web php artisan tinker --execute='echo App\Models\User::where("banned", false)->where("transfer_enable", ">", 0)->whereRaw("(expired_at IS NULL OR expired_at > extract(epoch from now())::bigint)")->limit(1)->value("token");' 2>/dev/null | tr -d '[:space:]')

if [ -z "${SMOKE_TOKEN:-}" ]; then
  warn "  ? no usable token found in v2_user — skipping live subscribe smoke"
else
  python3 - "$SMOKE_TOKEN" <<'PY' || { err "  ❌ smoke check failed — fix before upgrading"; exit 4; }
import json, re, subprocess, sys, time, urllib.error, urllib.request

token = sys.argv[1]
base = 'http://127.0.0.1:8001/api/v1/client/subscribe'

def fetch(ua):
    req = urllib.request.Request(f'{base}?token={token}', headers={'User-Agent': ua})
    last_error = None
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return r.read().decode('utf-8')
        except urllib.error.HTTPError as e:
            last_error = e
            if e.code != 429 or attempt == 3:
                raise
            time.sleep(2 + attempt)
    raise last_error

errors = []

noise_re = re.compile(r'剩余|到期|官网|流量|套餐|重置|过期|Expire|Traffic|订阅|网址', re.I)
region_re = {
    '香港': re.compile(r'🇭🇰|香港|Hong.?Kong|Hong|(^|[^A-Za-z])HK([^A-Za-z]|$)', re.I),
    '台湾': re.compile(r'🇹🇼|台湾|台灣|Taiwan|Taipei|(^|[^A-Za-z])TW([^A-Za-z]|$)', re.I),
    '日本': re.compile(r'🇯🇵|日本|Japan|Tokyo|Osaka|东京|大阪|(^|[^A-Za-z])JP([^A-Za-z]|$)', re.I),
    '韩国': re.compile(r'🇰🇷|韩国|韓國|Korea|Seoul|首尔|(^|[^A-Za-z])KR([^A-Za-z]|$)', re.I),
    '新加坡': re.compile(r'🇸🇬|新加坡|狮城|Singapore|(^|[^A-Za-z])SG([^A-Za-z]|$)', re.I),
    '美国': re.compile(r'^(?!.*AI).*(🇺🇸|美国|美國|United.?States|America|Los.?Angeles|San.?Jose|Seattle|Chicago|Dallas|(^|[^A-Za-z])USA?([^A-Za-z]|$))', re.I),
    '英国': re.compile(r'🇬🇧|英国|英國|United.?Kingdom|Britain|England|London|伦敦|UK', re.I),
    '德国': re.compile(r'🇩🇪|德国|德國|Germany|Frankfurt|Berlin|法兰克福|柏林|(^|[^A-Za-z])DE([^A-Za-z]|$)', re.I),
    '澳大利亚': re.compile(r'🇦🇺|澳大利亚|澳洲|Australia|Sydney|Melbourne|悉尼|墨尔本|(^|[^A-Za-z])AU([^A-Za-z]|$)', re.I),
    '加拿大': re.compile(r'🇨🇦|加拿大|Canada|Toronto|Vancouver|Montreal|多伦多|温哥华|(^|[^A-Za-z])CA([^A-Za-z]|$)', re.I),
    '荷兰': re.compile(r'🇳🇱|荷兰|荷蘭|Netherlands|Nederland|Amsterdam|阿姆斯特丹|(^|[^A-Za-z])NL([^A-Za-z]|$)', re.I),
}

def check_region_groups(label, groups):
    bad = []
    for name, members in groups.items():
        region = next((r for r in region_re if r in name), None)
        if not region:
            continue
        for member in members or []:
            text = str(member)
            if text in ('DIRECT', 'direct') or '聚合' in text:
                continue
            if noise_re.search(text):
                bad.append(f'{name} contains info/noise node {text}')
            elif not region_re[region].search(text):
                bad.append(f'{name} contains non-{region} node {text}')
    return bad

def check_fallback_group(label, members):
    members = [str(x) for x in (members or [])]
    bad = []
    if 'DIRECT' not in members and 'direct' not in members:
        bad.append(f'{label} missing DIRECT/direct in 兜底分流')
    leaked = [m for m in members if noise_re.search(m)]
    if leaked:
        bad.append(f'{label} 兜底分流 contains info/noise nodes: {leaked[:3]}')
    real_nodes = [m for m in members if m.startswith('悦·')]
    if real_nodes:
        bad.append(f'{label} 兜底分流 auto-expanded real nodes: {real_nodes[:3]}')
    if len(members) > 20:
        bad.append(f'{label} 兜底分流 too large ({len(members)} members), likely auto-expanded')
    return bad

# --- singbox (sing-box 1.13.7 schema) ---
try:
    d = json.loads(fetch('sing-box 1.13.7'))
    bad = []
    for s in d.get('dns', {}).get('servers', []):
        if 'address' in s:                 bad.append(f'legacy DNS address on {s.get("tag")}')
    for o in d.get('outbounds', []):
        if o.get('type') in ('block', 'dns'):
                                            bad.append(f'legacy outbound type {o.get("type")}')
        if 'includes' in o or 'excludes' in o:
                                            bad.append(f'unrendered includes/excludes on {o.get("tag")}')
        if '{all}' in (o.get('outbounds') or []):
                                            bad.append(f'unrendered {{all}} placeholder on {o.get("tag")}')
    for i in d.get('inbounds', []):
        for k in ('sniff', 'sniff_override_destination', 'domain_strategy'):
            if k in i:                      bad.append(f'legacy inbound field {k} on {i.get("tag")}')
    if 'independent_cache' in d.get('dns', {}):
        bad.append('legacy dns.independent_cache')
    if 'fakeip' in d.get('dns', {}):
        bad.append('legacy dns.fakeip top-level (use type:fakeip server)')
    outbound_groups = {
        o.get('tag'): o.get('outbounds') or []
        for o in d.get('outbounds', [])
        if o.get('type') in ('selector', 'urltest')
    }
    bad.extend(check_region_groups('singbox', outbound_groups))
    bad.extend(check_fallback_group('singbox', outbound_groups.get('兜底分流')))
    if bad:
        errors.append('singbox: ' + '; '.join(bad))
    else:
        print('  ✓ singbox schema clean (no legacy / unrendered fields)')
except Exception as e:
    errors.append(f'singbox fetch/parse: {e}')

for mobile_ua in ('SFA/1.13.0', 'SFI/1.13.0'):
    try:
        d = json.loads(fetch(mobile_ua))
        if not isinstance(d.get('outbounds'), list) or not d.get('route'):
            errors.append(f'{mobile_ua}: did not render sing-box JSON config')
        elif not any(o.get('tag') == '兜底分流' for o in d.get('outbounds', []) if isinstance(o, dict)):
            errors.append(f'{mobile_ua}: sing-box JSON missing 兜底分流 outbound')
        else:
            print(f'  ✓ {mobile_ua} renders sing-box JSON')
    except Exception as e:
        errors.append(f'{mobile_ua}: expected sing-box JSON, got fetch/parse error: {e}')

# --- clashmeta + stash (yaml) ---
try:
    import yaml
except ImportError:
    print('  ? PyYAML missing — skipping yaml smoke'); yaml = None

if yaml:
    for fmt, ua, want_str_value in [
        ('clashmeta', 'clash.meta/v1.19.24', False),  # mihomo accepts arrays
        ('stash',     'Stash/3.0',           True),   # stash needs str values
    ]:
        try:
            d = yaml.safe_load(fetch(ua))
            bad = []
            if not d.get('proxies'):
                bad.append('no proxies rendered')
            nsp = d.get('dns', {}).get('nameserver-policy', {})
            if want_str_value and nsp:
                non_str = [k for k, v in nsp.items() if not isinstance(v, str)]
                if non_str:
                    bad.append(f'nameserver-policy non-str values: {len(non_str)} keys')
                multi_key = [k for k in nsp if ',' in str(k)]
                if multi_key:
                    bad.append(f'nameserver-policy comma-joined keys: {len(multi_key)}')
            for g in d.get('proxy-groups') or []:
                outs = g.get('proxies') or []
                if '{all}' in outs:
                    bad.append(f"group '{g.get('name')}' has unrendered {{all}}")
                if g.get('include') or g.get('include-all') is False or g.get('filter'):
                    pass  # mihomo proxy-provider fields, ok
            groups = {g.get('name'): g.get('proxies') or [] for g in d.get('proxy-groups') or []}
            bad.extend(check_region_groups(fmt, groups))
            bad.extend(check_fallback_group(fmt, groups.get('兜底分流')))
            if bad:
                errors.append(f'{fmt}: ' + '; '.join(bad))
            else:
                print(f'  ✓ {fmt} schema clean')
        except yaml.YAMLError as e:
            errors.append(f'{fmt} yaml parse: {e}')
        except Exception as e:
            errors.append(f'{fmt} fetch: {e}')

if errors:
    print()
    print('SMOKE FAILURES:')
    for e in errors: print('  •', e)
    sys.exit(1)
PY
fi

# 11. Plugin presence check —— 升级前确认私有插件源码在位（防止 sync 漏推）
info ""
info "=== Private plugin presence ==="
MISSING_PLUGINS=()
for p in CommissionTier InviteAlias YueClientCompat YueOnlineCount; do
  if [ -d "/home/xboard/yue-to/plugins/$p" ] || [ -d "/home/xboard/yue-to/xboard-plugins/$p" ]; then
    ok "  ✓ $p present"
  else
    warn "  ⚠ $p missing — sync via bash /opt/yueops/scripts/sync-xboard-patches.sh"
    MISSING_PLUGINS+=("$p")
  fi
done
# InviteAlias 必须有 patch-invite-alias.sh，否则升级时 plugin row 可能丢
if [ -f /home/xboard/yue-to/patch-invite-alias.sh ]; then
  ok "  ✓ patch-invite-alias.sh present"
else
  warn "  ⚠ patch-invite-alias.sh missing — sync via sync-xboard-patches.sh"
fi

# 12. Sub-node mirror invariant —— v2_server parent_id 子节点 protocol_settings 必须 == 父
# 设计意图：子节点 = 父节点 UI 别名（"线路 3/4"），后端 xboard-node 只 pull 主节点
# 任何字段不一致（尤其 reality short_id）会让子节点客户端 100% 握手失败
# 装有 PG trigger v2_server_subnode_mirror_trg 自动 mirror，本检查兜底确认现状
info ""
info "=== Sub-node mirror invariant ==="
MISMATCH=$(cd /home/xboard/yue-to && docker compose exec -T web php artisan tinker --execute='
$rows = DB::select("SELECT child.id AS cid, child.name AS cname, parent.name AS pname FROM v2_server child JOIN v2_server parent ON child.parent_id = parent.id WHERE child.protocol_settings::jsonb != parent.protocol_settings::jsonb ORDER BY child.id");
echo count($rows) . "\n";
foreach ($rows as $r) echo "  • #{$r->cid} {$r->cname} (parent: {$r->pname})\n";
' 2>/dev/null | sed 's/^[[:space:]]*//')
MISMATCH_COUNT=$(echo "$MISMATCH" | head -1 | grep -o '^[0-9]\+' || echo 0)
if [ "${MISMATCH_COUNT:-0}" -eq 0 ]; then
  ok "  ✓ all sub-nodes mirror parent protocol_settings"
else
  err "  ❌ ${MISMATCH_COUNT} sub-node(s) diverge from parent — fix before upgrade:"
  echo "$MISMATCH" | tail -n +2
  err "  Trigger may have been dropped. Reinstall: cd /home/xboard/yue-to && docker cp v2_server_subnode_mirror_trigger.sql yue-to-web-1:/tmp/trigger.sql && docker compose exec -T web php artisan tinker --execute='DB::unprepared(file_get_contents("/tmp/trigger.sql"));'"
  exit 5
fi
# 同时确认 trigger 存在（用 query builder 避开 PG identifier 双引号歧义）
TRG_OK=$(cd /home/xboard/yue-to && docker compose exec -T web php artisan tinker --execute='echo DB::table("pg_trigger")->whereIn("tgname",["v2_server_subnode_mirror_trg","v2_server_parent_cascade_trg"])->count();' 2>/dev/null | grep -o '[0-9]\+' | tail -1)
if [ "${TRG_OK:-0}" = "2" ]; then
  ok "  ✓ both PG triggers (mirror + parent_cascade) installed"
else
  err "  ❌ PG trigger missing (found ${TRG_OK:-0}/2) — reinstall: bash /home/xboard/yue-to/patch-subnode-mirror-trigger.sh"
  exit 5
fi

ok "✓ Pre-upgrade checks passed."
exit 0
