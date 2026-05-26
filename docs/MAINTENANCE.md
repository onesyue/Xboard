# Fork Maintenance Strategy

This fork (`onesyue/Xboard`) tracks `cedar2025/Xboard` upstream and adds yueops-specific
hardening + business fixes. This document is the long-term playbook so the fork doesn't
drift into patch-hell.

## What lives where

```
fork master                        — upstream + image-inline business fixes
panel /opt/yueops/scripts/         — image-EXTERNAL ops only (see below)
panel /home/xboard/yue-to/         — runtime copy of bastion scripts + .env + plugins/
ghcr.io/onesyue/xboard:latest      — auto-built by .github/workflows/docker-publish.yml
```

### Inlined into the fork image (in this repo, master branch)

| Subsystem | Files | Rationale |
|---|---|---|
| Model casts (PG bigint/numeric→string) | `app/Models/{Order,Stat,User,Plan,Ticket,Server,Plugin}.php` | Without explicit casts, PG returns strings for numeric columns; Laravel + JS clients break |
| PG ILIKE + field validation | `app/Traits/QueryOperators.php` | upstream uses `LIKE` which is case-sensitive on PG |
| Balance tracking ([Patch BAL]) | `app/Services/UserService.php` | sync `user_account.balance` for monthly clear |
| Loon `upload-bandwidth=` | `app/Protocols/Loon.php` | upstream didn't include it; client mis-detects bandwidth |
| ClashMeta `xb_server_id` | `app/Protocols/ClashMeta.php` | YueLink reads this field to bind fp→server |
| ClashMeta dangling-group cleanup (P0b) | `app/Protocols/ClashMeta.php` | mihomo `proxy group not found` E006 — see commit `4d6e920` |
| AbstractProtocol `clientVersion !== null` guard | `app/Support/AbstractProtocol.php` | upstream throws on null |
| Plugin config array compat | `app/Services/Plugin/{PluginManager,PluginConfigService}.php`, `app/Traits/HasPluginConfig.php`, `app/Models/Plugin.php` | upstream cast inconsistency |
| BackupDatabase pgsql support | `app/Console/Commands/BackupDatabase.php` | upstream only had mysql |

### NOT inlined — kept as runtime patches in `/opt/yueops/scripts/`

Operations that touch host files **outside** the container, DB-level objects, or
non-image plugins. They run on the panel host post-image-update.

| Script | Why not inlinable |
|---|---|
| `patch-xboard-nginx.sh` | panel host `/home/nginx/conf.d/` — outside container |
| `patch-subscribe-templates.sh` | writes 6 rows into `v2_subscribe_templates` DB table |
| `patch-subnode-mirror-trigger.sh` | installs PG `v2_server_subnode_mirror_trg` trigger |
| `patch-invite-alias.sh` | installs InviteAlias plugin row + token + widget alias |
| `harden-xboard-portal-theme.sh` | writes to `storage/theme/Portal/` bind mount |

`restore-xboard-runtime-patches.sh` is the panel-side fallback: if a marker drifts
(e.g. image rolled back), it re-applies the runtime patches. Triggered every 5min
by `yue-to-runtime-patches.timer`. **Markers being all-present is the happy path.**

## docker cp + tmpfs (known by-design issue, not a 29.x regression)

`docker cp` works via chroot into the container rootfs and **cannot see through
tmpfs / bind mounts** — it sees the masked directory underneath and reports
`Could not find the file`. This has been documented since moby/moby#22020 (2016)
and Docker 29.x security hardening (CVE-2026-41567/42306 TOCTOU fixes) reinforced it.

**Workaround pattern** (use everywhere):

```bash
# Single file (works for tmpfs targets):
cat src.txt | docker exec -i <container> sh -c 'cat > /tmp/dst.txt'

# Multi-file with permissions preserved (preferred for directories):
tar -cC <srcdir> <names...> | docker exec -i <container> tar -xC /tmp/staging
```

Every fix script that needs to write into a container path **must** use this pattern.
Never use `docker cp HOST:f → CONTAINER:f` or `docker compose cp` for a tmpfs target.

## Upstream tracking

| Upstream change | Status / our action |
|---|---|
| 5/22 `fix(security): harden email code validation` ×2 | merged in `9140e2e` (5/26) |
| 5/25 `feat: add admin user plugin hooks` | merged in `9140e2e` |
| 4/19 `refactor: all-in-one docker deployment` | **NOT merged** — our compose has tmpfs/mem-limit customizations; revisit |
| 4/23 `refactor(middleware): split V2 server middleware to drop node_type` | NOT merged — verify our agents survive |
| 4/29 v2board migration docker commands | not relevant (we never v2board-migrated) |
| **PR #760 (in review) "Supports database migration"** | **HIGH PRIORITY watch** — when merged, Xboard will use `php artisan migrate` instead of manual SQL. Our yueops migration runner needs to coexist. |

## Image inline retirement

Every image-inlined fix listed above should be reviewed every 6 months for one of:

- **(a) Upstream**: open a PR on `cedar2025/Xboard`. Upstream is friendly to merges.
  E.g. `Patch BAL` could become an upstream hook.
- **(b) Demote to runtime patch**: if the fix is yueops-business-specific and
  upstream rightly rejects it (e.g. CommissionTier hook, InviteAlias).

The goal: fork master `diff cedar2025/master` should stay under 500 lines of
real business code (plus CI/Dockerfile/README which can drift freely).

## Pre-upgrade-check should grow

Add to `panel:/home/xboard/yue-to/pre-upgrade-check.sh` (currently scoped to upstream
fork-aware comparison only):

1. **compose drift**: diff upstream `compose.sample.yaml` vs panel `compose.yaml`
   beyond expected overlay (tmpfs, mem_limit, image). Fail if upstream restructured.
2. **migration baseline**: when PR #760 lands, check
   `database/migrations/*` introduced count vs `applied_migrations` table.
3. **YueOps runtime markers exit-clean check**: spawn restore.sh in `--check-only`
   mode and abort upgrade if any marker still missing after applying.

## Release process

1. `git pull upstream master && git merge upstream/master` → resolve conflicts
2. inspect `git diff` for surprises in `app/`, `database/migrations/`, `compose.*`
3. push `master` → GHA `docker-publish.yml` auto-builds + pushes `ghcr.io/onesyue/xboard:latest`
4. on panel: `cd /home/xboard/yue-to && SKIP_CHECK=1 bash upgrade.sh`
   (SKIP_CHECK only because pre-upgrade-check.sh polls upstream image which we don't pull)
5. health gate (HTTP + assets + redis + smoke)
6. if issues: pin to previous digest in `compose.yaml` and `docker compose up -d`

## Rollback

Every upgrade writes a pin file:
```
/var/backups/xboard-upgrade/rollback-pin-<utc_ts>.txt
```

To roll back:
```bash
cd /home/xboard/yue-to
# Replace `image: ghcr.io/onesyue/xboard:latest`
# with     `image: ghcr.io/onesyue/xboard@sha256:<old_digest_from_pin>`
docker compose up -d web ws horizon
bash restore-xboard-runtime-patches.sh   # in case markers regress
```
