# CRM Portal — Setup Guide

A Laravel 11 recruitment CRM with a built-in **xplosip** softphone integration
(click-to-dial, caller-ID lookup, call logging, and dial-collision locks).

This guide covers two ways to run the project:

- **[A. Docker (recommended)](#a-docker-setup-recommended)** — one command, everything self-contained.
- **[B. Manual / local PHP](#b-manual-local-setup)** — for development against a local PHP + MariaDB.

---

## Requirements

| Tool | Version | Notes |
|------|---------|-------|
| Docker + Compose | latest | For the Docker path only |
| PHP | 8.3+ | `pdo_mysql, mbstring, gd, zip, intl, bcmath, exif, xml, xsl, opcache` |
| Composer | 2.x | |
| Node.js | 22 LTS | with npm (for the Vite asset build) |
| MariaDB | 11.x (production runs MariaDB) | MySQL 8 also works (CI tests both); `REGEXP_REPLACE` is required (phone search) |

---

## A. Docker setup (recommended)

`docker-compose.yml` runs four containers:

| Service | What it is | Reachable at |
|---|---|---|
| `app` | PHP 8.3-FPM; the code in this folder is mounted into it | (nginx only) |
| `nginx` | web server | `http://<server-ip>:8000` |
| `mariadb` | **MariaDB 11.8**, as in production; data in the `mariadb_data` volume | `127.0.0.1:3306` on the server only |
| `phpmyadmin` | optional, only with `--profile tools` | `127.0.0.1:8080` on the server only (SSH tunnel) |

The app reaches the database as `DB_HOST=mariadb` (compose sets it; the old name `mysql` also
works). `DB_CONNECTION` stays `mysql`: that is Laravel's driver for both MySQL and MariaDB.

```bash
# 1. Clone and create .env
git clone https://github.com/mubashiribstec/crm_laravel_sass.git
cd crm_laravel_sass
cp .env.example .env
#    In .env set at least: APP_URL, DB_DATABASE, DB_ROOT_PASSWORD, and DB_PASSWORD
#    (the same value as DB_ROOT_PASSWORD while DB_USERNAME=root).

# 2. Start the containers (MariaDB creates DB_DATABASE on its first start)
docker compose up -d --build

# 3. First run only. The code folder is mounted over the image, so dependencies and assets
#    are installed into this folder.
docker compose exec app composer install --no-interaction --optimize-autoloader
docker compose exec app npm ci
docker compose exec app npm run build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force    # prints the admin's random password once
docker compose exec app php artisan storage:link
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
```

Open `http://<server-ip>:8000` and sign in as `admin@gmail.com` with the printed password, then
change it. Logging in also needs the browser's IP in **IP Addresses** (seeded: the allowlist from
`AllowedIpsSeeder`).

### Updating

```bash
git pull
docker compose up -d --build
docker compose exec app composer install --no-interaction --optimize-autoloader
docker compose exec app npm ci && docker compose exec app npm run build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
```

### Moving from the old MySQL container

Until this change, `docker-compose.yml` ran `mysql:8.0` with its data in the `mysql_data` volume.
MariaDB can't open MySQL 8's data files, so the data moves across as a dump.

1. **Dump the old database.**
   - Before pulling this change, while the `mysql` service still runs:

     ```bash
     docker compose exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases crm_kbp' > crm_kbp.sql
     ```

   - Already pulled? The old volume is still there (`docker volume ls | grep mysql_data`). Start a
     throwaway MySQL on it. It keeps the old root password; the value passed here is ignored for an
     existing volume.

     ```bash
     docker run -d --name old-mysql -v crm_laravel_sass_mysql_data:/var/lib/mysql -e MYSQL_ROOT_PASSWORD=ignored mysql:8.0
     sleep 30
     docker exec old-mysql sh -c 'mysqldump -uroot -p"<the old DB root password>" --single-transaction --routines --triggers --databases crm_kbp' > crm_kbp.sql
     docker rm -f old-mysql
     ```

2. **Load it into MariaDB:**

   ```bash
   docker compose up -d mariadb
   docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' < crm_kbp.sql
   docker compose up -d
   docker compose exec app php artisan migrate --force
   ```

3. **Delete the old volume** once the CRM works and you have kept `crm_kbp.sql`:
   `docker volume rm crm_laravel_sass_mysql_data`.

Use your database name instead of `crm_kbp` if it differs. The volume prefix is the folder name;
`docker volume ls` shows it.

A MySQL 8 dump names MySQL's own collation (`utf8mb4_0900_ai_ci`) in its `CREATE DATABASE` line.
MariaDB 11.8 accepts it (tested). To load such a dump into an older MariaDB, replace it first:
`sed -i 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' crm_kbp.sql`.

### Useful Docker commands

```bash
docker compose ps                                 # container status
docker compose logs -f app                        # app logs
docker compose exec app bash                      # shell in the app container
docker compose exec app php artisan migrate:status
docker compose exec mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'   # SQL shell
docker compose --profile tools up -d phpmyadmin   # DB admin UI (then an SSH tunnel to 8080)
docker compose down                               # stop (keeps the mariadb_data volume)
```

---

## B. Manual / local setup

```bash
git clone https://github.com/mubashiribstec/CRM.git
cd CRM

composer install
npm install

cp .env.example .env
php artisan key:generate

# Edit .env: set DB_DATABASE / DB_USERNAME / DB_PASSWORD and APP_URL
php artisan migrate --seed

npm run build          # or: npm run dev   (hot reload during development)
php artisan serve      # http://127.0.0.1:8000
```

---

## Environment configuration

Key variables (see `.env.example` for the full list):

```ini
APP_NAME=CRM
APP_ENV=production            # local | production
APP_DEBUG=false               # MUST be false in production
APP_URL=https://your-domain   # must match how users reach the app

DB_CONNECTION=mysql
DB_HOST=127.0.0.1             # "db" inside Docker
DB_PORT=3306
DB_DATABASE=crm_db_portal
DB_USERNAME=crm_user
DB_PASSWORD=change-me

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp             # "log" writes mail to the log instead of sending
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@your-domain
```

### Integration / security variables

These have sensible defaults in `config/services.php` but should be set explicitly:

```ini
# Shared secret for the desktop MicroSIP integration endpoints.
# Point MicroSIP's crmApiUrl at:  https://your-crm/api/sip/<MICROSIP_API_TOKEN>
MICROSIP_API_TOKEN=generate-a-long-random-string

# Click-to-dial collision lock duration (minutes). A number stays locked to the
# dialing agent until this expires or the call is logged.
DIAL_LOCK_MINUTES=5

# Trusted reverse-proxy CIDRs (restrict in production, e.g. "172.16.0.0/12").
TRUSTED_PROXIES=*
```

> **Never commit real secrets.** `.env`, `.env.docker`, `.env.production`, and
> `.env.*.local` are git-ignored. Keep `MICROSIP_API_TOKEN` and DB passwords out
> of the repository.

---

## xplosip softphone integration

The CRM exposes API endpoints the desktop MicroSIP client calls:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET`  | `/api/sip/{token}/lookup?number=...` | Caller-ID lookup by phone number |
| `POST` | `/api/sip/{token}/calls`             | Store a completed call record |

`{token}` is the value of `MICROSIP_API_TOKEN`. Browser-side (Sanctum-authenticated)
equivalents also exist at `/api/contacts/lookup` and `/api/calls/log`.

Phone matching uses indexed, digits-only `*_normalized` columns on the
`applicants` table (last 10 digits), kept in sync automatically when an applicant
is saved. This is added by the migration
`2026_05_29_120000_add_normalized_phone_columns_to_applicants_table`, which also
backfills existing rows. If that migration has not run yet, lookups transparently
fall back to a `REGEXP_REPLACE` scan, so the feature works either way.

---

## Production notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, and a real `APP_URL`.
- Serve over HTTPS and set `SESSION_SECURE_COOKIE=true`.
- After deploying code, cache for performance and restart to flush OPcache:
  ```bash
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  # Docker: docker compose restart app
  ```
- Run migrations during a low-traffic window if the `applicants` table is large
  (the phone backfill updates every row once).
- If `php artisan migrate` stops at `drop_applicant_email_secodary_typo`, some applicants have a
  different address in the misspelt `applicant_email_secodary` column than in
  `applicant_email_secondary`; the message lists their ids. Keep the right one in
  `applicant_email_secondary`, clear the other, and run `migrate` again (nothing is dropped
  until then).
- **Private files.** CVs and office/unit attachments are stored under `storage/app/private/` and
  downloaded only through permission-checked routes. Files uploaded before this change are still
  in `public/uploads/`; move them once (dry run first, the commands list what they would do):
  ```bash
  php artisan cv:move-to-private --dry-run && php artisan cv:move-to-private
  php artisan documents:move-to-private --dry-run && php artisan documents:move-to-private
  ```
  Then make sure the web server refuses the old folders. The bundled `docker/nginx.conf` and
  `public/.htaccess` (Apache) already do; with your own nginx config add:
  ```nginx
  location ^~ /uploads/resume/ { return 404; }
  location ^~ /uploads/docs/   { return 404; }
  location ^~ /uploads/ { location ~* \.(php|phtml|phar|pl|py|cgi|sh|html?|svg)$ { deny all; } }
  ```

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `MissingAppKeyException` | `php artisan key:generate` (Docker: `docker compose exec app php artisan key:generate`) |
| Login blocked by IP check ("Your IP address is not registered") | Add the IP from the server: `php artisan tinker --execute="DB::table('ip_addresses')->insert(['user_id' => 1, 'ip_address' => '203.0.113.7', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);"` (your IP and the admin's user id); then manage the list under IP Addresses |
| Stale CSS/JS | `npm run build` (Docker: `docker compose exec app npm run build`) |
| Code change not taking effect | `php artisan optimize:clear`; in Docker also `docker compose restart app` (OPcache) |
| `REGEXP_REPLACE` errors | Use MariaDB 10.0.5+ (or MySQL 8+) |
