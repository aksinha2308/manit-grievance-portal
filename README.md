# MANIT Grievance Redressal Portal

PHP 8.2+ (built on 8.3), CodeIgniter 4.7, PostgreSQL 15+ (built on 16). Bootstrap 5, no build step.

Status: **Module 7A of 8** (setup/auth, admin configuration, form fields + master list, submission,
workflow engine, tracking and visibility, admin search / export / report / archive, **Google sign-in + OTP**).
See the final specification for the full plan.

**Module 7A:** everyone signs in with the institute Google account (students @stu.manit.ac.in, staff @manit.ac.in);
the admin keeps a password as an emergency login; an OTP (e-mail + master-list mobile) is checked before every
grievance; "My grievances" replaces tracking by reference number. Set-up: **docs/GOOGLE-LOGIN-AND-OTP.md**.

## Requirements
PHP extensions: `pgsql`, `intl`, `mbstring`, `gd`, `zip`, `xml`, `curl`.

## Install
1. Create the database and user:
   ```sql
   CREATE USER grievance WITH PASSWORD '<strong password>';
   CREATE DATABASE grievance_db OWNER grievance;
   ```
2. `cp .env.example .env` and set `app.baseURL`, the database password and `CI_ENVIRONMENT`.
3. Create the tables and load defaults:
   ```
   php spark migrate
   php spark db:seed InitialDataSeeder
   ```
4. Create the single admin account (a temporary password is printed once; the admin must change it at first login):
   ```
   php spark portal:create-admin --username admin --name "Portal Admin"
   ```
5. Point the web server's document root at `public/`. For a quick local run: `php spark serve`.
6. Make `writable/` writable by the web server user.

Forgotten admin password: `php spark portal:reset-admin-password`.
Let the admin also sign in with Google: `php spark portal:admin-email name@manit.ac.in`.
Then set up Google sign-in and the OTP e-mail: see docs/GOOGLE-LOGIN-AND-OTP.md.

## Bootstrap
The layout uses local files if present in `public/assets/bootstrap/` (`bootstrap.min.css`,
`bootstrap.bundle.min.js`), otherwise the jsDelivr CDN. For an offline server, download Bootstrap 5.3 and
place those two files there.

## What the seeder loads
Settings (reference prefix `MANIT`, attachment rules, lockout, idle timeout...), semesters 1-8, all
posts, the six grievance types (ACD, HST, DSC, OTH for students; FGEN; NGEN) with their
escalation matrices exactly as in the specification, and the submission form fields for each role.
Departments are read from `establishment.dpcode`; hostels, people and the holders of each post are managed in the portal.

## Module 2: admin configuration
After logging in as admin: Grievance types (+ escalation matrix per type), Posts, Authorities
(single add, bulk Excel/CSV upload with preview), Post holders (HOD per department, Warden per hostel...),
Lists (read-only departments from `establishment.dpcode`, hostels, semesters), Settings, Audit log.
All departments in `establishment.dpcode` are available to students, faculty, non-teaching staff, and department-scoped posts.
Upgrading from module 1: back up the database, then run `php spark migrate` (migrations 4 and 5). Migration 5
merges same-named departments that were entered separately per list into one (people, grievances and post
holders are re-pointed; it cannot be reversed, hence the backup).
Bulk upload previews are kept briefly in `writable/imports/` (private files, removed on confirm/discard).

## Module 3: form fields and master list
- **Form fields** (admin menu): per role (Student / Faculty / Non-Teaching) rename, reorder, show/hide and require fields,
  and add custom fields (text, number, dropdown, date). Core fields (ID, name, contact, department, hostel) can be renamed
  and moved but never hidden. A custom field already used in a grievance can only be hidden.
- **Master list** (admin menu): the people allowed to submit. Add one person, or bulk upload from the Excel/CSV template
  (update existing + add new, nobody removed, preview before saving, up to 25,000 rows). Deactivate people who leave.
  Format the ID column as Text in Excel so leading zeros are kept.
Upgrade from module 2: no new migration; copy the files over.

## Module 4: public submission
> Changed in module 7A: the lookup step and captcha are gone. The person signs in with Google, the form is filled
> from their master-list record, and an OTP is required before saving (docs/GOOGLE-LOGIN-AND-OTP.md).

No login. The home page offers Student / Faculty / Non-Teaching; a role is shown as "Not available yet" until it has an
active grievance type with at least one level in its matrix.
1. **Lookup**: Scholar Number / Employee Code + captcha (a distorted image drawn by the portal itself; no extra PHP extension).
   Unknown and deactivated people get the same message.
2. **Form**: name, department, semester and hostel come from the master list and are locked; the contact number is
   pre-filled and editable (the change is kept on the grievance only, never written to the master list). Then grievance
   type (one fixed type for Faculty/Non-Teaching), the custom fields you defined, subject, description, attachments.
