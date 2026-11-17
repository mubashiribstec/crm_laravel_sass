#!/usr/bin/env bash
# CRM installer for a fresh Debian 13 (or 12 with PHP 8.3+) server: Apache + PHP-FPM + MariaDB.
#
#   sudo bash deploy/install.sh                      # from a clone
#   curl -fsSL https://raw.githubusercontent.com/mubashiribstec/crm_laravel_sass/main/deploy/install.sh | sudo bash
#
# Safe to run again: every step checks first. It never overwrites an existing .env, never drops or
# empties a table, never deletes files, and never resets git. For an existing install use update.sh.
#
# Unattended (all optional; asked for when missing and a terminal is attached):
#   CRM_DIR=/var/www/crm_laravel_sass CRM_BRANCH=main CRM_REPO=https://github.com/...
#   CRM_URL=http://192.168.1.20  CRM_DB_HOST=127.0.0.1 CRM_DB_PORT=3306 CRM_DB_NAME=crm_db_portal
#   CRM_DB_USER=crm  CRM_DB_PASS=...  CRM_IP_PREFIXES=192.168.1,127.0.0  CRM_SEED=yes|no
#   CRM_SKIP_PACKAGES=1 (skip apt)  CRM_SKIP_APACHE=1 (leave Apache config alone)
set -Eeuo pipefail
umask 022

REPO="${CRM_REPO:-https://github.com/mubashiribstec/crm_laravel_sass.git}"
BRANCH="${CRM_BRANCH:-main}"
APP_DIR="${CRM_DIR:-/var/www/crm_laravel_sass}"
APP_DIR="${APP_DIR%/}"

# Running from a clone: use it. Piped through curl: clone to APP_DIR first, then re-run from it.
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)"
if [ -n "$SELF_DIR" ] && [ -f "$SELF_DIR/lib.sh" ] && [ -f "$SELF_DIR/../artisan" ]; then
  [ -z "${CRM_DIR:-}" ] && APP_DIR="$(cd "$SELF_DIR/.." && pwd)"
  # shellcheck source=deploy/lib.sh
  . "$SELF_DIR/lib.sh"
else
  [ "$(id -u)" -eq 0 ] || { echo "Run as root: curl -fsSL <url> | sudo bash" >&2; exit 1; }
  if [ ! -d "$APP_DIR/.git" ]; then
    command -v git >/dev/null || { apt-get update -qq && apt-get install -y -qq git ca-certificates; }
    [ -e "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ] && { echo "$APP_DIR exists and is not a git clone; not touching it." >&2; exit 1; }
    git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
  fi
  # Piped through curl, stdin is the script: read answers from the terminal when there is one.
  if (exec </dev/tty) 2>/dev/null; then exec bash "$APP_DIR/deploy/install.sh" "$@" </dev/tty; fi
  exec bash "$APP_DIR/deploy/install.sh" "$@" </dev/null
fi

trap on_error ERR
need_root
start_log
INTERACTIVE=0; [ -t 0 ] && INTERACTIVE=1

ask() {  # ask VAR "Question" "default" [secret]
  local var=$1 q=$2 def=${3:-} secret=${4:-} ans
  if [ -n "${!var:-}" ]; then return; fi
  if [ "$INTERACTIVE" = 1 ]; then
    if [ -n "$secret" ]; then read -r -s -p "  $q${def:+ [leave empty to generate]}: " ans; echo
    else read -r -p "  $q${def:+ [$def]}: " ans; fi
  fi
  printf -v "$var" '%s' "${ans:-$def}"
}

# ── 1. System ─────────────────────────────────────────────────────────────────────────────────
step "Checking the system"
. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
  debian:13|debian:12) ok "$PRETTY_NAME" ;;
  *) [ "${CRM_FORCE_OS:-}" = 1 ] || die "Supported: Debian 12 and 13 (found ${PRETTY_NAME:-unknown}). Set CRM_FORCE_OS=1 to try anyway." ;;
