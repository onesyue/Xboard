# Panel runtime patches (snapshot of `panel:/home/xboard/yue-to/`)

Snapshot date: **2026-05-26**

This directory mirrors the runtime patch suite that lives on the production panel
(`66.55.76.208:/home/xboard/yue-to/`). It exists in the fork so the **fork repo
is the single source of truth** for both image-inlined fixes and image-external
runtime operations. Going forward, any change to these scripts should be committed
here first, then `bash scripts/sync-xboard-patches.sh` pushes them to the panel.

## Classification

### Image-inlined (these scripts can be retired after the next migration sweep)

All the PHP/Sh fixes below have been verified inlined into the image at fork commit
`4d6e920` (image digest `sha256:8ceef2e420dd...`). The scripts are still present
on the panel because `restore-xboard-runtime-patches.sh` references them in the
fallback path, but the marker check passes so they don't run in steady state.

- `patch-models.sh` — Model `$casts` for PG numeric/bigint columns
- `patch-security.sh` — 14-fix bundle (SQL injection guards, addBalance DB tx, Ticket reorder, TG plugin reorder)
- `patch-pgsql-stability.sh` — secondary `orderBy('id')` tiebreaks
- `patch-pgsql-ilike.php` — `like` → `ilike` for PG case-insensitive search
- `patch-pgsql-runtime-compat.php` — AbstractProtocol null guard, BackupDatabase pgsql, Plugin config cast
- `patch-loon-upload-bandwidth.{sh,php}` — Loon `upload-bandwidth=` line
- `patch-online-stats.php` (v1) — already in image
- `patch-online-stats-v2.php` — **inlined 2026-05-26** (commit pending)
- `patch-balance-tracking.php` — `[Patch BAL]` user_account.balance sync
- `patch-commission-tier-hook.php` — `[Patch CT Rate]` invite filter
- `patch-classmeta-xbid.sh` — `xb_server_id` field on every clashmeta proxy
- `patch-clashmeta-dangling-ref.sh` — P0b cleanup of stale group references (inlined `4d6e920`)
- `patch-reset-disconnect.php` — subscription-reset disconnect propagation

### Image-external (must remain runtime patches; cannot be inlined)

These manipulate state that's outside the PHP image — host nginx, DB triggers,
plugin install, DB-stored templates, mounted theme assets, or are read-only audit.

- `patch-singbox-placeholder.sh` — read-only verification gate, prevents regression
- `patch-subnode-mirror-trigger.sh` — installs PG `v2_server_subnode_mirror_trg`
- `patch-subscribe-templates.sh` — writes 6 rows into `v2_subscribe_templates`
- `patch-xboard-nginx.sh` — host `/home/nginx/conf.d/*.conf` (outside container)
- `patch-invite-alias.sh` — InviteAlias plugin install + internal_token + widget
- `harden-xboard-portal-theme.sh` — rebuilds `/www/storage/theme/Portal/` bind mount

### Tooling

- `restore-xboard-runtime-patches.sh` — fallback orchestrator (runs only when a marker drifts)
- `sync-xboard-patches.sh` — bastion → panel rsync
- `pre-upgrade-check.sh` — upstream cedar2025 drift check
- `upgrade.sh` — image pull + container recreate + sequence orchestrator
- `check-xboard-runtime-integrity.sh` — post-restart integrity audit
- `install-xboard-runtime-guard.sh` — installs systemd timer
- `patch-verify-pgsql-casts.php` — read-only audit (move under `audit/` long-term)
- `sync-child-nodes.sh` — orthogonal node-side maintenance
- `patch-stash-include-all-renderer.sh` — Stash rendering compatibility check

## `docker cp` ↔ tmpfs

`docker cp` (and `docker compose cp`) **cannot write into a tmpfs or bind mount**
inside a container — see [moby/moby#22020](https://github.com/moby/moby/issues/22020)
(by-design since 2016). Docker 29.x security hardening (CVE-2026-41567 TOCTOU fixes)
reinforced this.

All scripts that write into container paths use stdin redirect or `tar | docker exec -i tar -xC`:

```bash
# Single file (tmpfs-safe)
cat src | docker exec -i CONTAINER sh -c 'cat > /tmp/dst'

# Directory tree, preserves permissions
tar -cC SRCDIR NAMES... | docker exec -i CONTAINER tar -xC /tmp/staging
```

`patch-subscribe-templates.sh` and `patch-subnode-mirror-trigger.sh` were converted
on 2026-05-26 after the panel disk-full incident exposed this bug. See `MAINTENANCE.md`
for full context.
