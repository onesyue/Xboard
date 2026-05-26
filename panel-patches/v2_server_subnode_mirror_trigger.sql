-- v2_server 子节点（parent_id IS NOT NULL）protocol_settings 强 mirror 父节点。
--
-- 设计意图（详见 memory/feedback_panel_subnode_mirror_main.md）：
--   parent_id 子节点 = 主节点的 UI 别名（"线路 3/4"），后端 xboard-node 只 pull 主节点 config。
--   子节点任何 protocol_settings 字段（尤其 reality short_id / hy2 bandwidth）与父不一致 → 子节点
--   客户端 100% 握手失败 fallback 到 decoy 域名，用户感知"节点连不上"。
--
-- 触发器：
--   1) v2_server_subnode_mirror_trg  — BEFORE INSERT/UPDATE：子节点写入时 protocol_settings
--      被强覆盖为父节点 protocol_settings（admin UI 改子节点字段会被静默忽略）。
--   2) v2_server_parent_cascade_trg  — AFTER UPDATE：父节点 protocol_settings 变更时级联
--      推送到所有子节点。
--
-- 部署目标：XBoard panel DB (23.80.91.14:5432/yue-to)
-- 重装：psql -h 127.0.0.1 -U root -d yue-to -f v2_server_subnode_mirror_trigger.sql
-- 审计：SELECT child.id, child.name FROM v2_server child JOIN v2_server parent
--         ON child.parent_id = parent.id
--         WHERE child.protocol_settings::jsonb != parent.protocol_settings::jsonb;
--       （应返回 0 行）

BEGIN;

CREATE OR REPLACE FUNCTION v2_server_subnode_mirror_fn() RETURNS trigger AS $$
DECLARE
    parent_settings jsonb;
BEGIN
    IF NEW.parent_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT protocol_settings::jsonb INTO parent_settings
      FROM v2_server WHERE id = NEW.parent_id;
    IF parent_settings IS NOT NULL THEN
        NEW.protocol_settings := parent_settings;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS v2_server_subnode_mirror_trg ON v2_server;
CREATE TRIGGER v2_server_subnode_mirror_trg
    BEFORE INSERT OR UPDATE ON v2_server
    FOR EACH ROW EXECUTE FUNCTION v2_server_subnode_mirror_fn();

CREATE OR REPLACE FUNCTION v2_server_parent_cascade_fn() RETURNS trigger AS $$
BEGIN
    IF NEW.parent_id IS NOT NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.protocol_settings::jsonb IS DISTINCT FROM OLD.protocol_settings::jsonb THEN
        UPDATE v2_server SET protocol_settings = NEW.protocol_settings
         WHERE parent_id = NEW.id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS v2_server_parent_cascade_trg ON v2_server;
CREATE TRIGGER v2_server_parent_cascade_trg
    AFTER UPDATE ON v2_server
    FOR EACH ROW EXECUTE FUNCTION v2_server_parent_cascade_fn();

COMMIT;