esac
[ -f "$APP_DIR/artisan" ] || die "$APP_DIR is not the CRM (no artisan file)."
ok "application folder: $APP_DIR"

SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"; SERVER_IP="${SERVER_IP:-127.0.0.1}"

# Questions first, so the rest runs unattended.
EXISTING_ENV=0; [ -f "$APP_DIR/.env" ] && EXISTING_ENV=1
if [ "$EXISTING_ENV" = 0 ]; then
  step "A few questions"
  ask CRM_URL "Address of the CRM (http://domain or http://IP)" "http://$SERVER_IP"
  CRM_URL="${CRM_URL%/}"; case "$CRM_URL" in http://*|https://*) ;; *) CRM_URL="http://$CRM_URL" ;; esac
  ask CRM_DB_HOST "Database host (127.0.0.1 = install MariaDB here)" "127.0.0.1"
  ask CRM_DB_PORT "Database port" "3306"
  ask CRM_DB_NAME "Database name" "crm_db_portal"
  ask CRM_DB_USER "Database user" "crm"
  ask CRM_DB_PASS "Database password" "generate" secret
  [ "$CRM_DB_PASS" = generate ] && CRM_DB_PASS="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
  ask CRM_IP_PREFIXES "Office networks allowed to open the CRM (first 3 parts, comma-separated)" \
      "$(echo "$SERVER_IP" | cut -d. -f1-3),127.0.0"
else
  ok ".env already exists: kept as it is (no questions)"
fi

# ── 2. Packages ───────────────────────────────────────────────────────────────────────────────
if [ "${CRM_SKIP_PACKAGES:-}" != 1 ]; then
  step "Installing system packages"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  PHPV="$(apt-cache policy php-cli 2>/dev/null | awk '/Candidate:/ {print $2}' | sed -E 's/^[0-9]+://; s/\+.*//')"
  PHPV="${CRM_PHP_VERSION:-$PHPV}"
  [ -n "$PHPV" ] && dpkg --compare-versions "$PHPV" ge 8.3 \
    || die "PHP 8.3+ is needed; this Debian offers ${PHPV:-none}. Debian 13 has 8.4 (or add a PHP 8.3+ repository and set CRM_PHP_VERSION)."
  PKGS=(apache2 git unzip curl ca-certificates cron python3 poppler-utils mariadb-client composer nodejs npm)
  for e in cli fpm mysql mbstring xml gd zip intl bcmath curl xsl opcache; do
    apt-cache show "php$PHPV-$e" >/dev/null 2>&1 && PKGS+=("php$PHPV-$e")
  done
  LOCAL_DB=0
  if [ "$EXISTING_ENV" = 0 ]; then case "$CRM_DB_HOST" in 127.0.0.1|localhost) LOCAL_DB=1 ;; esac; fi
  [ "$LOCAL_DB" = 1 ] && PKGS+=(mariadb-server)
  apt-get install -y -qq --no-install-recommends "${PKGS[@]}" >/dev/null
  ok "PHP $PHPV, Apache, Composer, Node $(node -v), poppler-utils$([ "$LOCAL_DB" = 1 ] && echo ', MariaDB server')"
else
  PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  LOCAL_DB=0
  ok "package step skipped (CRM_SKIP_PACKAGES=1); PHP $PHPV"
fi
for e in pdo_mysql mbstring xml gd zip intl bcmath curl xsl dom fileinfo; do
  php -m | grep -qi "^$e$" || die "PHP extension '$e' is missing (apt-get install php$PHPV-${e/pdo_/})."
done
ok "PHP extensions present"

prepare_owner
chmod -R o-w "$APP_DIR" && ok "no world-writable files in $APP_DIR"

