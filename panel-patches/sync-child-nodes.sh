#!/bin/bash
# Sync parent node updated_at to child nodes every minute
PGPASSWORD="jim@8858" psql -h 23.80.91.14 -p 5432 -U root -d yue-to -c "
UPDATE v2_server c
SET updated_at = p.updated_at
FROM v2_server p
WHERE c.parent_id = p.id
  AND c.parent_id IS NOT NULL
  AND (c.updated_at IS NULL OR c.updated_at < p.updated_at);
" 2>/dev/null