3. **Receipt**: the reference number, e.g. `MANIT-2026-ACD-000001` (prefix, digits and per-type yearly sequence as configured).
   The grievance starts at level 1 of its matrix; the first action ("submit") and two notification events are logged.
- A pending grievance of the same type by the same person triggers a warning with a tick-box to submit anyway.
- Attachments are checked for type, size, count and real content (a renamed text file is refused), stored outside the
  web root in `writable/attachments/` under random names. Nothing is saved if any step fails (the reference number is not used up).
- Safeguards: CSRF on every form, captcha single use, per-IP limits (100 lookups / 60 submissions per 10 minutes),
  the lookup context expires after the idle timeout setting and is used up by a successful submission.
- PHP's own upload limits also apply. If large files fail, raise `upload_max_filesize` and `post_max_size`
  in php.ini (XAMPP default is 40M; plain PHP defaults to 2M and 8M).
- Fix included: text typed into a form that is shown again after an error is no longer HTML-escaped twice.
Upgrade from module 3: no new migration; copy the files over. Faculty and Non-Teaching routes need an HOD / Reporting
Officer post holder only when the grievance is *handled* (module 5); submitting works without one.

## Module 5: workflow engine
Authorities now handle grievances. After logging in, an authority sees the grievances at the post(s) they hold:
- **Needs action**: pending at their post, overdue ones first (red, with a banner).
- **Waiting for submitter**: they asked for more information and the submitter has not replied yet.
- **Earlier**: grievances that passed through their post and have since moved on or been disposed (view only).

Opening a grievance shows the details, the submitter's contact number, the attachments (download), and the full
history. The holder of the current post can:
- **Dispose**: resolve and close it.
- **Escalate**: send it to the *next* level of the matrix only. Not possible at the last level (Director).
- **Request information**: ask the submitter a question. The status becomes "Awaiting submitter" and the overdue clock
  pauses. While waiting, the authority can still dispose or escalate (e.g. if the submitter never answers), after
  ticking "I understand the submitter has not replied yet". Escalating withdraws the question, but if the submitter
  answers later the answer is still added to the history ("Submitter replied (late)") for the level now handling it,
  without changing the status or the overdue clock. One late answer per question; none after disposal.
A remark is mandatory for all three. Every action is kept permanently in the history with the person, post and time.
The submitter's reply screen arrives with tracking in module 6 (the engine for it, including late answers, is already
in place and tested). In the "Needs action" list a grievance shows a "Replied" or "Late reply" badge when the
submitter's answer is the latest entry.

Rules worth knowing:
- **Access follows the post.** When the admin assigns a new holder, the new holder gets the open grievances *and* the
  earlier ones of that post; the previous holder loses access at once.
- **Vacant post**: the grievance waits there. Authorities see nothing; the admin dashboard lists every grievance waiting
  at a post with no holder, with an "Assign holder" button.
- **Overdue**: time at the current level (not counting time waiting for the submitter) beyond that level's overdue days
  (7 by default, set per level in the matrix). Overdue means reminders only; nothing moves automatically.
- **Matrix changes**: removing a level moves its open grievances to the next level (history entry "Re-routed");
  reordering does not move anything.
- If two people (or two browser tabs) act on the same grievance at once, the second one is refused with
  "was updated while you were looking at it", so nothing is ever done twice.

**Overdue reminders.** No e-mail is sent in v1; reminders are logged as events, ready for e-mail later. Run once a day:
```
php spark portal:reminders
```
On Windows, add a daily task in Task Scheduler: Program `C:\xampp\php\php.exe`, Arguments `spark portal:reminders`,
Start in: the portal folder. On Linux: `15 9 * * * cd /path/to/portal && php spark portal:reminders`.
Running it more often is harmless: a grievance is reminded when it becomes overdue and again every <overdue days>.

Upgrade from module 4: back up the database, copy the files over, then run `php spark migrate` (migration 6 adds two
history columns and the reminder event type; migration 7 adds late replies), then `php spark portal:test`.

## Module 6: tracking and visibility
> Changed in module 7A: tracking by reference number + captcha is replaced by **My grievances** (`/my`) after Google
> sign-in. The grievance page, replies, late answers and attachments work as described below.

**Tracking (public, no login).** "Track your grievance" on the home page, or the button on the receipt (the receipt can
also be printed or saved as PDF). The submitter enters the reference number + Scholar Number / Employee Code + captcha
and sees:
- the status ("Under review", "Waiting for your reply", "Disposed") and which post has it now;
- the full history with **every authority remark** (questions, reasons for escalating, the final decision). Authorities
  are shown by **post only**, never by name;
- their own attachments (download);
- when a question is open, a reply box (text + attachments, same limits as submission). If the grievance was escalated
  before they answered, they can still send the answer once; it is added for the level now handling it.
