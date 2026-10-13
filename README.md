# Dwell Central

The Dwell Communities team portal: **Phase 1, Employees.** A private personnel application that keeps
an accurate picture of the team (who works where, who reports to whom) and makes sure every birthday and
work anniversary is remembered by the right person.

Built on Dwell's existing Ubuntu LAMP server (PHP, MySQL, jQuery, PHPMailer) at
`https://www.58dwell.com/dash/`, as the foundation for the later modules (Entities, Properties,
Projects, Resources).

## What's included

**For everyone who signs in** (scoped to what their role allows)
- **Dashboard:** active team members, birthdays and work anniversaries this month, the next 7 and 30
  days, missing or unconfirmed dates, celebrations without an owner, and the upcoming list with each
  reminder's status.
- **Org chart:** an interactive, zoomable map by company (Dwell Communities, 58 Place), by property,
  or by reporting line. Expand, collapse, search, filter; click anyone for their profile.
- **Directory** and **profiles:** photo, title, property, department, company, supervisor, reports,
  birthday (month and day only), hire date and years of service, who celebrates them.
- **Celebrations calendar:** month grid or list with filters; mark celebrations planned or done.

**For administrators**
- **People records:** add, edit, deactivate; fix data issues; mark records verified against HR.
- **Import:** upload the HR spreadsheet (.xlsx/.csv), review every change, match spellings to records,
  resolve duplicates, protect verified records, then apply. The file itself isn't kept.
- **Notifications:** monthly summaries on the 1st, reminders 3 days before, day-of emails, no-owner
  and delivery-failure alerts. **Test mode by default**; preview any day; history with retries; going
  live needs an explicit, audited confirmation.
- **Organization** (companies, legal entities, properties, departments), **Users & access** (roles,
  invitations, Google sign-in), **Settings**, **Audit log**.

## Documentation

| | |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Architecture and implementation plan, data model, security, stages, acceptance-test map |
| [docs/DECISIONS.md](docs/DECISIONS.md) | **Outstanding approvals and data questions** |
| [docs/POLICIES.md](docs/POLICIES.md) | Celebration rules: eligibility, Feb 29, rehires, owner routing, schedule, retention |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Installing at www.58dwell.com/dash, cron, backups, email, updates |
| [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) | First-time setup, everyday tasks, roles, emails, troubleshooting |
| [docs/GOOGLE.md](docs/GOOGLE.md) | Google sign-in setup; Google Calendar options and recommendation |

## Requirements

PHP 8.1+ (pdo_mysql, mbstring, simplexml, openssl; zip for .xlsx, gd for photos, curl for Google
sign-in, intl recommended), MySQL 5.7+/8 or MariaDB 10.3+, Apache, cron. Tested on PHP 8.1 and 8.3 with
MariaDB 10.11.

## Project layout

```
public/            the only web-visible folder (published at /dash)
  index.php        the app page            api.php      JSON API (every action + its permission)
  photo.php        private staff photos    google.php   Google sign-in
  assets/          CSS, JavaScript, fonts, vendored jQuery 3.7.1 and d3 7.9.0
src/
  Core/            sign-in, access control, settings, database, mail outbox, audit, users
  Org/             companies, legal entities, properties, departments (shared by all modules)
  Employees/       records, celebration owners, photos
  Celebrations/    date rules, events, dashboard figures
  Notifications/   daily check, monthly/advance/day-of emails, alerts, previews
  Import/          .xlsx/.csv reader and import with preview/approval
  Api/Router.php   the API: actions, methods, permissions
lib/PHPMailer/     PHPMailer 6.12 (bundled)
config/            config.sample.php → config.php (not in Git)
sql/schema.sql     tables
bin/               install.php, cron.php, check.php, admin.php, backup.sh
storage/           logs, photos, backups (not web-visible, not in Git)
tests/             run.php (acceptance tests), seed-demo.php (fictional demo data)
```

## Quick start (development)

```bash
cp config/config.sample.php config/config.php     # set db.*, app_secret and 'debug' => true
php bin/install.php
php tests/seed-demo.php                            # fictional people; admin@example.com / dwell-demo-2026
php -S localhost:8090 -t public
DC_DB_NAME=dwell_test php tests/run.php            # needs a separate *test* database
```

No personnel data belongs in this repository: spreadsheets are ignored by Git, and demo data is fictional.