# ── 3. Database ───────────────────────────────────────────────────────────────────────────────
if [ "$EXISTING_ENV" = 0 ]; then
  step "Database"
  if [ "$LOCAL_DB" = 1 ] || { [ "${CRM_SKIP_PACKAGES:-}" = 1 ] && [[ "$CRM_DB_HOST" =~ ^(127\.0\.0\.1|localhost)$ ]] && have mariadb; }; then
    svc enable mariadb
    for _ in $(seq 1 30); do mariadb -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
    mariadb -e 'SELECT 1' >/dev/null 2>&1 || die "Can't reach the local MariaDB as root (unix socket)."
    # Names are checked; the password goes in as an SQL string (quotes escaped), never on a command line.
    [[ "$CRM_DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$CRM_DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "Use letters, digits and _ only in the database and user names."
    PASS_SQL="${CRM_DB_PASS//\\/\\\\}"; PASS_SQL="${PASS_SQL//\'/\\\'}"
    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$CRM_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$CRM_DB_USER'@'localhost' IDENTIFIED BY '$PASS_SQL';
CREATE USER IF NOT EXISTS '$CRM_DB_USER'@'127.0.0.1' IDENTIFIED BY '$PASS_SQL';
GRANT ALL PRIVILEGES ON \`$CRM_DB_NAME\`.* TO '$CRM_DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$CRM_DB_NAME\`.* TO '$CRM_DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    ok "database '$CRM_DB_NAME' and user '$CRM_DB_USER' ready (an existing user keeps its password)"
  else
    ok "using the database server at $CRM_DB_HOST (not created here)"
  fi
  MYCNF="$(mktemp)"; chmod 600 "$MYCNF"
  printf '[client]\nuser=%s\npassword="%s"\nhost=%s\nport=%s\n' "$CRM_DB_USER" "${CRM_DB_PASS//\"/\\\"}" "$CRM_DB_HOST" "$CRM_DB_PORT" > "$MYCNF"
  if ! mariadb --defaults-extra-file="$MYCNF" -e "USE \`$CRM_DB_NAME\`" 2>/dev/null; then
    rm -f "$MYCNF"; die "Can't log in to '$CRM_DB_NAME' as '$CRM_DB_USER' with that password (an existing user keeps its old password)."
  fi
  rm -f "$MYCNF"
  ok "login to the database works"
fi

# ── 4. .env ───────────────────────────────────────────────────────────────────────────────────
step "Configuration (.env)"
if [ "$EXISTING_ENV" = 0 ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  env_set APP_ENV production;   env_set APP_DEBUG false;     env_set APP_URL "$CRM_URL"
  env_set DB_CONNECTION mysql;  env_set DB_HOST "$CRM_DB_HOST"; env_set DB_PORT "$CRM_DB_PORT"
  env_set DB_DATABASE "$CRM_DB_NAME"; env_set DB_USERNAME "$CRM_DB_USER"; env_set DB_PASSWORD "$CRM_DB_PASS"
  env_set CRM_ALLOWED_IP_PREFIXES "$CRM_IP_PREFIXES"
  case "$CRM_URL" in https://*) env_set SESSION_SECURE_COOKIE true ;; esac
  ok ".env created from .env.example (values not shown)"
else
  ok ".env kept"
fi
chown "$APP_OWNER:$WEB_USER" "$APP_DIR/.env"; chmod 640 "$APP_DIR/.env"

# ── 5. Code dependencies and assets ───────────────────────────────────────────────────────────
step "PHP dependencies"
fix_writable
composer_install
if ! grep -qE '^APP_KEY=.+' "$APP_DIR/.env"; then
  (cd "$APP_DIR" && php artisan key:generate --force >/dev/null) && ok "application key generated"
else
  ok "application key already set"
fi

step "Front-end assets"
build_assets

step "Folders and permissions"
fix_writable
ensure_storage_link

# ── 6. Database schema ────────────────────────────────────────────────────────────────────────
step "Database schema"
clear_compiled
artisan migrate --force
USERS="$(cd "$APP_DIR" && runuser -u "$WEB_USER" -- php artisan tinker --execute='echo \DB::table("users")->count();' 2>/dev/null | tail -1)"
ADMIN_PASS=""
if [ "$USERS" = 0 ] && [ "${CRM_SEED:-yes}" != no ]; then
  ADMIN_PASS="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
  (cd "$APP_DIR" && runuser -u "$WEB_USER" -- env SEED_ADMIN_PASSWORD="$ADMIN_PASS" php artisan db:seed --force >/dev/null)
  ok "new database seeded (roles, permissions, settings, admin user)"
else
  artisan db:seed --class='Database\Seeders\AllowedIpsSeeder' --force >/dev/null
  ok "existing data kept (${USERS:-?} users); missing office networks added"
fi
artisan crm:sync-permissions >/dev/null && ok "permissions in sync (super_admin has all)"

# ── 7. Apache, PHP, cron ──────────────────────────────────────────────────────────────────────
APP_URL="$(env_get APP_URL)"
if [ "${CRM_SKIP_APACHE:-}" != 1 ]; then
  step "Apache and PHP-FPM"
  SITE=/etc/apache2/sites-available/crm.conf
  HOST="${APP_URL#*://}"; HOST="${HOST%%/*}"; HOST="${HOST%%:*}"
  if [ ! -f "$SITE" ]; then
    sed -e "s#__APP_DIR__#$APP_DIR#g" -e "s#__SERVER_NAME__#$HOST#g" -e "s#__PHP_VERSION__#$PHPV#g" \
      "$APP_DIR/deploy/apache/crm.conf" > "$SITE"
    ok "virtual host written: $SITE"
  else
    ok "virtual host $SITE already exists: left as it is"
  fi
  INI="/etc/php/$PHPV/fpm/conf.d/90-crm.ini"
  if [ -d "/etc/php/$PHPV/fpm/conf.d" ] && [ ! -f "$INI" ]; then
    cp "$APP_DIR/deploy/php/90-crm.ini" "$INI" && ok "PHP limits: $INI (uploads 12M, post 16M)"
  fi
  a2enmod -q rewrite headers proxy_fcgi setenvif >/dev/null
  a2ensite -q crm >/dev/null
  if [ -L /etc/apache2/sites-enabled/000-default.conf ] && grep -q 'DocumentRoot /var/www/html' /etc/apache2/sites-available/000-default.conf; then
    a2dissite -q 000-default >/dev/null && ok "Debian's placeholder site disabled"
  fi
  apache2ctl configtest 2>&1 | grep -q 'Syntax OK' || die "Apache configuration test failed: $(apache2ctl configtest 2>&1 | tail -3)"
  svc enable "php$PHPV-fpm"; svc restart "php$PHPV-fpm"
  svc enable apache2; svc reload apache2
  ok "Apache serves $APP_DIR/public"
fi

step "Scheduler"
ensure_cron

# ── 8. Caches and checks ──────────────────────────────────────────────────────────────────────
step "Caches"
artisan optimize:clear >/dev/null
artisan optimize >/dev/null && ok "config, routes, views and events cached"
fix_writable

step "Health check"
HEALTH=ok; health_check "$APP_URL" || HEALTH=failed

printf '\n%s%s CRM installed%s\n' "$B" "$([ "$HEALTH" = ok ] && echo '✓' || echo '!')" "$N"
printf '  Address:    %s\n' "$APP_URL"
if [ -n "$ADMIN_PASS" ]; then
  printf '  Admin:      admin@gmail.com / %s\n' "$ADMIN_PASS" >&3      # terminal only, not the log
  printf '              (shown once, not stored anywhere: write it down and change it after logging in)\n'
  printf '  Admin:      admin@gmail.com (password shown on the terminal only)\n' >> "$LOG_FILE"
fi
printf '  Folder:     %s\n  Log:        %s\n  Update:     sudo bash %s/deploy/update.sh\n' "$APP_DIR" "$LOG_FILE" "$APP_DIR"
if [ "${#WARNINGS[@]}" -gt 0 ]; then printf '  Warnings:\n'; printf '    - %s\n' "${WARNINGS[@]}"; fi
[ "$HEALTH" = ok ]
