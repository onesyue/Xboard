# Yue Production Notes

This fork keeps `onesyue/Xboard:master` as the production source branch.
The sample compose files use `ghcr.io/onesyue/xboard:latest` as the moving
production alias. Each build also publishes immutable date and SHA tags; record
the deployed digest before updates so rollback is deterministic.

## Source-owned fixes

These production patches are now intended to live in source code:

- PostgreSQL model casts for numeric, boolean and JSON fields.
- Plugin config array compatibility for PostgreSQL JSON/JSONB hydration.
- PostgreSQL `ilike` search behavior and admin filter field validation.
- Stable PostgreSQL tie-break ordering for affected paginated lists.
- PostgreSQL database backup support.
- Null-safe client version checks in protocol filtering.
- Loon HY2 `upload-bandwidth` output.
- ClashMeta `xb_server_id` output for telemetry binding.
- Online stats based on `last_online_at` and `online_count`.
- Reset-subscription disconnect propagation through node sync.
- Balance tracking adjustment for `user_account`.
- CommissionTier invite hooks.
- Ticket message ordering and Telegram ticket notification ordering.
- Theme refresh tolerance when the mounted theme path is absent.

## Deployment-owned assets

These should stay outside the image because they operate on host state,
database state, mounted plugin/theme data, or nginx configuration:

- `patch-subscribe-templates.sh`
- `patch-xboard-nginx.sh`
- `harden-xboard-portal-theme.sh`
- `patch-invite-alias.sh`
- `patch-subnode-mirror-trigger.sh`
- `v2_server_subnode_mirror_trigger.sql`
- `patch-singbox-placeholder.sh` as an upgrade gate
- `check-xboard-runtime-integrity.sh`
- `upgrade.sh` / pre-upgrade checks
- the systemd runtime guard until the new image has survived at least one
  real production upgrade

## Retire candidates

After production is switched to a verified `ghcr.io/onesyue/xboard:latest`
image built from this fork and the integrity gate passes, these old runtime
patchers should no longer be necessary:

- `patch-pgsql-runtime-compat.php`
- `patch-models.sh`
- `patch-pgsql-ilike.php`
- `patch-online-stats.php`
- `patch-loon-upload-bandwidth.sh`
- `patch-loon-upload-bandwidth.php`
- `patch-classmeta-xbid.sh`
- `patch-balance-tracking.php`
- `patch-reset-disconnect.php`
- `patch-commission-tier-hook.php`
- `patch-pgsql-stability.sh`
- the source-editing parts of `patch-security.sh`

Do not delete those files from the production host until the new image is
deployed and rollback has been tested.
