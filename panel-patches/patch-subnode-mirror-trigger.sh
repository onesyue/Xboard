#!/bin/bash
# 安装/重装 v2_server 子节点 mirror PG trigger
#
# 设计 invariant（详见 memory/feedback_panel_subnode_mirror_main.md）：
#   parent_id IS NOT NULL 子节点 = 主节点的 UI 别名（"线路 3/4"），后端 xboard-node
#   只 pull 主节点 config。子节点 protocol_settings 任何字段（reality short_id /
#   hy2 bandwidth / SNI / keypair）与父不一致 → 子节点客户端 100% 握手失败。
#
# 触发器幂等（DROP IF EXISTS + CREATE）—— 升级时强制重装，防 cedar2025 上游
# schema migration 副作用导致 trigger 丢失。
#
# 部署位置：/home/xboard/yue-to/patch-subnode-mirror-trigger.sh
# 调用者：xboard-upgrade.sh Step 5.95
# 事实源 SQL：v2_server_subnode_mirror_trigger.sql（同目录）
#
# 单独跑：cd /home/xboard/yue-to && bash patch-subnode-mirror-trigger.sh
set -euo pipefail

SQL_FILE="${SQL_FILE:-/home/xboard/yue-to/v2_server_subnode_mirror_trigger.sql}"
WEB_CTR="${WEB_CTR:-yue-to-web-1}"

if [ ! -f "$SQL_FILE" ]; then
  echo "[patch-subnode-mirror-trigger] FATAL: $SQL_FILE not found" >&2
  echo "  sync via: bash /opt/yueops/scripts/sync-xboard-patches.sh" >&2
  exit 1
fi

echo "[patch-subnode-mirror-trigger] copy SQL into $WEB_CTR (via stdin — tmpfs/29.x docker cp bug workaround)..."
cat "$SQL_FILE" | docker exec -i "$WEB_CTR" sh -c "cat > /tmp/subnode-mirror-trigger.sql"

echo "[patch-subnode-mirror-trigger] (re)install triggers via tinker..."
docker compose exec -T web php artisan tinker --execute='
$sql = file_get_contents("/tmp/subnode-mirror-trigger.sql");
DB::unprepared($sql);
$n = DB::table("pg_trigger")->whereIn("tgname", ["v2_server_subnode_mirror_trg","v2_server_parent_cascade_trg"])->count();
echo "  triggers active: $n/2\n";
$mismatch = DB::selectOne("SELECT COUNT(*)::int AS c FROM v2_server c JOIN v2_server p ON c.parent_id = p.id WHERE c.protocol_settings::jsonb != p.protocol_settings::jsonb")->c;
echo "  subnode mismatches: $mismatch\n";
if ($n != 2) { fwrite(STDERR, "FATAL trigger count $n != 2\n"); exit(1); }
if ($mismatch > 0) {
  echo "  cascading parent → children to clear $mismatch mismatch(es)...\n";
  DB::statement("UPDATE v2_server c SET protocol_settings = p.protocol_settings FROM v2_server p WHERE c.parent_id = p.id AND c.protocol_settings::jsonb != p.protocol_settings::jsonb");
  $after = DB::selectOne("SELECT COUNT(*)::int AS c FROM v2_server c JOIN v2_server p ON c.parent_id = p.id WHERE c.protocol_settings::jsonb != p.protocol_settings::jsonb")->c;
  echo "  after cascade: $after mismatch(es)\n";
  if ($after > 0) { fwrite(STDERR, "FATAL cascade did not converge\n"); exit(1); }
}
'

echo "[patch-subnode-mirror-trigger] ✓ done"
