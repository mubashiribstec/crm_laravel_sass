#!/usr/bin/env bash
# Update an installed CRM from GitHub, safely:
#
#   sudo bash deploy/update.sh               # in the CRM folder (or: sudo bash /var/www/crm_laravel_sass/deploy/update.sh)
#   sudo bash deploy/update.sh --no-backup   # skip the database backup
#   sudo bash deploy/update.sh --check       # only show what would come in; change nothing
#
# - git: fast-forward only. Local edits and untracked files are kept; if an incoming change
#   touches a file you edited, it stops before changing anything. Never reset, clean or stash.
# - Never touches .env, uploads, storage data or public/storage, and never drops a table.
# - Database backup (mysqldump, gzipped, /var/backups/crm/, last 5 kept) before any new migration.
# - Maintenance mode only while migrating.
set -Eeuo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${CRM_DIR:-$(cd "$SELF_DIR/.." && pwd)}"; APP_DIR="${APP_DIR%/}"
# shellcheck source=deploy/lib.sh
. "$SELF_DIR/lib.sh"
BACKUP_DIR="${CRM_BACKUP_DIR:-/var/backups/crm}"
KEEP_BACKUPS="${CRM_KEEP_BACKUPS:-5}"

NO_BACKUP=0; CHECK=0
for a in "$@"; do
  case $a in
    --no-backup) NO_BACKUP=1 ;;
    --check) CHECK=1 ;;
    -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
    *) die "Unknown option: $a" ;;
  esac
done

trap on_error ERR
need_root
start_log
prepare_owner

step "Checking the installation"
[ -f "$APP_DIR/artisan" ] || die "$APP_DIR is not the CRM."
[ -d "$APP_DIR/.git" ] || die "$APP_DIR is not a git clone; update.sh needs one."
[ -f "$APP_DIR/.env" ] || die "$APP_DIR/.env is missing. Restore it (or run deploy/install.sh for a new install)."
ok "$APP_DIR (owner $APP_OWNER)"

# ── 1. Code ───────────────────────────────────────────────────────────────────────────────────
step "Fetching updates"
BRANCH="$(as_owner git -c safe.directory="$APP_DIR" -C "$APP_DIR" symbolic-ref --short -q HEAD || true)"
[ -n "$BRANCH" ] || die "The checkout is not on a branch (detached HEAD); check out main first."
REMOTE="$(git_app config "branch.$BRANCH.remote" || echo origin)"
as_owner git -c safe.directory="$APP_DIR" -C "$APP_DIR" fetch --quiet "$REMOTE" "$BRANCH"
OLD="$(git_app rev-parse HEAD)"; NEW="$(git_app rev-parse FETCH_HEAD)"
LOCAL_CHANGES="$(git_app status --porcelain --untracked-files=no | wc -l)"
UNTRACKED="$(git_app status --porcelain | grep -c '^??' || true)"
ok "branch $BRANCH at ${OLD:0:7}; $REMOTE has ${NEW:0:7}"
[ "$LOCAL_CHANGES" -gt 0 ] && warn "$LOCAL_CHANGES locally edited file(s) (kept): $(git_app status --porcelain --untracked-files=no | awk '{print $2}' | head -5 | tr '\n' ' ')"
[ "$UNTRACKED" -gt 0 ] && ok "$UNTRACKED untracked file(s)/folder(s) (kept, not touched)"

if [ "$OLD" = "$NEW" ]; then
  ok "already up to date"
elif git_app merge-base --is-ancestor "$NEW" "$OLD"; then
  ok "this checkout is ahead of $REMOTE/$BRANCH; nothing to pull"
elif ! git_app merge-base --is-ancestor "$OLD" "$NEW"; then
  die "This checkout has commits that $REMOTE/$BRANCH doesn't (it diverged). Nothing was changed. Ask a developer to merge them."
else
  echo "  Incoming:"; git_app log --oneline --no-decorate "$OLD..$NEW" | head -20 | sed 's/^/    /'
  CONFLICTS="$(comm -12 <(git_app diff --name-only "$OLD" "$NEW" | sort) \
      <( (git_app diff --name-only; git_app diff --name-only --cached; git_app ls-files --others --exclude-standard) | sort -u))"
  if [ -n "$CONFLICTS" ]; then
    printf '  Files you changed locally that the update also changes:\n%s\n' "$(echo "$CONFLICTS" | sed 's/^/    /')"
    die "Stopped before changing anything. Keep a copy of those files, then: git checkout -- <file> (or commit them), and run update.sh again."
  fi
  if [ "$CHECK" = 1 ]; then ok "check only: nothing changed"; exit 0; fi
  as_owner git -c safe.directory="$APP_DIR" -C "$APP_DIR" merge --ff-only --quiet "$NEW"
  ok "code updated to ${NEW:0:7}"
