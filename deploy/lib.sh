# shellcheck shell=bash
# Shared helpers for deploy/install.sh and deploy/update.sh. Sourced, not run.
# Nothing here prints a secret: values read from .env are only passed to programs, never echoed.

umask 022                       # new files: not group/world-writable unless set below
WEB_USER="${CRM_WEB_USER:-www-data}"
CRON_FILE="/etc/cron.d/crm-laravel"
LOG_FILE="${CRM_LOG_FILE:-/var/log/crm-deploy.log}"

if [ -t 1 ]; then B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'; else B=; G=; Y=; R=; N=; fi
STEP=""
step() { STEP="$*"; printf '\n%s==> %s%s\n' "$B" "$*" "$N"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; WARNINGS+=("$*"); }
die()  { printf '\n%s✗ %s%s\n' "$R" "$*" "$N" >&2; exit 1; }
WARNINGS=()

on_error() {
  local code=$?
  [ "${BASH_SUBSHELL:-0}" -gt 0 ] && exit "$code"     # report once, from the main script
  printf '\n%s✗ Failed during: %s (exit %s).%s\n  Full output: %s\n' "$R" "${STEP:-setup}" "$code" "$N" "$LOG_FILE" >&2
  exit "$code"
}

# Everything (stdout and stderr) also goes to the log file.
start_log() {
  mkdir -p "$(dirname "$LOG_FILE")"
  touch "$LOG_FILE" && chmod 600 "$LOG_FILE"
  exec 3>&1                      # the terminal only (for the one-time admin password)
  exec > >(tee -a "$LOG_FILE") 2>&1
  printf '\n----- %s %s -----\n' "$(date -Is)" "$0"
}

need_root() { [ "$(id -u)" -eq 0 ] || die "Run this as root: sudo bash $0"; }

have() { command -v "$1" >/dev/null 2>&1; }

# Services work with or without systemd (containers have none).
svc() {  # svc reload|restart|enable NAME
  local action=$1 name=$2
  if [ -d /run/systemd/system ]; then
    if [ "$action" = enable ]; then systemctl enable --now "$name" >/dev/null 2>&1 || true; else systemctl "$action" "$name"; fi
  else
    case $action in
      enable) service "$name" status >/dev/null 2>&1 || service "$name" start ;;
      reload) service "$name" reload 2>/dev/null || service "$name" restart ;;
      *)      service "$name" "$action" || true ;;
    esac
  fi
  # A restart can leave the service stopped (init-script race without systemd): start it again.
  if [ "$action" != enable ]; then
    local _
    for _ in 1 2 3 4 5; do svc_running "$name" && return 0; sleep 1; done
    if [ -d /run/systemd/system ]; then systemctl start "$name"; else service "$name" start; fi
    sleep 1; svc_running "$name" || die "$name is not running after $action (see its log)."
  fi
}
svc_running() { if [ -d /run/systemd/system ]; then systemctl is-active --quiet "$1"; else service "$1" status >/dev/null 2>&1; fi; }

# The checkout's owner runs git, composer and npm; artisan runs as the web user, so every file it
# writes under storage/ and bootstrap/cache stays writable by the site (the root cause of the
# "permission denied" errors after running artisan as root).
owner_of() { stat -c %U "$1"; }
as_owner() {
  if [ "$APP_OWNER" = root ]; then
    env COMPOSER_ALLOW_SUPERUSER=1 "$@"
  else
    runuser -u "$APP_OWNER" -- env HOME="$OWNER_HOME" COMPOSER_HOME="$OWNER_HOME/.composer" \
      npm_config_cache="$OWNER_HOME/.npm" PATH="$PATH" "$@"
  fi
}
# HOME/XDG point at a private temp folder: www-data's home (/var/www) isn't writable, and tinker
# (psysh) refuses to run without a config folder it can write.
ARTISAN_HOME="$(mktemp -d /tmp/crm-artisan.XXXXXX)"
trap 'rm -rf "$ARTISAN_HOME"' EXIT
artisan() {
  chown "$WEB_USER" "$ARTISAN_HOME"
  (cd "$APP_DIR" && runuser -u "$WEB_USER" -- env HOME="$ARTISAN_HOME" XDG_CONFIG_HOME="$ARTISAN_HOME" php artisan "$@")
}
# Before migrating: drop compiled config/routes/views/events only. optimize:clear would also empty
# the cache store, which is a database table that doesn't exist yet on a new database.
clear_compiled() { local c; for c in config:clear route:clear view:clear event:clear; do artisan "$c" >/dev/null; done; }
git_app() { git -c safe.directory="$APP_DIR" -C "$APP_DIR" "$@"; }

prepare_owner() {
  APP_OWNER="$(owner_of "$APP_DIR")"
  OWNER_HOME="$(getent passwd "$APP_OWNER" | cut -d: -f6)"
  if [ "$APP_OWNER" != root ] && { [ -z "$OWNER_HOME" ] || ! runuser -u "$APP_OWNER" -- test -w "$OWNER_HOME"; }; then
    OWNER_HOME="/var/cache/crm-deploy/$APP_OWNER"
    mkdir -p "$OWNER_HOME" && chown "$APP_OWNER" "$OWNER_HOME"
  fi
}

