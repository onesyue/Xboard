#!/usr/bin/env bash
# patch-xboard-nginx.sh
#
# 把 git 仓库 xboard-nginx/{default,node-api}.conf 同步到 panel 的
# /home/nginx/conf.d/ 下。**git 是事实源**。
#
# 行为：
#   - 算每个 .conf 的 md5 vs 现有文件 md5
#   - 不一致：先备份当前文件到 .conf.bak-pre-sync-<ts>，再写入新内容
#   - 一致：跳过
#   - 任何写入后 `docker exec nginx nginx -t`，OK 才 reload；失败回滚备份
#
# 幂等：第二次跑全部 [skip]。
#
# 用法（panel /home/xboard/yue-to/）：
#   bash patch-xboard-nginx.sh
#
set -euo pipefail
cd /home/xboard/yue-to

NGINX_SRC_DIR="${NGINX_SRC_DIR:-/home/xboard/yue-to/xboard-nginx}"
NGINX_DEST_DIR="${NGINX_DEST_DIR:-/home/nginx/conf.d}"
[ -d "$NGINX_SRC_DIR" ] || { echo "FATAL: $NGINX_SRC_DIR not found" >&2; exit 1; }
[ -d "$NGINX_DEST_DIR" ] || { echo "FATAL: $NGINX_DEST_DIR not found" >&2; exit 1; }

ts=$(date +%Y%m%d%H%M%S)
changed=()
backups=()

for src in "$NGINX_SRC_DIR"/*.conf; do
  base=$(basename "$src")
  dest="$NGINX_DEST_DIR/$base"
  src_md5=$(md5sum "$src" | awk '{print $1}')

  if [ -f "$dest" ]; then
    dest_md5=$(md5sum "$dest" | awk '{print $1}')
    if [ "$src_md5" = "$dest_md5" ]; then
      echo "  [skip] $base in sync ($src_md5)"
      continue
    fi
    bak="$dest.bak-pre-sync-$ts"
    cp -p "$dest" "$bak"
    backups+=("$bak")
    echo "  [drift] $base $dest_md5 -> $src_md5 (backup: $(basename $bak))"
  else
    echo "  [new]   $base $src_md5"
  fi
  cp "$src" "$dest"
  changed+=("$base")
done

if [ ${#changed[@]} -eq 0 ]; then
  echo "[ok] all nginx confs in sync"
  exit 0
fi

echo "[verify] nginx -t..."
if docker exec nginx nginx -t; then
  echo "[reload] nginx -s reload"
  docker exec nginx nginx -s reload
  echo "[ok] nginx confs sync done. Changed: ${changed[*]}; backup ts=$ts"
else
  echo "[FATAL] nginx -t failed; rolling back changes" >&2
  for bak in "${backups[@]}"; do
    orig="${bak%.bak-pre-sync-*}"
    cp -p "$bak" "$orig"
    echo "  rolled back: $(basename $orig)"
  done
  echo "  current nginx config restored; review $NGINX_SRC_DIR vs $NGINX_DEST_DIR" >&2
  exit 1
fi
