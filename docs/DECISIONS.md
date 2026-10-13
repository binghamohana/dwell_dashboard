# Outstanding approvals and data questions

What Dwell Central needs from Dwell leadership and HR. The defaults in use until then are shown, all
deliberately conservative. Individual names are listed in the separate *source data review* (kept out
of this repository because it contains personnel information).

## Approvals

| # | Decision | Until decided |
|---|---|---|
| A1 | **Go live with celebration emails** (Notifications → Live) once the data below is in good shape | Test mode: messages only reach the administrator's test inbox |
| A2 | **Sender account** for email: a Dwell-owned mailbox (e.g. `central@…`) with an app password, or the Workspace SMTP relay | Mail is written to the server log |
| A3 | **Google Workspace sign-in**: create an OAuth client in a Dwell-owned Google Cloud project ([GOOGLE.md](GOOGLE.md) §1) | Email + password sign-in |
| A4 | **Google Calendar**: approve option (a) dedicated shared calendars written by a service account, which calendars, and who may read each ([GOOGLE.md](GOOGLE.md) §2) | Not built; in-app calendar and reminders only |
| A5 | **Who handles alerts** (celebrations with no owner, delivery failures) | Every system administrator |
| A6 | **Who receives the organization-wide monthly report** | Opt-in per executive/administrator (Users & access) |
| A7 | **Keep years of birth?** The brief allows it "only if authorized" | Not kept: month and day only |
| A8 | **Contractors / vendors (1099)**: celebrate them? | Listed in directory and org chart, not celebrated |
| A9 | **February 29** celebrated on Feb 28 or Mar 1 in non-leap years | February 28 |
| A10 | **Rehires**: anniversaries from the most recent hire date or the original one | Most recent hire date |
| A11 | **Employee role** (staff seeing the directory): when, and which fields | Role exists; no employee accounts created |
| A12 | **Retention**: email history 2 years, unapplied import previews 30 days, backups 14 days | As listed |

## Data questions (from the HR spreadsheet)

| # | Question | Effect today |
|---|---|---|
| D1 | **The org chart**: each person's supervisor (and any dotted-line manager), job title and department. The brief mentions an org chart that wasn't in the files received; the spreadsheet has no supervisors, titles or departments | Reporting-lines view is grouped by property; nobody has a celebration owner except through property managers, so nothing would be routed to supervisors yet |
| D2 | **Work emails** for supervisors and managers at minimum | Owners need an email to be reminded; managers need one to sign in |
| D3 | **Employee IDs** from the HR/payroll system, if one exists | Imports match by name (works, but two people with the same name need a manual choice) |
| D4 | **10 of 45 active birthdays** need fixing: 9 have impossible dates (born after, or months before, their hire date; years 2021–2026) and 1 is blank. Month and day are enough | Imported as "needs review"; not celebrated |
| D5 | **Conflicts between the main sheet and the division sheets**: 2 people are active on one and terminated on the other; 4 active people appear only on division sheets; 1 person is marked active but has a term date; 2 active people appear on no division sheet | The main sheet is used; the 4 people are not imported until confirmed |
| D6 | **Legal entity → brand mapping**: Dwell Communities, LLC; 58 Place, LLC; Uplift Georgia Inc.; Grove Park Renewal, Inc.; Clairmont Inc; 1099 contractors. Which brand does each belong to, and is "Clairmont Inc" a separate employer? | Set on import; editable under Organization |
| D7 | **Full names and managers** for each property/division: 750, Arbor, Clairmont, EastGate, Ilion, Westside, Grove, Corporate, Landscape (is Landscape a team rather than a property?) | Named as in the file |
| D8 | **3 active people have no division**; they appear on the Corporate sheet | Assigned on import as you choose |
| D9 | Of the 4 people only on division sheets, one starts later this month and one has a 1998 hire date: worth confirming when they're added | Not imported until confirmed (D5) |
| D10 | 23 former employees have no term date; 2 former employees have birth dates in the future | Former employees are not imported |