fi
[ "$CHECK" = 1 ] && { ok "check only: nothing changed"; exit 0; }

# ── 2. Dependencies and assets ────────────────────────────────────────────────────────────────
step "PHP dependencies"
fix_writable
composer_install

step "Front-end assets"
build_assets

# ── 3. Database ───────────────────────────────────────────────────────────────────────────────
step "Database"
clear_compiled
PENDING="$(artisan migrate:status --pending 2>/dev/null | grep -c 'Pending' || true)"
if [ "$PENDING" -gt 0 ]; then
  ok "$PENDING new migration(s): $(artisan migrate:status --pending 2>/dev/null | awk '/Pending/ {print $1}' | tr '\n' ' ')"
  if [ "$NO_BACKUP" = 0 ]; then
    have mysqldump || have mariadb-dump || die "mysqldump is missing (apt-get install mariadb-client), or run with --no-backup."
    DUMP="$(command -v mariadb-dump || command -v mysqldump)"
    DB_NAME="$(env_get DB_DATABASE)"
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    CNF="$(mktemp)"; chmod 600 "$CNF"; trap 'rm -f "$CNF"; rm -rf "$ARTISAN_HOME"' EXIT
    printf '[client]\nuser=%s\npassword="%s"\nhost=%s\nport=%s\n' "$(env_get DB_USERNAME)" \
      "$(env_get DB_PASSWORD | sed 's/\\/\\\\/g; s/"/\\"/g')" "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$CNF"
    SIZE_MB="$(mariadb --defaults-extra-file="$CNF" -N -e "SELECT ROUND(SUM(data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema='${DB_NAME//\'/}'" 2>/dev/null || echo 0)"
    FREE_MB="$(df -Pm "$BACKUP_DIR" | awk 'NR==2 {print $4}')"
    [ "${SIZE_MB:-0}" = NULL ] && SIZE_MB=0
    [ "$FREE_MB" -gt "$(( ${SIZE_MB:-0} / 2 + 200 ))" ] || die "Not enough free space in $BACKUP_DIR for a backup (${FREE_MB} MB free, database ~${SIZE_MB} MB). Free some space or use --no-backup."
    FILE="$BACKUP_DIR/${DB_NAME}-$(date +%Y%m%d-%H%M%S)-before-${NEW:0:7}.sql.gz"
    "$DUMP" --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB_NAME" \
      | gzip > "$FILE" || { rm -f "$FILE"; die "The database backup failed; nothing was migrated."; }
    chmod 600 "$FILE"
    ok "backup: $FILE ($(du -h "$FILE" | cut -f1))"
    ls -1t "$BACKUP_DIR"/"${DB_NAME}"-*.sql.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f
  else
    warn "database backup skipped (--no-backup)"
  fi
  artisan down --retry=60 >/dev/null && ok "maintenance mode on"
  if ! artisan migrate --force; then
    printf '\n%s✗ A migration failed. The site stays in maintenance mode so nobody works on a half-migrated database.%s\n' "$R" "$N" >&2
    printf '  Fix the error above and run update.sh again, or restore the backup above.\n  To reopen anyway: cd %s && sudo -u %s php artisan up\n' "$APP_DIR" "$WEB_USER" >&2
    exit 1
  fi
  ok "migrations done"
else
  ok "no new migrations (no backup needed)"
fi
artisan crm:sync-permissions >/dev/null && ok "permissions in sync (super_admin has all)"

# ── 4. Files, caches, services ────────────────────────────────────────────────────────────────
step "Folders, scheduler and caches"
fix_writable
ensure_storage_link
ensure_cron
artisan optimize:clear >/dev/null
artisan optimize >/dev/null && ok "caches rebuilt"
fix_writable
artisan up >/dev/null 2>&1 && ok "site is live"
reload_php

step "Health check"
HEALTH=ok; health_check "$(env_get APP_URL)" || HEALTH=failed
printf '\n%s%s CRM updated to %s%s\n' "$B" "$([ "$HEALTH" = ok ] && echo '✓' || echo '!')" "$(git_app rev-parse --short HEAD)" "$N"
if [ "${#WARNINGS[@]}" -gt 0 ]; then printf '  Warnings:\n'; printf '    - %s\n' "${WARNINGS[@]}"; fi
printf '  Log: %s\n' "$LOG_FILE"
[ "$HEALTH" = ok ]
