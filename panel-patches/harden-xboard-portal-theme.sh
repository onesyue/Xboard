#!/bin/bash
# Re-apply the neutral Portal theme wrapper after XBoard/theme upgrades.
#
# Run from bastion:
#   SSHPASS=... bash scripts/harden-xboard-portal-theme.sh
#
# Run on the panel host itself:
#   LOCAL=1 bash /home/xboard/yue-to/harden-xboard-portal-theme.sh
set -euo pipefail

PANEL_HOST=${PANEL_HOST:-66.55.76.208}
PANEL_USER=${PANEL_USER:-root}
CONTAINER=${CONTAINER:-yue-to-web-1}
THEME=${THEME:-Portal}
ASSET_VER=${ASSET_VER:-$(date +%Y%m%d%H%M%S)}
LOCAL=${LOCAL:-0}

run_remote() {
  if [ "$LOCAL" = "1" ]; then
    CONTAINER="$CONTAINER" THEME="$THEME" ASSET_VER="$ASSET_VER" bash -s
    return
  fi

  if [ -n "${SSHPASS:-}" ]; then
    sshpass -e ssh -o StrictHostKeyChecking=no "${PANEL_USER}@${PANEL_HOST}" "$@"
  else
    ssh -o StrictHostKeyChecking=no "${PANEL_USER}@${PANEL_HOST}" "$@"
  fi
}

remote_env=$(printf 'CONTAINER=%q THEME=%q ASSET_VER=%q bash -s' "$CONTAINER" "$THEME" "$ASSET_VER")

echo "Applying Portal hardening on ${PANEL_HOST}/${CONTAINER} (theme=${THEME}, asset=${ASSET_VER})"

run_remote "$remote_env" <<'REMOTE'
set -euo pipefail

docker exec -i -e THEME="$THEME" -e ASSET_VER="$ASSET_VER" "$CONTAINER" sh -s <<'CONTAINER'
set -eu

THEME=${THEME:-Portal}
ASSET_VER=${ASSET_VER:-$(date +%Y%m%d%H%M%S)}
SRC="/www/storage/theme/$THEME"
BASE="/www/storage/theme/LiquidGlass"
PUBLIC_THEME="/www/public/theme/$THEME"
ALIAS="/www/public/assets/u"

if [ ! -d "$SRC" ]; then
  if [ -d "$BASE" ]; then
    cp -a "$BASE" "$SRC"
  else
    mkdir -p "$SRC/assets"
  fi
fi

mkdir -p "$SRC/assets" "$PUBLIC_THEME/assets" "$ALIAS"

find "$SRC" "$PUBLIC_THEME" "$ALIAS" -type f \( \
  -name '*.bak*' -o \
  -name 'chat-widget-panel.js' -o \
  -name 'yue-chat-v5.js' -o \
  -name 'env.example.js' \
\) -delete 2>/dev/null || true

if [ -d "$SRC/assets" ]; then
  cp -a "$SRC/assets/." "$ALIAS/"
  cp -a "$SRC/assets/." "$PUBLIC_THEME/assets/"
fi

MAIN_JS=$(find "$SRC/assets" "$PUBLIC_THEME/assets" "$ALIAS" -maxdepth 1 -type f -name '*.js' \
  ! -name '*widget*' ! -name 'ux-state.js' ! -name 'app-core.js' \
  -exec wc -c {} + 2>/dev/null | sort -nr | awk '$2 != "total" {print $2; exit}')
if [ -n "${MAIN_JS:-}" ] && [ -f "$MAIN_JS" ]; then
  cp "$MAIN_JS" "$ALIAS/app-core.js"
fi

if [ ! -s "$ALIAS/app-core.js" ]; then
  echo "FATAL: Portal app-core.js alias was not generated" >&2
  echo "       Check theme assets under $SRC/assets and $PUBLIC_THEME/assets" >&2
  exit 1
fi