After disposal nothing more can be done; the page suggests submitting a new grievance if they are not satisfied.
The grievance stays open in that browser until 30 minutes of inactivity (the idle-timeout setting) or until "Close".
For privacy there is no "list my grievances by Scholar Number": a lost reference number is looked up by the admin.
Wrong reference or wrong ID give the same message. Limits: 100 lookups / 60 replies per IP per 10 minutes.

**Authorities.**
- The action form now says that **the submitter can read the remark**.
- **Related grievances banner**: when the same person has other grievances from the last 12 months (any type), the
  grievance page lists them (reference, type, status, date). A grievance the authority may open is a link with its
  subject; one they may not open shows only the summary line.
- Dashboard: search by reference number or submitter name, filter by type and "overdue only"; someone holding several
  posts sees pending / waiting / overdue counts for each post.
- **Director oversight**: whoever holds the Director post gets an "All grievances" tab and can open any grievance,
  view only. They can act on a grievance only when it reaches the Director level.

Upgrade from module 5: copy the files over (no new migration), then `php spark portal:test`.

## Module 7: search, export, report, archive
**Admin → Grievances** (view only): every grievance, searchable by reference number, Scholar Number / Employee Code or
name (this is how to find a student's lost reference number), with filters for role, type, status, department, hostel,
current post, submitted dates, overdue and archived. Opening one shows the full details and history *including the
names of the authorities who acted*, the attachments, and the Archive / Restore button. The admin still cannot act.

**Exports** (CSV or Excel, of exactly what the filters show):
- *Grievances*: one row per grievance: submitter details including contact number, subject, description, custom
  fields, status, current post, days at level, overdue, escalations, disposal date, post and person, decision remark.
- *Full history*: one row per action with date, post, person, remark and attachment names.
- Who: the admin (everything), the Director ("All grievances" tab = everything), and every authority (only the list
  they are viewing: Needs action / Waiting / Earlier, with their filters).
- Every export is written to the audit log (who, when, filters, number of grievances). Up to 50,000 grievances per
  export. Text that Excel could run as a formula (starting with = + - @) is neutralised in CSV files. Times are IST.

**Summary report** (admin: Admin → Report; Director: "Summary report" button), for a date range (default: the last
12 months): totals, disposed %, escalated %, average and median days to disposal, by type, by department, by month, and
open / awaiting / overdue grievances per post right now (vacant posts flagged). Downloadable as Excel.

**Archive** (Admin → Archive): disposed grievances become eligible <archive_after_years> years (setting, default 3)
after their disposal date. The page shows how many and the oldest 200; one click archives them all. A single grievance
can also be archived from its page. Nothing is deleted; archived grievances leave the authorities' lists and counts,
stay findable with the "Archived" filter, remain trackable by the submitter (with a note), and can be restored by the
admin. Archive and restore are audited.

Upgrade from module 6: copy the files over (no new migration), then `php spark portal:test`.

## Tests
- **`php spark portal:test`**: self-test of the rules (works on Windows, needs only PHP). Everything runs in a database
  transaction that is rolled back, so your data is not changed. Use it after every upgrade.
With the dev server running (`php spark serve --port 8080`) and a throw-away database:
All scripts sign people in through the development-only test login: in `.env` set `CI_ENVIRONMENT = development`,
`google.devLogin = true` and keep `otp.emailMode = 'log'` (OTPs are read from `writable/logs/otp-*.log`).
- `tests/manual/auth_test.sh '<admin temp password>'`: Google/test sign-in for students, staff and authorities, admin
  password login and lockout, idle timeout, CSRF, roles, audit log (creates its own `hod.cs` fixture).
- `psql ... -f tests/manual/db_constraints.sql`: database rules (single admin, one holder per post,
  type codes, mandatory remarks, append-only history...). Runs in a transaction that is rolled back.
- `tests/manual/module2_test.sh '<admin temp password>'`: all admin configuration screens and rules
  (use a freshly migrated and seeded throw-away database; it creates its own test data).
- `tests/manual/module3_test.sh '<admin temp password>'`: form fields and master list through the web pages (fresh database).
- `tests/manual/module4_test.sh '<admin temp password>'`: submission with OTP through the web pages (161 checks; fresh database,
  it leaves test grievances behind because the action history is append-only by design). Set `DBNAME` if your database is not `grievance_db`.
- `tests/manual/module5_test.sh '<admin temp password>'`: workflow through the web pages: inbox, access by post, actions,
  attachments, stale-page protection, vacant posts, overdue reminders (77 checks; fresh database, leaves test data behind).
- `tests/manual/module6_test.sh '<admin temp password>'`: My grievances, replies and late answers, Director oversight, related banner,
  filters (87 checks; fresh database, leaves test data behind).
- `tests/manual/module7_test.sh '<admin temp password>'`: admin list and detail, exports (admin, Director, authorities),
  report, archive and restore (79 checks; fresh database, leaves test data behind).
