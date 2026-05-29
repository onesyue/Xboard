#!/usr/bin/env bash
# Rebuild theme/Portal 的 ux-state.js —— 把各插件 widget 顺序拼接成单文件。
#
# ux-state.js 是历史兼容别名(对外不暴露 widget 真名,去特征)。
# 顺序固定: CommissionTier → InviteAlias → ChangeEmail → YueOnlineCount。
#
# 何时跑: 改了任一 widget 源(theme/Portal/widgets/*.js)之后。
# 跑完 git add public/assets/u/ux-state.js + 对应 widget 源, commit, push → GHA 自动 build 烘焙进镜像。
# 缓存失效: dashboard.blade.php 用 ?v={{ $version }}, 每次发版(镜像 version=YYYY.MM.DD-sha)自动 bust, 无需手动改版本号。
set -euo pipefail
cd "$(dirname "$0")"

OUT=../../public/assets/u/ux-state.js
: > "$OUT"
for w in \
  widgets/commission-tier-widget.js \
  widgets/invite-alias-widget.js \
  widgets/change-email-widget.js \
  widgets/yue-online-count-widget.js \
; do
  [ -f "$w" ] || { echo "FATAL: missing $w" >&2; exit 1; }
  printf '/* === %s === */\n' "$(basename "$w")" >> "$OUT"
  cat "$w" >> "$OUT"
  printf '\n' >> "$OUT"
done
echo "[ok] rebuilt $OUT ($(wc -c < "$OUT") bytes)"