# 合并所有 plugin widget 到 ux-state.js（此名是兼容别名，多 widget 顺序拼接）
UX_OUT="$ALIAS/ux-state.js"
: > "$UX_OUT"
for w in \
  /www/plugins/CommissionTier/assets/commission-tier-widget.js \
  /www/plugins/InviteAlias/assets/invite-alias-widget.js \
  /www/plugins/YueOnlineCount/assets/yue-online-count-widget.js \
; do
  if [ -f "$w" ]; then
    echo "/* === $(basename "$w") === */" >> "$UX_OUT"
    cat "$w" >> "$UX_OUT"
    echo "" >> "$UX_OUT"
  fi
done
cp "$UX_OUT" "$PUBLIC_THEME/assets/ux-state.js"

rm -f \
  "$ALIAS/commission-tier-widget.js" \
  "$ALIAS/invite-alias-widget.js" \
  "$ALIAS/yue-online-count-widget.js" \
  "$PUBLIC_THEME/assets/commission-tier-widget.js" \
  "$PUBLIC_THEME/assets/invite-alias-widget.js" \
  "$PUBLIC_THEME/assets/yue-online-count-widget.js" \
  "$PUBLIC_THEME/assets/chat-widget-panel.js" \
  "$PUBLIC_THEME/assets/yue-chat-v5.js"

