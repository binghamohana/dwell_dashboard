# Admin guide

**Address:** `https://www.58dwell.com/dash/`. Sign in with your work email (or "Sign in with Google"
once it's set up). Sessions last 7 days per device.

## First-time setup

The quickest way in is the **setup workbook**: one Excel file with a sheet each for companies, legal
entities, properties, departments and people. The lists on the People sheet come from the other sheets,
so every person links to real records instead of free text.

1. **Settings:** check the celebration rules (February 29, anniversaries from the most recent hire,
   contractors, birth year) and the email schedule. Pick yourself as the **test-mode inbox**.
2. **Import → Download the template.** Fill in the sheets in order (the "Start here" sheet explains each
   column):
   - **Companies:** Dwell Communities and 58 Place are already there.
   - **Legal entities:** each employer of record, its type (Employer or Contractors (1099)) and company.
     Put spellings from payroll/HR exports in *Also known as* so later HR files match automatically.
   - **Properties:** every community, office and team, its company, address and **property manager**
     (the fallback when someone's supervisor isn't available).
   - **Departments:** Leasing, Maintenance, and so on.
   - **People:** one row per current team member. Most important: **Supervisor** and **Work email** (they
     decide who gets each reminder). Birthdays are month and day only.
3. **Import → upload the workbook → review.** The review shows the organization records to create or
   update, then every person, with notes for anything that looks wrong. **Apply** saves it all at once.
   Upload an updated copy any time: unchanged rows are skipped, blank cells never erase saved data,
   and verified records are protected.
4. **People records:** work through the "Needs" filters (*No supervisor*, *No work email*, birthdays to
   review). Tick **Verified** once a record is checked against HR; imports then can't change it without
   your approval.
5. **Users & access:** add executives and managers (see *Roles* below). Each manager must be linked to
   their own record so the app knows whose information they may see.
6. **Notifications → Preview a day:** pick the 1st of next month and read exactly what each supervisor
   would receive. Run today's check in test mode and look at the copies in your inbox.
7. When leadership approves: **Notifications → Live**, type `GO LIVE`.

The raw HR export (e.g. *Cross Company Employee Data*) can also be imported directly: choose its main
sheet, match each company/division spelling to a record (or create one), review and apply.

Please don't edit the tables in phpMyAdmin: the app keeps IDs, links between records, data checks and the
audit log consistent, and direct edits bypass all of them.

## Everyday tasks

| I want to… | Do this |
|---|---|
| See what's coming up | **Dashboard** (next 30 days, counts, data issues, email status) or **Celebrations** (month grid or list; filter by company, property, department, type, "without an owner") |
| Find someone | **Directory** (search, filter, group by property or company) or **Org chart** → *Find a person* |
| See the structure | **Org chart**: *Companies*, *Properties* or *Reporting lines*. Drag to move, scroll or pinch to zoom, click a company/property to open it, click a person for their profile. The round button on a card shows/hides their team; the side buttons zoom, fit, expand all or collapse all |
| Add or change a person | **People records → Add person**, or open a profile → **Edit** |
| Someone left | Edit → Status **Former employee** (term date fills in as today if blank). Their celebrations stop; history is kept |
| Someone was rehired | Edit → Status **Active**, new **Hire date**, and their earlier start as **Original hire date** (an import does this automatically when it sees a later hire date) |
| Change who reminds someone | Set their **Supervisor**, or a **Celebration owner** for special cases. The profile shows who it is and why |
| Someone prefers no birthday fuss | Edit → untick *Celebrate their birthday* |
| Upload a photo | Edit → **Upload approved photo** (only with the person's OK; visible to signed-in staff only) |
| Add or change many people or properties at once | Update your setup workbook and **Import** it again |
| Re-import a newer HR file | **Import** again. Unchanged people show "No change"; verified records are protected; people missing from the file are listed but only marked as former if you tick them |
| Mark a celebration as done | Click it (dashboard or calendar) → **Planned / Celebrated / Skipped** |
| Check an email | **Notifications** → click a row to see the message exactly as sent, who it was for, and any error. **Retry** failed ones after fixing the mail settings |
| See who changed what | **Audit log**, or a profile → *View change history* |

## Roles

| Role | Sees | Can |
|---|---|---|
| **System administrator** | Everyone, every field | Everything: records, imports, organization, users, settings, email mode, audit log |
| **Executive leadership** | Everyone (read-only) | Dashboard, calendar, notification history, mark celebrations; opt in to the organization-wide monthly report |
| **Property manager / supervisor** | Themselves, everyone below them in the reporting chain, their dotted-line reports, everyone at properties they manage (or are given under *Also sees everyone at*), and anyone they're the assigned owner for | Dashboard, calendar and directory for those people; mark celebrations |
| **Employee** (future) | Current team: name, title, property, department, supervisor, photo | Directory and org chart only |

Access is checked on the server for every request. Changing someone's role or email, or disabling them,
signs them out everywhere.

**Adding a user:** Users & access → Add user → name, email, role, linked record. Tick *Email them an
invitation* (or copy the link and send it yourself). Invitations work for 7 days; password-reset links
for 24 hours. With Google sign-in on, people can simply sign in with Google.

**Alerts:** tick *Send them "no celebration owner" and delivery-failure alerts* for the administrators who
should handle those (if nobody is ticked, every system administrator gets them).

## Celebration emails

| Mode | What happens |
|---|---|
| **Off** | Nothing is prepared or sent |
| **Test** (default) | Everything is prepared exactly as live, labelled `[TEST]`, and delivered only to the test-mode inbox (Settings), or just recorded if none is chosen |
| **Live** | Supervisors and owners receive their emails |

What goes out and when: [POLICIES.md](POLICIES.md#when-messages-go-out). The **Notifications** page shows
the mode, whether the background job is running, the mail server, and every message with its status
(*Sent*, *Queued*, *Failed*, *Recorded only*, *Cancelled*). **Preview a day** shows what any date would
produce without sending. **Run today's check now** runs it immediately (safe to repeat: nothing is
sent twice).

## Data quality checklist

The dashboard's **Data to tidy up** panel links to each list:

- **No celebration owner**: active people nobody will be reminded about. Add a supervisor (with a work
  email) or a property manager.
- **No supervisor**: also keeps them out of the reporting-lines chart. Expected for the top of the
  organization.
- **Birthday needs review / No birthday**: not celebrated until fixed.
- **No hire date**: no work anniversaries.
- **No work email**: they can't receive reminders as a supervisor.
- **Not yet verified**: records nobody has checked against HR yet.

## Troubleshooting

| Symptom | Check |
|---|---|
| Dashboard says background tasks haven't run | The cron job ([DEPLOYMENT.md](DEPLOYMENT.md) step 6). Run `sudo -u www-data php /var/www/dwell-central/bin/cron.php` by hand; see `storage/logs/cron.log` |
| Emails "Recorded only" | Test mode with no test inbox chosen (Settings), or that's intended |
| Emails go to the log instead of being sent | `mail.transport` in `config/config.php` is `log`; set `smtp` |
| Email failed | Notifications shows the server's reply (e.g. a wrong app password). Fix `config.php`, then **Retry**. `bin/check.php --send-test=you@…` tests from the server |
| A manager sees too much or too little | Users & access: their linked record and *Also sees everyone at*; then their reporting line in People records |
| Someone isn't on the org chart | Former employees and filtered-out companies/properties aren't shown; check the filters |
| An import says "something changed since you reviewed" | Someone edited a record meanwhile; the preview refreshes, review and apply again |
| "Dwell Central is temporarily unavailable" | Database or config problem: `sudo -u www-data php bin/check.php`; details in `storage/logs/php-error.log` |

## Ownership and costs

Everything runs on Dwell's server: code (also in the `binghamohana/dwell_dashboard` repository), the
MySQL database, staff photos and backups. Accounts live in the app's own database. Running cost: **$0**
beyond the existing server and email account. Google sign-in and calendar use a Dwell-owned Google Cloud
project (no cost at this scale).
