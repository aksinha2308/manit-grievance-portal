# MANIT Grievance Portal: Module 8, Stage 1: change list

**Base used:** `grievance-portal-tilpatch18_demo_fullworking.zip` (patch 17 + 18 theme + 19 demo kit + 20 footer; 1,069 files; no `.env` inside).
My working copy was verified identical to that zip before I started, and every diff in this package is against it.
**Not touched:** all ERP code (`app/Libraries/Erp/*`, `app/Config/Erp.php`, ERP controllers/views/commands), the authority/post/workflow code, the `Account` controller, `Auth::attempt()`, `Auth::changePassword()`, `portal:create-admin`, `portal:reset-admin-password`.

**Test status (sandbox, PHP 8.3, PostgreSQL 16):** built-in self-test 471 → **510 passed, 0 failed** (39 new checks); web suites auth 76, module2 170, module3 103, module4 166, module5 77, module6 87, module7 81, erp 41 (all equal to before) plus the new `admin_mode_test.sh` 35/35.
The new one-command runner `tests/run_all_tests.sh` ran all of the above on a throw-away database and ended with ALL TESTS PASSED.
**Not testable in the sandbox:** the real Google account chooser and real SMTP (no route to Google/SMTP). They are in the go-live checklist.

## How to merge
`production/files/…` holds each changed file in full at its real path; `production/diffs/….diff` is the same change as a unified diff (made against the base). With vimdiff: `vimdiff your/path new/path`. New files are marked **NEW**: simply copy them. Order below = suggested merge order. Risk: **HIGH** = sign-in/roles/layout, review line by line; MED; LOW.
`demo-only/` is for the demo kit only and is NOT part of the production merge.