cat > "$SRC/dashboard.blade.php" <<'BLADE'
<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <meta http-equiv="Content-Security-Policy" content="script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com https://yue.yuebao.website; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://cloudflareinsights.com https://*.cloudflareinsights.com https://yue.yuebao.website; object-src 'none'; base-uri 'self'; form-action 'self'" />
  <meta name="robots" content="noindex,nofollow,noarchive" />
  <meta name="referrer" content="same-origin" />
  <meta name="description" content="{{ admin_setting('app_description', '专为 AI 与流媒体打造的全球加速网络') }}" />
  <link rel="icon" href="/favicon.ico" />
  <title>{{ admin_setting('app_name') ?: '控制台' }}</title>
  <script>
    if (!window.location.hash) {
      window.location.replace(window.location.pathname + window.location.search + '#/login');
    }
  </script>
  <style id="portal-auth-contrast-fix">
    :root {
      --portal-auth-agree-text: #000000;
      --portal-auth-checkbox-bg: #ffffff;
      --portal-auth-checkbox-fg: #000000;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --portal-auth-agree-text: #ffffff;
        --portal-auth-checkbox-bg: #000000;
        --portal-auth-checkbox-fg: #ffffff;
      }
    }

    html.dark,
    body.dark,
    [data-theme='dark'],
    .dark {
      --portal-auth-agree-text: #ffffff;
      --portal-auth-checkbox-bg: #000000;
      --portal-auth-checkbox-fg: #ffffff;
    }

    #app .n-checkbox {
      --n-color: var(--portal-auth-checkbox-bg) !important;
      --n-color-hover: var(--portal-auth-checkbox-bg) !important;
      --n-color-focus: var(--portal-auth-checkbox-bg) !important;
      --n-color-pressed: var(--portal-auth-checkbox-bg) !important;
      --n-color-checked: var(--portal-auth-checkbox-fg) !important;
      --n-color-checked-hover: var(--portal-auth-checkbox-fg) !important;
      --n-color-checked-pressed: var(--portal-auth-checkbox-fg) !important;
      --n-border: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-hover: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-focus: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-pressed: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-border-checked: 1px solid var(--portal-auth-checkbox-fg) !important;
      --n-check-mark-color: var(--portal-auth-checkbox-bg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox .n-checkbox-box {
      background: var(--portal-auth-checkbox-bg) !important;
      box-shadow: 0 0 0 0.5px var(--portal-auth-checkbox-fg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox .n-checkbox-box .n-checkbox-box__border {
      border: 1px solid var(--portal-auth-checkbox-fg) !important;
      opacity: 1 !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box {
      background: var(--portal-auth-checkbox-fg) !important;
      box-shadow: 0 0 0 0.5px var(--portal-auth-checkbox-fg) !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box .n-checkbox-box__border {
      border-color: var(--portal-auth-checkbox-fg) !important;
    }

    #app .n-checkbox.n-checkbox--checked .n-checkbox-box svg,
    #app .n-checkbox.n-checkbox--checked .n-checkbox-box path {
      color: var(--portal-auth-checkbox-bg) !important;
      fill: var(--portal-auth-checkbox-bg) !important;
      stroke: var(--portal-auth-checkbox-bg) !important;
    }

    #app .n-checkbox .n-checkbox__label {
      color: var(--portal-auth-agree-text) !important;
      font-weight: 500 !important;
      opacity: 1 !important;
      text-shadow: none !important;
    }

    #app .n-checkbox .n-checkbox__label a,
    #app .n-checkbox .n-checkbox__label a.text-blue-500 {
      color: var(--portal-auth-agree-text) !important;
      font-weight: 600 !important;
      opacity: 1 !important;
      text-decoration-color: currentColor !important;
      text-decoration-line: underline !important;
    }
  </style>
  <script type="module" crossorigin src="/assets/u/app-core.js?v=__ASSET_VER__"></script>
</head>

<body>
  <script>
    window.routerBase = '{{ $theme_config['api_url'] ?? '/' }}';
    window.settings = {
      title: @json(admin_setting('app_name', '')),
      assets_path: '/assets/u',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? 'default' }}',
      },
      version: '{{ $version }}',
      background_url: '{{ $theme_config['background_url'] }}',
      description: @json(admin_setting('app_description', '')),
      i18n: ['zh-CN', 'en-US'],
      logo: '{{ $logo ?: '/favicon.ico' }}',
      show_payment_title: '{{ $theme_config['show_payment_title'] ?? 'false' }}',
      hide_gift_card_menu: '{{ $theme_config['hide_gift_card_menu'] ?? 'false' }}',
    }
  </script>
  <script>
    window.__assets_path__ = window.settings?.assets_path || '/';
    __webpack_public_path__ = window.__assets_path__ + 'assets/';
  </script>
  <div id="app"></div>
  <script>
    (function () {
      function isDarkMode() {
        var root = document.documentElement;
        var body = document.body;
        return root.classList.contains('dark') ||
          body.classList.contains('dark') ||
          root.getAttribute('data-theme') === 'dark' ||
          body.getAttribute('data-theme') === 'dark' ||
          (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      }

      function paintCheckbox(checkbox) {
        var dark = isDarkMode();
        var fg = dark ? '#ffffff' : '#000000';
        var bg = dark ? '#000000' : '#ffffff';
        var label = checkbox.querySelector('.n-checkbox__label');
        var text = label ? label.textContent : checkbox.textContent;
        if (!/服务条款|Terms of Service|Terms/.test(text || '')) return;

        var checked = checkbox.classList.contains('n-checkbox--checked') ||
          !!checkbox.querySelector('input:checked');
        var box = checkbox.querySelector('.n-checkbox-box');
        var border = checkbox.querySelector('.n-checkbox-box__border');
        var markNodes = checkbox.querySelectorAll('svg, path, .n-checkbox-box__check');

        checkbox.style.setProperty('--n-color', bg, 'important');
        checkbox.style.setProperty('--n-color-hover', bg, 'important');
        checkbox.style.setProperty('--n-color-focus', bg, 'important');
        checkbox.style.setProperty('--n-color-pressed', bg, 'important');
        checkbox.style.setProperty('--n-color-checked', fg, 'important');
        checkbox.style.setProperty('--n-color-checked-hover', fg, 'important');
        checkbox.style.setProperty('--n-color-checked-pressed', fg, 'important');
        checkbox.style.setProperty('--n-border', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-hover', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-focus', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-pressed', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-border-checked', '1px solid ' + fg, 'important');
        checkbox.style.setProperty('--n-check-mark-color', bg, 'important');
        checkbox.style.setProperty('opacity', '1', 'important');

        if (box) {
          box.style.setProperty('background', checked ? fg : bg, 'important');
          box.style.setProperty('box-shadow', '0 0 0 0.5px ' + fg, 'important');
          box.style.setProperty('opacity', '1', 'important');
        }
        if (border) {
          border.style.setProperty('border', '1px solid ' + fg, 'important');
          border.style.setProperty('opacity', '1', 'important');
        }
        markNodes.forEach(function (node) {
          node.style.setProperty('color', bg, 'important');
          node.style.setProperty('fill', bg, 'important');
          node.style.setProperty('stroke', bg, 'important');
          node.style.setProperty('opacity', '1', 'important');
        });
        if (label) {
          label.style.setProperty('color', fg, 'important');
          label.style.setProperty('font-weight', '500', 'important');
          label.style.setProperty('opacity', '1', 'important');
          label.querySelectorAll('*').forEach(function (node) {
            node.style.setProperty('color', fg, 'important');
            node.style.setProperty('font-weight', '600', 'important');
            node.style.setProperty('opacity', '1', 'important');
          });
        }
      }

      function applyAuthContrast() {
        document.querySelectorAll('#app .n-checkbox').forEach(paintCheckbox);
      }

      document.addEventListener('click', function () {
        setTimeout(applyAuthContrast, 0);
        setTimeout(applyAuthContrast, 80);
      }, true);
      window.addEventListener('hashchange', function () {
        setTimeout(applyAuthContrast, 120);
        setTimeout(applyAuthContrast, 600);
      });
      document.addEventListener('DOMContentLoaded', applyAuthContrast);
      setTimeout(applyAuthContrast, 300);
      setTimeout(applyAuthContrast, 1000);
      setTimeout(applyAuthContrast, 2500);

      if (window.MutationObserver) {
        new MutationObserver(applyAuthContrast).observe(document.documentElement, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['class', 'data-theme']
        });
      }
    })();
  </script>
  <script src="/assets/u/ux-state.js?v=__ASSET_VER__"></script>
  {!! $theme_config['custom_html'] ?? '' !!}
