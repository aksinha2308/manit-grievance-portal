# Live portal compared with our version (Patch 18, without Module 8)

Compared: `live-grievance-portal.zip` against the Patch 18 code we both started from (`vendor/`, `writable/` and `.env` excluded). 52 files differ, 3 files were removed, 4 files are new. Full unified diffs are in `diffs/` (one `.diff` per file; `-` is our version, `+` is live).

## 1. Departments now come from the real ERP table `establishment.dpcode`
Portal-owned departments are replaced by the ERP's own IDs and names.
- **New migration `2026-11-10-000013_DpcodeDepartments`:** maps every used department (people, grievances, post holders) to one `dpcode` row, by ERP code or by name. It stops with a list if any department matches none or several. Then it re-points the foreign keys to `establishment.dpcode(dept)` and **drops the portal's `departments` table**.
- **Changed to read `dpcode` instead of `departments`:** `PostService`, `ListService`, `MasterListService`, `MasterListImporter`, `SubmissionService`, `GrievanceSearch`, `ReportService`, `ExportService`, `Admin/Lists`, `Admin/MasterList`, `Admin/PostHolders`, `Admin/Users`, and the views `admin/lists`, `admin/people/form`, `admin/people/import`, `admin/post_holders/show`, `admin/grievances/index`.
- **Effect:** departments can no longer be added or edited in the portal. The department list in the bulk-upload template comes from `dpcode`.
- **ERP admin tools removed:** `ErpSync::createDepartments`, `createHostels` and the "unmapped hostels" scan.

## 2. Hostels are now plain text, not a portal list
- **Grievances and post holders:** a grievance stores `hostel` and `room` as text instead of `hostel_id`. A post holder's hostel scope is also text.
- **Matching:** matching to a Warden is by `lower(btrim(hostel))` in `WorkflowService`, `PostService` and `GrievanceSearch`. The "hostel not set up" message and the portal Hostels list are gone from the submission path.
- **Bulk upload:** the people template gets a **Room** column. Hostel and Room are free text, and blank clears them.
- **Related files:** `SubmissionService`, `WorkflowService`, `Submit`, `PostHolders`, `submit/form`.
- **I have not seen the migration that changes these columns.** The one migration in the zip only handles departments. Please confirm how `hostel`/`room` were added on the live database.

## 3. ERP access rewritten for the real ERP (`ErpDirectory`)
- `PostgresErpSource.php`, `ErpSource.php` and `BulkUserImporter.php` are removed. `ErpDirectory` now contains the queries and connection handling itself, using real schema names `academic`, `establishment` and `manit_hostel_management`.
- **Student by roll number:**
  - It joins `academic.studentmas` to `establishment.dpcode` and to `student_program_enrollment_versions`.
  - Only registered semesters (`semester_reg_completion_status = 'R'`) are used.
  - It excludes programs whose `degree_level_id_code` is 82, and takes the latest semester.
  - Mobile and program are returned as NULL.
- **Employee by e-mail:** reads `establishment.empmas`, with department from `dpcode`. Mobile comes from `mobile_no`.
- **Hostel:** reads `hostel_allot`, `hostel_rooms` and `hostal_master` (room, hostel abbreviation, name), for active final allotments.
- **Semester:** the semester shown is the ERP value **minus 3**. This is hard-coded in `ErpDirectory` (lines about 421-422). Please confirm this is deliberate.
- `ErpSync`, `Admin/ErpAdmin`, `admin/erp/index` and `admin/erp/sync` were reworked to match. `Config/Erp.php` comments updated, and `FakeErpSource` changed to fit.

## 4. Sign-in and OTP
- **Administrator Google sign-in:** new `admin-login` page and route `auth/admin/google`. `GoogleAuth::authUrl('admin')` carries a flow flag, and `Auth::loginAdminWithEmail` lets the admin account whose e-mail matches the verified Google account in. The admin password still works. Ordinary Google sign-in now excludes the admin row (`AND NOT is_admin`).
- **Auth debug lines:** `Auth.php` has commented-out `echo`/`print_r`/`die` lines, and `loginAdminWithEmail` logs the SQL and bind data at debug level. `ErpDirectory` logs each student's profile at debug level.
- **OTP:**
  - **Mobile channel:** now off when `otp.smsDriver` is empty.
  - **Test mode:** a fixed code, 1234, is added. `OtpService::testMode()` is hard-coded to return false, so it is off.
  - **Messages:** wording changed, since the OTP now goes to e-mail only when SMS is empty.
- **Other:** `AuthController`, `login`, `home` and `Routes` changed for these.

## 5. Settings and housekeeping
- `Config/App.php`: timezone `UTC` to `Asia/Kolkata`. `Config/Database.php`: default connection has `schema => 'grievance'` (portal tables live in their own schema).
- `public/index.php` and `spark`: the PHP-version check was removed.
- `DemoData`, `PortalTest`, `InitialDataSeeder` and `demo/setup-demo.ps1` were updated for the new department and hostel model. `PortalTest` has 250 changed lines.
- Docs changed: `README`, `.env.example`, `docs/ERP-PEOPLE.md`, `docs/GOOGLE-LOGIN-AND-OTP.md`.
- **Leftover files in the zip:** `BulkUserImporter.php.donotuse`, `ErpDirectory - Copy.php`, `PATCH-10-DELETE-THESE-FILES.txt`, `dump-demo_portal-202611041737.sql`, and a root file `env`.

## 6. Points for your decision
1. Dropping `departments` cannot be undone without a backup, and departments can no longer be managed in the portal.
2. The semester shown is the ERP value minus 3.
3. Leftover `Copy`/`donotuse` files and debug logging should be removed.
4. The zip includes live logs, attachments, a database dump and an `env` file. I did not open the dump, `env` or the logs.
