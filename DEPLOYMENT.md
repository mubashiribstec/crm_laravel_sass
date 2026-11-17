# Updating and running the CRM (native Debian install)

For a new server, see [INSTALL.md](INSTALL.md).

## Updating

```bash
sudo bash /var/www/crm_laravel_sass/deploy/update.sh
```

| Option | What it does |
|---|---|
| `--check` | shows what would come in and changes nothing |
| `--no-backup` | skips the database backup |

What it does, in order:

1. **Code.** Fetches the branch the server is on and moves forward to it (fast-forward only).
   - Your local edits and untracked files are kept.
   - If an incoming change touches a file you edited (or an untracked file it would overwrite), it **stops before changing anything** and lists the files. Keep a copy, run `git checkout -- <file>`, and run it again.
   - If the server has its own commits (the history diverged), it stops too.
   - It never runs `git reset`, `git clean` or `git stash`.
2. **PHP dependencies:** `composer install --no-dev`.
3. **Front end:** `npm ci` and `npm run build`, only when `package*.json`, `vite.config.js` or `resources/` changed since the last build.
4. **Database.** If there are new migrations:
   - it backs up the database first, to `/var/backups/crm/<database>-<date>-before-<commit>.sql.gz` (`mysqldump --single-transaction`; root only; the last 5 are kept);
   - it turns on maintenance mode, runs the migrations, and turns it off again;
   - if a migration fails, the site **stays in maintenance mode**, so nobody works on a half-migrated database. Fix the error and run the update again, or restore the backup.
5. **Permissions:** `crm:sync-permissions` creates permissions added by the new code and gives them to `super_admin`. Other roles and users are not changed.
6. **Folders and services:**
   - `storage/` and `bootstrap/cache` are owned by `www-data`;
   - `public/storage` is linked only if it's missing;
   - the scheduler cron is created if it's missing;
   - caches are rebuilt;
   - PHP-FPM (or Apache) reloads so the new code is used.
7. **Health check:** `/login` must answer 200.

It never touches `.env`, uploads, `storage/` contents or a real `public/storage` folder, and it never drops or empties a table.

### Restoring a backup

```bash
gunzip < /var/backups/crm/<file>.sql.gz | sudo mariadb <database>
```

This overwrites the tables that are in the backup. Then go back to the matching code with `git checkout <commit>` (the commit is in the file name).

### Moving old files to private storage (once, by hand)

Older installs kept CVs, documents and Send CV synopsis forms under `public/uploads/`. The update never moves files on its own. Run each command with `--dry-run` first to see what it would do, then without:

```bash
cd /var/www/crm_laravel_sass
sudo -u www-data php artisan cv:move-to-private --dry-run
sudo -u www-data php artisan documents:move-to-private --dry-run
sudo -u www-data php artisan synopsis:move-to-private --dry-run
```

- A database row whose file is missing is listed and left unchanged.
- `public/.htaccess` already refuses the old folders (`/uploads/resume/`, `/uploads/docs/`, `/uploads/synopsisForms/`, `/uploads/drafts/`).

## Day-to-day

**Running artisan.** Always run it as the web user, so the files it writes stay writable by the site:

```bash
cd /var/www/crm_laravel_sass
sudo -u www-data php artisan <command>
```

Running it as root caused the `bootstrap/cache` and `storage` permission errors. If that happens again, `sudo bash deploy/update.sh` repairs the ownership.

**Cache commands:**
- After editing `.env`: `sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan optimize`.
- `php artisan optimize` caches config, routes, views and events. Run it only once `.env` is complete, because cached config ignores later `.env` changes until it's cleared.

**Scheduler.** `/etc/cron.d/crm-laravel` runs every minute as `www-data`. To see the jobs: `sudo -u www-data php artisan schedule:list`.

**Logs:**
- application: `storage/logs/laravel.log`;
- Apache: `/var/log/apache2/crm-error.log`;
- install and update: `/var/log/crm-deploy.log`.

**Login refused with "Your IP address is not registered".** Add the network under **IP Addresses**, or from the server:

```bash
sudo -u www-data php artisan tinker --execute="DB::table('allowed_ips')->insert(['ip_prefix' => '10.0.5']);"
```

The prefix is the first three parts of the IP address.

## Requirements reference

| | |
|---|---|
| PHP | 8.3+ (Debian 13: 8.4) with `pdo_mysql, mbstring, xml, dom, gd, zip, intl, bcmath, curl, xsl, fileinfo, opcache` |
| Database | MariaDB 10.5+ (production: 11.8) or MySQL 8 |
| Node.js | 18+ with npm (Debian 13: 20), only to build the front end |
| Web server | Apache 2.4 with `rewrite, headers, proxy_fcgi, setenvif` and `AllowOverride All` for `public/` (nginx: [docker/nginx.conf](docker/nginx.conf)) |
| Other | `pdftotext` (`poppler-utils`), cron |
| Not needed | queue worker, Redis, Node at runtime |

## Known open items

- **Portal API token.** The open-sales feed's token is written in `config/services.php`, so it's visible to anyone who can read the repository. It is left as it is for now; moving it to `.env` (`PORTAL_API_TOKEN`) means giving the portal a new token.
- **Front-end dependency warnings.** `npm audit` still reports:
  - `picomatch` (used only during the build, no fixed release yet);
  - `quill` 2.0.3 (the advisory is about its `getSemanticHTML` export, which the CRM doesn't call).