</body>

</html>
BLADE

sed -i "s/__ASSET_VER__/$ASSET_VER/g" "$SRC/dashboard.blade.php"

APP_NAME=$(php <<'PHP'
<?php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = trim((string) admin_setting('app_name', ''));
if ($name === '') { $name = '控制台'; }  // 中性 fallback，避免空白 title 在 GFW 视角异常显眼
echo htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
PHP
)

cat > "$SRC/index.html" <<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no"><meta http-equiv="Content-Security-Policy" content="script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com https://yue.yuebao.website; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://cloudflareinsights.com https://*.cloudflareinsights.com https://yue.yuebao.website; object-src 'none'; base-uri 'self'; form-action 'self'" /><meta name="robots" content="noindex,nofollow,noarchive" /><meta name="referrer" content="same-origin" /><link rel="icon" href="/favicon.ico" /><title>__APP_NAME__</title><script>if(!window.location.hash){window.location.replace(window.location.pathname+window.location.search+'#/login');}</script><script type="module" crossorigin src="/assets/u/app-core.js?v=__ASSET_VER__"></script></head><body><div id="app"></div><script src="/assets/u/ux-state.js?v=__ASSET_VER__"></script></body></html>
HTML

sed -i "s/__ASSET_VER__/$ASSET_VER/g" "$SRC/index.html"
APP_NAME="$APP_NAME" python3 -c '
import os, sys
p = sys.argv[1]
with open(p) as f: s = f.read()
s = s.replace("__APP_NAME__", os.environ.get("APP_NAME", ""))
with open(p, "w") as f: f.write(s)
' "$SRC/index.html"

