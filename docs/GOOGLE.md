# Google Workspace: sign-in and calendar

Both need approval because they involve Dwell's Google Workspace and a Google Cloud project. Nothing
here is switched on until someone with Workspace admin rights sets it up in a **Dwell-owned** project
(not a personal account).

## 1. Sign in with Google (built, off until configured)

People sign in with their Dwell Google account instead of a password. Google only confirms who they
are: they still need a Dwell Central account created by an administrator, with the same email.

**Setup (about 10 minutes, Workspace admin):**

1. In <https://console.cloud.google.com/>, create (or choose) a project owned by Dwell's organization,
   e.g. "Dwell Central".
2. **APIs & Services → OAuth consent screen**: User type **Internal** (only your Workspace can use it).
   App name "Dwell Central"; scopes: `openid`, `email`, `profile` (nothing else).
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**: type **Web application**;
   authorized redirect URI: `https://www.58dwell.com/dash/google.php`.
4. Put the client ID and secret in `config/config.php`:

   ```php
   'google' => [
       'client_id'     => '….apps.googleusercontent.com',
       'client_secret' => '…',
       'hosted_domain' => 'your-workspace-domain.com',   // only accounts from this Workspace domain may sign in
   ],
   ```

The sign-in page then shows "Sign in with Google". Password sign-in keeps working (for the server-side
`bin/admin.php` recovery account, for example).

What is checked: the response comes straight from Google over TLS; the token must be for this client,
unexpired, with a verified email in the configured domain, and match the sign-in attempt (state and
nonce). Unknown emails are refused and logged.

## 2. Google Calendar (designed, waiting for approval)

The brief asks to compare (a) a dedicated shared "Dwell Celebrations" calendar with (b) events placed in
each supervisor's own calendar.

| | (a) Dedicated shared calendar(s) | (b) Supervisors' own calendars |
|---|---|---|
| Google permissions needed | A service account given "Make changes to events" on **only** that calendar. No domain-wide access | Domain-wide delegation (the app can act as any user and write any calendar), or every supervisor's individual consent |
| Who can see the events | Exactly who the calendar is shared with (a Google Group per audience) | Each supervisor, plus anyone they share their calendar with |
| When a supervisor changes | Nothing to move | Events must be deleted from one person's calendar and created in another's |
| When someone leaves | Their events are removed in one place | Removed from whichever calendars hold them |
| Failure modes | One calendar to check | Many calendars; a departed supervisor's calendar may be unreachable |

**Recommendation: (a)**, with one calendar per audience so nobody sees more than the dashboard already
allows them to, e.g. "Dwell Celebrations: Leadership" (everyone) and one calendar per property shared
with that property's managers. It needs the least access, is owned by Dwell rather than individuals,
and is easy to audit or switch off.

**Design** (to build once approved):

- A service account in the same Dwell-owned Google Cloud project. Each celebrations calendar is created
  by a Dwell Workspace account and shared with the service account ("Make changes to events") and with a
  Google Group for its readers. Knowing someone's email never lets the app write to their calendar.
- All-day events titled like "🎂 Maria G. — Birthday" or "🎉 Jordan E. — 5 years at Dwell", with no
  year of birth or age and no other personal details.
- **Stable event IDs** derived from the person's Dwell Central ID, the celebration type and the year
  (e.g. `dc` + base32hex of a hash), so a repeated or interrupted sync updates the same event instead of
  creating a duplicate.
- Birthdays and anniversaries are written as individual events for the next 12 months (anniversary titles
  change every year; February 29 dates follow the policy), refreshed nightly.
- A sync table records what was written; a person becoming inactive, losing a confirmed date or opting
  out removes their future events.
- The same test mode: "dry run" lists what would be created, changed or removed; changes to calendars
  start only after an administrator approves going live.

**Approvals needed:** a Dwell-owned Google Cloud project; the service account; which calendars to create
and who may read each one; who owns them. Until then, the monthly summaries, reminders and the in-app
celebrations calendar cover the workflow without touching anyone's Google Calendar.