# Read one value from .env the way Laravel does (quotes, escapes), without printing it.
env_get() {
  (cd "$APP_DIR" && php -r '
    require "vendor/autoload.php";
    $v = Dotenv\Dotenv::createArrayBacked(getcwd())->safeLoad();
    echo $v[$argv[1]] ?? "";' "$1")
}

# Set KEY=VALUE in .env (replace the line or append). The value is double-quoted and escaped.
env_set() {
  local key=$1 val=$2 file="$APP_DIR/.env"
  python3 - "$file" "$key" "$val" <<'PY'
import re, sys
path, key, val = sys.argv[1], sys.argv[2], sys.argv[3]
q = val if re.fullmatch(r'[A-Za-z0-9_./:@,+-]*', val) else '"' + val.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'
lines = open(path, encoding='utf-8').read().splitlines()
pat = re.compile(r'^\s*#?\s*' + re.escape(key) + r'=')
for i, l in enumerate(lines):
    if pat.match(l):
        lines[i] = f'{key}={q}'
        break
else:
    lines.append(f'{key}={q}')
open(path, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
PY
}

# storage/ and bootstrap/cache: created if missing, owned by the web user, group-writable,
# setgid on directories. Only these two trees change; uploads elsewhere are not touched.
fix_writable() {
  local d
  for d in storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
           storage/framework/views storage/framework/testing storage/logs bootstrap/cache; do
    mkdir -p "$APP_DIR/$d"
  done
  chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  chmod -R u+rwX,g+rwX,o-w "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec chmod g+s {} +
  if [ -f "$APP_DIR/.env" ]; then
    chown "$APP_OWNER:$WEB_USER" "$APP_DIR/.env" && chmod 640 "$APP_DIR/.env"
  fi
  ok "storage/ and bootstrap/cache writable by $WEB_USER (no 777)"
}

# public/storage: a symlink to storage/app/public on a fresh install. A real folder (older
# installs keep files in it) is left exactly as it is.
ensure_storage_link() {
  local link="$APP_DIR/public/storage"
  if [ -L "$link" ]; then
    ok "public/storage is a link"
  elif [ -e "$link" ]; then
    warn "public/storage is a real folder ($(du -sh "$link" 2>/dev/null | cut -f1)); left as it is"
  else
    (cd "$APP_DIR" && php artisan storage:link >/dev/null) && ok "public/storage linked"
  fi
}

# Install or update the scheduler's cron entry (our own file only).
ensure_cron() {
  local want="# Laravel scheduler for the CRM (bulk emails and SMS, job status, attachment purge)
* * * * * $WEB_USER cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
  if [ ! -f "$CRON_FILE" ] || [ "$(cat "$CRON_FILE")" != "$want" ]; then
    printf '%s\n' "$want" > "$CRON_FILE" && chmod 644 "$CRON_FILE"
    ok "scheduler cron written to $CRON_FILE"
  else
    ok "scheduler cron already in place"
  fi
  svc enable cron
}

# Build only when the inputs changed (package files, resources, vite config) or no build exists.
build_hash() {
  (cd "$APP_DIR" && cat package.json package-lock.json vite.config.js 2>/dev/null; \
    find resources -type f \( -name '*.js' -o -name '*.scss' -o -name '*.css' -o -name '*.vue' -o -name '*.jsx' \) -print0 \
      | sort -z | xargs -0 cat 2>/dev/null) | sha256sum | cut -d' ' -f1
}
build_assets() {
  local force=${1:-}
  local h; h="$(build_hash)"
  if [ -z "$force" ] && [ -f "$APP_DIR/public/build/manifest.json" ] && [ "$(cat "$APP_DIR/public/build/.crm-build-hash" 2>/dev/null)" = "$h" ]; then
    ok "front-end assets are current (no rebuild needed)"
    return
  fi
  have node && have npm || die "Node.js and npm are needed for the front-end build (apt-get install nodejs npm)."
  local major; major="$(node -p 'process.versions.node.split(".")[0]')"
  [ "$major" -ge 18 ] || die "Node.js 18 or newer is needed (found $(node -v))."
  (cd "$APP_DIR" && as_owner npm ci --no-audit --no-fund) || die "npm ci failed (see $LOG_FILE)."
  (cd "$APP_DIR" && as_owner npm run build) || die "npm run build failed (see $LOG_FILE)."
  [ -s "$APP_DIR/public/build/manifest.json" ] || die "The build finished but public/build/manifest.json is missing."
  printf '%s\n' "$h" > "$APP_DIR/public/build/.crm-build-hash"
  ok "front-end built (public/build/manifest.json)"
}

composer_install() {
  have composer || die "Composer is missing (apt-get install composer)."
  (cd "$APP_DIR" && as_owner composer install --no-dev --optimize-autoloader --no-interaction --no-progress) \
    || die "composer install failed (see $LOG_FILE)."
  ok "PHP dependencies installed"
}

# Restart PHP so OPcache picks up new code: PHP-FPM if it runs, else Apache (mod_php).
reload_php() {
  local fpm
  fpm="$(find /etc/init.d -maxdepth 1 -name 'php*-fpm' -printf '%f\n' 2>/dev/null | sort -V | tail -1 || true)"
  if [ -n "$fpm" ] && svc_running "$fpm"; then svc reload "$fpm" && ok "$fpm reloaded"; fi
  if svc_running apache2; then apache2ctl configtest >/dev/null 2>&1 && svc reload apache2 && ok "apache2 reloaded"; fi
}

# GET /login through the local web server with the site's Host header: 200 = up.
health_check() {
  local url=$1 host port scheme code
  scheme="${url%%://*}"; host="${url#*://}"; host="${host%%/*}"; port=80
  [ "$scheme" = https ] && port=443
  code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve "${host%%:*}:$port:127.0.0.1" "$scheme://${host%%:*}/login" || true)"
  if [ "$code" = 200 ]; then ok "$url/login answers 200"; return 0; fi
  warn "$url/login answered ${code:-nothing} (expected 200); check $APP_DIR/storage/logs/laravel.log"
  return 1
}