## A. New files (copy as they are)
| # | File | What | Why | Risk |
|---|------|------|-----|------|
| 1 | `app/Database/Migrations/2026-11-06-000013_AdministratorTable.php` | NEW. Table `administrator` (exactly one row: the administrator's staff e-mail). Carries an e-mail that was on the old admin `users` row over as first administrator (`set_by = 'migration'`) and clears it from that row. `down()` reverses it. | The administrator is now a person (staff e-mail), not a password account. | MED (touches `users.email` of the admin row only) |
| 2 | `app/Libraries/AdministratorService.php` | NEW. `current()`, `isAdministrator()`, `accountId()`, `checkPerson()` (staff domain, found on ERP via the read-only `ErpDirectory::lookupStaff`, or on the master list), `set()` (transaction, audited `set_admin`; creates the technical `users` admin row if missing). | One place for all administrator rules. | MED |
| 3 | `app/Commands/SetAdmin.php` | NEW. `php spark portal:set-admin [email] [--yes]` (no argument = show current). | How the vendor names the first administrator; replaces `portal:admin-email`. | LOW |
| 4 | `app/Commands/PortalCheck.php` | NEW. `php spark portal:check [--dev]`: read-only go-live check (PHP/extensions, production mode, https, cookies, Google keys, `google.devLogin` off, DB and migrations, administrator found, SMTP reachable, Google reachable with certificate, ERP `ping()` only, writable folders, `.env` permissions, `demo/` absent). Exit 1 on any FAIL. | The vendor needs one command that says whether the install is ready. | LOW |
| 5 | `app/Controllers/Admin/Administrator.php` | NEW. Admin screen to see and hand over the administrator (needs the confirm box; refuses the same person; signs the old administrator out; audited). | Hand-over inside the portal. | LOW |
| 6 | `app/Views/admin/administrator/index.php` | NEW. View for #5. | | LOW |
| 7 | `app/Views/auth/admin_code.php` | NEW. Page where the administrator types the e-mailed 6-digit code (with Resend). | Second step of the Administration link. | LOW |
| 8 | `app/Filters/ProductionHeaders.php` | NEW. In production only: Content-Security-Policy, HSTS (https only), Permissions-Policy, added to the headers the existing `secureheaders` filter already sets. CSP allows `self`, cdn.jsdelivr.net, Google Fonts, and `unsafe-inline` for scripts/styles (your views have inline code). | Security hardening for the live server. | MED. If any page misbehaves on the live server, remove `'prodheaders'` from `Filters.php` (change B-1) to switch it off at once |
| 9 | `deploy/_env.sh`, `deploy/backup.sh`, `deploy/restore.sh`, `deploy/clean-logs.sh`, `deploy/cron.example`, `deploy/make-release.sh` | NEW. Nightly DB + attachments backup (14 daily + 12 monthly, dump verified, files 600), restore, log clean-up (app logs 30 days, `otp-*.log` 7 days), cron examples, release-zip builder (no `.env`, no `demo/`, no `~`/`.un~` files, empty `writable/`). All tested in the sandbox. | Backups, log rotation and a clean hand-over package. | LOW (nothing in the app uses them) |
| 10 | `docs/OPERATIONS.md` | NEW. Backups, restore, logs, `portal:check`, administrator. | Vendor documentation. | LOW |
| 11 | `tests/run_all_tests.sh` | NEW. One command for all tests on a temporary copy + throw-away database `grievance_runner_test` (refuses names not ending in `_test` or equal to the live DB; your `.env` is never read for writing). | Safe test run. | LOW |
| 12 | `tests/manual/admin_mode_test.sh` | NEW. 35 web checks for the Administration link, code, admin mode, idle timeout, hand-over. | Tests for the new behaviour. | LOW |

## B. Changed files
| # | File | Change | Reason | Risk |
|---|------|--------|--------|------|
| 1 | `app/Config/Filters.php` | `use App\Filters\ProductionHeaders;`, alias `'prodheaders'`, and `'prodheaders'` added to the global `after` list. | Activates #A8. | MED |
| 2 | `app/Libraries/Auth.php` | (a) `SESSION_KEYS` + `'admin_pending'`. (b) In `loginWithEmail`, the staff `users` lookup gets `AND NOT is_admin`, so a normal sign-in can never produce admin rights. (c) `user()` shows the administrator's own name in admin mode. (d) New `canEnterAdmin()` and `enterAdminMode()` (clears the session, new session id, technical admin account, audited `login via=admin`). (e) Class comment only. `attempt()` / `changePassword()` untouched. | The core of the new administrator design. | **HIGH** (Auth) |
| 3 | `app/Controllers/AuthController.php` | `googleCallback` goes to `adminAfterGoogle()` when the Google round-trip was started by the Administration link; `attempt()` (admin password POST) now begins with `devOnly()` (404 unless `google.devLogin` in development/testing); `login()` no longer passes `showAdmin`; new `adminStart`, `adminAfterGoogle`, `adminCodeForm`, `adminCodeResend`, `adminCodeVerify`, `adminPending`, `sendAdminCode`. Any mismatch logs the earlier session out. | The Administration link flow; no admin password on the live portal. | **HIGH** (Auth) |
| 4 | `app/Libraries/GoogleAuth.php` | `authUrl($intent = 'login', $hint = null)` stores the intent in the state and adds `login_hint`; new `lastIntent()`; `emailFromCallback` records the intent. Prompt stays `select_account` (Google does not support `prompt=login`). | Re-shows the account chooser for the Administration link. | MED |
| 5 | `app/Libraries/OtpService.php` | `send($person, $purpose = 'submit a grievance')` (the text says what the code is for); new `withoutCode()`; in **production** the 6-digit code is blanked (`******`) in `otp-*.log` and in the failed-e-mail log line. | "Never log the OTP in production". Default behaviour for grievance OTPs is unchanged. | MED |
| 6 | `app/Config/Routes.php` | Added `GET auth/admin`, `GET/POST auth/admin/code`, `POST auth/admin/code/resend`, and in the admin group `GET/POST administrator`. `POST login` is kept (for the test scripts) but the controller makes it 404 in production. | Routes for the above. | **HIGH** (routes) |
| 7 | `app/Views/layouts/main.php` | "Administration" button (`id="enter-admin"`) shown only when `Auth::canEnterAdmin()`. Nothing else changed. | The link. | **HIGH** (layout) |
| 8 | `app/Views/auth/login.php` | Removed the whole `<details id="admin-login">` administrator password form. | The common login page shows Google only. | **HIGH** (login page) |
| 9 | `app/Views/admin/_nav.php` | One menu item "Administrator". | Reach #A5. | LOW |
| 10 | `app/Commands/AdminEmail.php` | Replaced by a stub that says "use portal:set-admin" and exits with an error. | The old command no longer makes sense. | LOW |
| 11 | `app/Commands/PortalTest.php` | `use AdministratorService`; new section `administrator()` (39 checks) after `googleAndOtp()`. | Self-tests for the new code. | LOW |
| 12 | `.env.example` | Added `cookie.secure = true`. | Cookies only over https. Note: the existing `app.sessionDriver` / `app.sessionSavePath` lines in this template have no effect in CodeIgniter 4.7 (its key is `session.driver`), so the portal keeps file sessions in `writable/session`. I left it as it is. | LOW |
| 13 | `README.md`, `docs/GOOGLE-LOGIN-AND-OTP.md` | Text about `portal:admin-email` / admin password replaced by `portal:set-admin` / Administration link. | Documentation. | LOW |
| 14 | `tests/manual/auth_test.sh` | Check "keeps the admin password login" now checks the form is **absent**; CSRF token for the test login now read from `/auth/dev` (the login page no longer has a form). | Follows #B8. | LOW |
| 15 | `tests/manual/module2…module7_test.sh` | One line each: token for the test login read from `/auth/dev` instead of `/login`. | Follows #B8. | LOW |
| 16 | `tests/manual/_preflight.sh` | `as_user` for the admin account uses the development-only password route. | Used by `erp_test.sh` to open the admin pages. | LOW |

## C. Demo-only (not for the live server) in `demo-only/`
`app/Commands/DemoData.php` (makes `neha.gupta@manit.ac.in`, a Professor on the demo ERP, the demo administrator and writes the new instructions into DEMO-LOGINS), `demo/setup-demo.ps1` (no admin password any more), `demo/README-DEMO-SETUP.txt`, `demo/DEMO-LOGINS.txt`. Demo flow tested: test sign-in as Neha Gupta, Administration, code from `Show-OTP.bat`, admin dashboard 200.

## D. Important for the live upgrade
1. **Run `php spark migrate` and then immediately `php spark portal:set-admin name@manit.ac.in`.** The old admin password login stops working on the live portal (404). If the old admin row had an e-mail, it becomes the first administrator automatically; check with `php spark portal:set-admin` (no argument).
2. The named person must be on the ERP (when people come from the ERP) or on the master list as faculty/non-teaching staff.
3. Rollback: `php spark migrate:rollback` undoes the table and restores the e-mail on the old admin row.