php <<'PHP'
<?php
$theme = getenv('THEME') ?: 'Portal';
$file = "/www/storage/theme/{$theme}/config.json";
$cfg = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
$cfg['name'] = $theme;
$cfg['title'] = '';
$cfg['description'] = '';
file_put_contents($file, json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
PHP

mkdir -p "$PUBLIC_THEME"
cp "$SRC/config.json" "$PUBLIC_THEME/config.json"
cp "$SRC/dashboard.blade.php" "$PUBLIC_THEME/dashboard.blade.php"
cp "$SRC/index.html" "$PUBLIC_THEME/index.html"
rm -rf /www/public/theme/LiquidGlass

cat > /tmp/portal-settings.tinker <<'PHP'
$theme = getenv('THEME') ?: 'Portal';
$lowerTheme = strtolower($theme);
$cols = \Illuminate\Support\Facades\Schema::getColumnListing('v2_settings');
$keyCol = in_array('key', $cols, true) ? 'key' : (in_array('name', $cols, true) ? 'name' : 'key');
$valueCol = in_array('value', $cols, true) ? 'value' : 'value';
$readSetting = function ($key) use ($keyCol, $valueCol) {
    $row = \Illuminate\Support\Facades\DB::table('v2_settings')->where($keyCol, $key)->first();
    if (!$row) {
        return null;
    }
    $value = $row->{$valueCol};
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $value;
    }
    return $value;
};
$existing = [];
foreach (['theme_' . $theme, 'theme_' . $lowerTheme] as $key) {
    $value = $readSetting($key);
    if (is_array($value)) {
        $existing = array_merge($existing, $value);
    }
}
$themeConfig = [
    'title' => '',
    'api_url' => $existing['api_url'] ?? '/',
    'custom_html' => $existing['custom_html'] ?? '',
    'theme_color' => $existing['theme_color'] ?? 'black',
    'background_url' => $existing['background_url'] ?? null,
    'show_payment_title' => $existing['show_payment_title'] ?? 'true',
    'hide_gift_card_menu' => $existing['hide_gift_card_menu'] ?? 'false',
    'description' => '',
];
$set = function ($key, $value) use ($keyCol, $valueCol) {
    \Illuminate\Support\Facades\DB::table('v2_settings')->updateOrInsert(
        [$keyCol => $key],
        [$valueCol => $value]
    );
};
$set('current_theme', $theme);
$set('frontend_theme', $theme);
$set('theme_' . $theme, json_encode($themeConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$set('theme_' . strtolower($theme), json_encode($themeConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
\Illuminate\Support\Facades\Cache::forget('admin_settings');
PHP

php /www/artisan tinker --execute="$(cat /tmp/portal-settings.tinker)"
rm -f /tmp/portal-settings.tinker

php /www/artisan view:clear || true
php /www/artisan config:clear || true
php /www/artisan cache:clear || true
CONTAINER

docker restart "$CONTAINER" >/dev/null
sleep 8
docker exec -e THEME="$THEME" -e ASSET_VER="$ASSET_VER" "$CONTAINER" sh -lc '
set -eu
for f in \
  "/www/storage/theme/$THEME/dashboard.blade.php" \
  "/www/public/theme/$THEME/dashboard.blade.php" \
  "/www/storage/theme/$THEME/index.html" \
  "/www/public/theme/$THEME/index.html"
do
  [ -f "$f" ] || continue
  sed -i -E "s#(app-core\\.js\\?v=)[A-Za-z0-9._-]*#\\1$ASSET_VER#g; s#(ux-state\\.js\\?v=)[A-Za-z0-9._-]*#\\1$ASSET_VER#g" "$f"
done
php /www/artisan view:clear >/dev/null || true
php /www/artisan cache:clear >/dev/null || true
grep -n "ux-state" "/www/storage/theme/$THEME/dashboard.blade.php" | tail -1
'
REMOTE

echo "Portal hardening complete"
