# Celebration policies

How Dwell Central decides who is celebrated, when, and who is reminded. Items marked **(setting)** can
be changed by a system administrator under **Settings**; the defaults below were chosen to be
conservative and are listed in [DECISIONS.md](DECISIONS.md) for leadership to confirm.

## Who is celebrated

- People whose status is **Active**. Former employees and people **on leave** are not.
- Contractors and vendors (1099, or classification "Contractor / vendor") are listed in the directory
  and org chart but **not celebrated** by default **(setting: Include contractors)**.
- Anyone can be opted out of birthday or anniversary celebrations individually (person record →
  "Celebrate their birthday" / "…work anniversary"), for example if they prefer not to be.
- A birthday that falls before someone's start date is skipped (they weren't on the team yet).

## Which dates count

- **Only confirmed dates create celebrations.** A missing birthday or hire date never produces an event,
  email or calendar entry. Nothing is guessed.
- A birthday marked **needs review** (for example an impossible birth year in the HR file) is not
  celebrated until an administrator enters or confirms it. The dashboard counts these.
- **Birth years are not kept (setting).** Only the month and day are stored. During an import the year
  is used to catch impossible dates and then discarded. No email, chart, calendar or export ever shows a
  year of birth or an age.

## February 29

Birthdays and hire dates on February 29 are celebrated on **February 28** in non-leap years
**(setting: or March 1)**. Service years change on that same day.

## Work anniversaries and rehires

- Anniversaries count **completed years from the most recent hire date**. Someone who left and was
  rehired starts again from the rehire date **(setting: count from the original hire date instead)**.
  The original hire date is kept on the record for reference.
- The first celebration is the **1-year** anniversary; the hire date itself is not an anniversary.
- **Service years** shown in profiles are completed years as of today; they change on the anniversary.

## Who is reminded (the celebration owner)

Worked out each time a message is prepared, so changes take effect immediately:

1. An **owner assigned** on the person's record (for special cases such as regional staff or shared roles).
2. Otherwise their **direct supervisor**.
3. If the supervisor isn't available (former employee, on leave, no work email, or none recorded): the
   **manager of their primary property**.
4. If there's still nobody: the **next manager up** the reporting chain **(setting)**.
5. If there's nobody at all, the celebration is flagged **No owner** on the dashboard and calendar, and
   the designated administrators get an alert before the date (once per celebration).

An owner must be an active employee with a work email, and is never the person being celebrated.
Dotted-line managers can be copied on reminders **(setting, off by default)**.

## When messages go out

All at the daily send time, **8:00 AM America/New_York (setting)**:

- **1st of the month**: each owner gets a summary of the month for the people they're responsible for
  ("November Employee Celebrations - Dwell Communities"). Owners with nothing that month get nothing,
  unless "no celebrations" notes are switched on **(setting)**. If the server was down on the 1st, the
  summary goes out on the next run within 3 days **(setting)**.
- **3 days before (setting)**: an advance reminder.
- **On the day**: "Today Is Maria's Birthday!" / "Today Is Jordan's 5-Year Work Anniversary!"
- Executives and administrators who opt in (Users & access) receive an organization-wide monthly
  report, including which celebrations have no owner.

Each message is sent at most once (per person, celebration and recipient), however often the job runs.
A day-of email missed because the server was down all day is not sent late.

## Test mode and going live

Celebration emails start in **test mode**: they are prepared exactly as they would be sent, labelled
`[TEST]`, and delivered only to the chosen administrator's test inbox (or simply recorded). Nothing
reaches supervisors until a system administrator switches to **live** on the Notifications page by
typing `GO LIVE`. The switch is recorded in the audit log and can be reversed at any time.

## Follow-through

Owners and executives can mark a celebration **Planned**, **Celebrated** or **Skipped**, with a note.
An email being delivered is not taken as proof that anyone celebrated.

## Data retention

- Uploaded spreadsheets are deleted as soon as they are read. Their rows stay in the database only until
  the import is applied or discarded; unreviewed previews are erased after 30 days **(setting)**.
- Email history (including message bodies) is kept for 2 years **(setting)**, then deleted.
- Former employees stay on record (status "Former employee") so history and reporting are kept; records
  created by mistake can be deleted by an administrator.
- Nightly backups are kept for 14 days on the server (`storage/backups`, owner-only permissions).
