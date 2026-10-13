# Deployment: www.58dwell.com/dash

Same server and the same pattern as the room-booking app at `/book`: Ubuntu 22.04, PHP 8.1 (FPM),
Apache, MySQL, web root `/var/www/verus/fiftyeight`. Every PHP extension Dwell Central needs is already
installed (pdo_mysql, mbstring, zip, gd, exif, curl, intl, openssl, xml). Nothing new to install.

The code lives **outside** the web root at `/var/www/dwell-central`; only its `public/` folder is
published at `/dash`. Configuration, source code, staff photos, logs and backups can never be
downloaded. **Never** put the HR spreadsheet anywhere in the web root: upload it inside the app instead.

---

## 1. Put the code on the server

```bash
sudo git clone https://github.com/binghamohana/dwell_dashboard.git /var/www/dwell-central
cd /var/www/dwell-central
sudo mkdir -p storage/backups
sudo chown -R www-data:www-data storage
sudo chmod 750 storage storage/photos storage/backups
```

## 2. Create the database

```bash
sudo mysql <<'SQL'
CREATE DATABASE dwell_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dwell_central'@'localhost' IDENTIFIED BY 'PASTE-A-LONG-RANDOM-PASSWORD';
GRANT ALL PRIVILEGES ON dwell_central.* TO 'dwell_central'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Generate the password with `openssl rand -base64 24` and paste it in place of the placeholder (as with
`/book`, don't leave the placeholder text).

## 3. Configure

```bash
sudo cp config/config.sample.php config/config.php
sudo chown root:www-data config/config.php && sudo chmod 640 config/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"      # copy this for app_secret
sudo vim config/config.php
```

| Key | Value |
|---|---|
| `base_url` | `https://www.58dwell.com/dash` |
| `app_secret` | the random string from above (not the one used by `/book`) |
| `db.name`, `db.user` | `dwell_central` |
| `db.password` | the password from step 2 (`host` stays `localhost`) |
| `mail.transport` | leave `log` for now; `smtp` once the sender account is decided (step 8) |
| `google.*` | leave empty until Google sign-in is approved ([GOOGLE.md](GOOGLE.md)) |

## 4. Create the tables and the first administrator

```bash
sudo -u www-data php bin/install.php
sudo -u www-data php bin/admin.php create you@yourdomain.com "Your Name"
```

`install.php` is safe to re-run after every update. `admin.php` asks for a password (10+ characters).

## 5. Publish at /dash

```bash
sudo ln -s /var/www/dwell-central/public /var/www/verus/fiftyeight/dash
```

This is the same symlink approach that already works for `/book`. If `/dash/` returns 403, add
`Options +FollowSymLinks` for the site, or use an Alias in the site's Apache config instead:

```apache
Alias /dash /var/www/dwell-central/public
<Directory /var/www/dwell-central/public>
    Require all granted
    AllowOverride All
    Options -Indexes +FollowSymLinks
</Directory>
```

(then `sudo systemctl reload apache2`). HTTPS is required: sign-in cookies are only sent over HTTPS.

## 6. Background tasks and nightly backups

```bash
sudo tee /etc/cron.d/dwell-central >/dev/null <<'CRON'
# Dwell Central
*/5 * * * * www-data php /var/www/dwell-central/bin/cron.php >> /var/www/dwell-central/storage/logs/cron.log 2>&1
30 2 * * *  www-data /var/www/dwell-central/bin/backup.sh >> /var/www/dwell-central/storage/logs/backup.log 2>&1
CRON
```

`cron.php` runs the daily celebration check at the configured time (8:00 AM Eastern by default),
delivers and retries email, alerts administrators about failures and tidies up. `backup.sh` keeps 14 days
of database dumps and photo archives in `storage/backups/` with owner-only permissions. They contain
personnel data: if you copy them off the server, keep them somewhere access-controlled.

## 7. Check, sign in, import

```bash
sudo -u www-data php bin/check.php
```

Then open `https://www.58dwell.com/dash/`, sign in, and follow **First-time setup** in the
[admin guide](ADMIN-GUIDE.md): import the HR spreadsheet, review the data issues, add users. Celebration
emails stay in **test mode** until you switch them to live inside the app.

## 8. Email (PHPMailer)

PHPMailer is bundled. Use an account **owned by Dwell**, not a personal mailbox, ideally a dedicated
address such as `central@<your domain>`:

```php
'mail' => [
    'transport'  => 'smtp',
    'host'       => 'smtp.gmail.com',
    'port'       => 465,
    'encryption' => 'ssl',
    'username'   => 'central@yourdomain.com',
    'password'   => 'xxxx xxxx xxxx xxxx',     // a Google app password named "Dwell Central"
    'from_email' => 'central@yourdomain.com',  // must be the same account (or an alias it may send as)
    'from_name'  => 'Dwell Central',
],
```

As with `/book`, paste the Google app password exactly as Google shows it (four groups of four). Then:

```bash
sudo -u www-data php bin/check.php --send-test=you@yourdomain.com
```

Changing `transport` to `smtp` does **not** send celebration emails to supervisors by itself: they stay
in test mode (delivered only to the administrator chosen as test inbox) until someone switches to live
on the Notifications page.

## Updating later

```bash
cd /var/www/dwell-central
sudo git pull
sudo -u www-data php bin/install.php
sudo -u www-data php bin/check.php
```

## Restoring a backup

```bash
gunzip -c storage/backups/db-YYYY-MM-DD.sql.gz | sudo mysql dwell_central
sudo tar -xzf storage/backups/photos-YYYY-MM-DD.tar.gz -C storage
```

## Locked out?

```bash
sudo -u www-data php bin/admin.php list
sudo -u www-data php bin/admin.php password you@yourdomain.com
```

---

## Developer notes

- Local copy: `cp config/config.sample.php config/config.php`, set `db.*`, `debug => true`, then
  `php bin/install.php && php -S localhost:8090 -t public`.
- Fictional demo data: `php tests/seed-demo.php` (refuses unless `debug` is on and the database is
  empty). Sign in as `admin@example.com` / `dwell-demo-2026`.
- Tests: `DC_DB_NAME=dwell_test php tests/run.php` against a separate database whose name contains
  `test` (it is wiped each run). 116 checks; also run on PHP 8.1.
- No build step. Files in `public/assets` are cache-busted automatically. d3 7.9.0 and jQuery 3.7.1
  are vendored in `public/assets/vendor`.
