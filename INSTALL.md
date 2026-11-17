# Installing the CRM on a Debian server

One command sets up a new server: Apache, PHP-FPM, MariaDB, the code, the database, the scheduler and the web server configuration. (For Docker, see [SETUP.md](SETUP.md).)

## Before you start

- **Debian 13.** Debian 12 also works if PHP 8.3 or newer is installed, because Debian 12's own PHP is 8.2.
- Root access (`sudo`), and internet access to `deb.debian.org`, `github.com`, `packagist.org` and `registry.npmjs.org`.
- About 2 GB of free disk for the code, dependencies and build, plus room for the database and uploads.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/mubashiribstec/crm_laravel_sass/main/deploy/install.sh | sudo bash
```

A minimal Debian may not have `curl`; install it first with `sudo apt-get install -y curl`.

If you already have a clone, run `sudo bash deploy/install.sh` from inside it instead.

The installer asks only for the following:

| Question | Default |
|---|---|
| Address of the CRM | `http://<this server's IP>` |
| Database host | `127.0.0.1` (MariaDB is installed on this server) |
| Database port, name, user | `3306`, `crm_db_portal`, `crm` |
| Database password | generated if you leave it empty |
| Office networks allowed to open the CRM | this server's network, plus `127.0.0` |

At the end it prints the address and the admin login (`admin@gmail.com`). The admin password is shown **once**, on screen only. It isn't written to the log or anywhere else, so write it down and change it after you log in.

The CRM opens only from the office networks you gave. To add more networks later, go to **IP Addresses** in the CRM, or set `CRM_ALLOWED_IP_PREFIXES` in `.env` and run `sudo -u www-data php artisan db:seed --class='Database\Seeders\AllowedIpsSeeder' --force`.

### Without questions

Set the answers as variables:

```bash
curl -fsSL https://raw.githubusercontent.com/mubashiribstec/crm_laravel_sass/main/deploy/install.sh \
 | sudo CRM_URL=http://crm.example.com CRM_DB_NAME=crm_db_portal CRM_DB_USER=crm CRM_DB_PASS='…' \
        CRM_IP_PREFIXES=192.168.110,127.0.0 bash
```

Other variables:

| Variable | What it does |
|---|---|
| `CRM_DIR` | where to install (default `/var/www/crm_laravel_sass`) |
| `CRM_BRANCH` | which branch to install (default `main`) |
| `CRM_DB_HOST`, `CRM_DB_PORT` | a database on another server; nothing is created there |
| `CRM_SEED=no` | don't seed an empty database |
| `CRM_SKIP_PACKAGES=1` | skip installing system packages |
| `CRM_SKIP_APACHE=1` | leave the Apache configuration alone |

## What it does

Each step checks first, so running the installer again is safe. It only finishes the parts that are missing.

1. Installs Debian's own packages:
   - Apache, PHP-FPM and the PHP extensions the CRM uses (`mysql, mbstring, xml, gd, zip, intl, bcmath, curl, xsl, opcache`);
   - Composer, Node.js and npm;
   - `poppler-utils`, which provides `pdftotext` for PDF CV import;
   - cron;
   - MariaDB, when the database is on this server.
2. Creates the database and its user with `IF NOT EXISTS`. An existing user keeps its password, and nothing is dropped.
3. Creates `.env` from `.env.example`, **only if `.env` doesn't exist**. An existing `.env` is never changed, and its values are never printed. It also creates the application key if it's empty.
4. Installs the PHP dependencies (`composer install --no-dev`).
5. Builds the front end (`npm ci`, `npm run build`). It stops unless `public/build/manifest.json` exists afterwards.
6. Sets up the folders the web server writes to:
   - `storage/` and `bootstrap/cache` belong to `www-data` and are group-writable, with no `777`;
   - every `artisan` command runs as `www-data`, so it never leaves files the site can't write;
   - `public/storage` is linked to `storage/app/public` only if it's missing. If it's a real folder (older installs keep files there), it's left as it is.
7. Runs the migrations:
   - an empty database is seeded with roles, permissions, settings and the admin user;
   - an existing database keeps its data;
   - `crm:sync-permissions` makes sure the `super_admin` role has every permission.
8. Sets up Apache:
   - adds a site, `/etc/apache2/sites-available/crm.conf` (from `deploy/apache/crm.conf`), which serves `public/`, with only `index.php` run as PHP;
   - enables the `rewrite`, `headers`, `proxy_fcgi` and `setenvif` modules;
   - sets PHP limits in `/etc/php/<version>/fpm/conf.d/90-crm.ini` (uploads 12 MB);
   - an existing `crm.conf` is never replaced.
9. Adds the scheduler: `/etc/cron.d/crm-laravel` runs `php artisan schedule:run` every minute. This sends bulk emails and SMS, updates job availability every night, and purges deleted attachments every hour.
10. Rebuilds the caches (`php artisan optimize`), then checks that `/login` answers 200.

No queue worker is needed: nothing in the CRM is queued.

The output goes to `/var/log/crm-deploy.log` (readable by root only).

## After installing

- **HTTPS.** For a domain name, add a certificate, for example `apt-get install certbot python3-certbot-apache && certbot --apache`. Then set `APP_URL=https://…` and `SESSION_SECURE_COOKIE=true` in `.env`, and run `sudo bash deploy/update.sh`.
- **Optional integrations.** Mail (SMTP) and the Xplogent AI phone agent are set in `.env`; see [SETUP.md](SETUP.md) and [docs/xplogent-api.md](docs/xplogent-api.md).
- **Updates:** see [DEPLOYMENT.md](DEPLOYMENT.md).
