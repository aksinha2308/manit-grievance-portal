# People from the institute ERP

When the setting `person_source` is `erp` (`php spark portal:person-source erp`), the portal does not keep a
master list of students/staff. At each sign-in it asks the ERP who the person is and what their current
department, semester, hostel, rank etc. are (kept in the session for `erp_cache_minutes`, default 5).
The portal keeps only an identity row in `master_people` (source = 'erp'). A grievance stores a frozen snapshot.

## Matching
Accounts are created ONLY at a person's first sign-in, with the e-mail they logged in with. The bulk sync never creates accounts. The ERP has no student e-mail column (staff have `inst_email`) and no enrolled/left flag, so a student who has left but is still in the ERP can sign in until the admin switches them off.
- Student: roll number = the part of the Google e-mail before `@` (compared ignoring case/spaces).
- Staff: institute e-mail (`inst_email`). Faculty / non-teaching is decided only by the job-title text, using
  the admin-managed list (`php spark portal:designation-role`). An unlisted title blocks sign-in until it is listed.
- Duplicates in the ERP are refused; a person not in the ERP is "not registered".
- ERP down: known people can sign in and read, but cannot submit; unknown people cannot sign in; authorities unaffected.
  Hostel database down: only hostel-type grievances are blocked.

## Setup
1. `php spark migrate`
2. Add the `database.erp.*` and `database.erp_hostel.*` lines (see `.env.example`).
3. `php spark portal:erp-test` (checks the connections and mapping).
4. Ensure ERP department IDs are present in `establishment.dpcode`; map ERP hostel codes to portal hostels.
5. `php spark portal:designation-role` for the non-teaching titles.
6. `php spark portal:person-source erp`

## DUMMY ERP
Everything guessed about the ERP (table and column names) lives in `app/Config/Erp.php` and is marked with
`DUMMY ERP` comments. Replace those when the real ERP's names are known.

## Admin screens (Admin > ERP)
- **Connection and mapping**: checks both ERP databases; reports department IDs missing from `establishment.dpcode`,
  maps hostel codes to portal hostels, and assigns job titles to Faculty or Non-Teaching roles.
- **People from the ERP**: read-only list (filter by role, status, search). Only identity is stored.
- **Sync from ERP**: shows a preview (new, taken over from the old master list, changed, back, to be switched off,
  skipped problem records, unlisted titles, unmapped codes) and runs the sync on a button. Nobody is deleted; people no
  longer in the ERP are switched off. Switching off more than 25% of active ERP people needs an extra confirmation, and
  a sync is refused if the ERP returns nobody or cannot be read.

## Command line / scheduled sync
    php spark portal:erp-sync            (preview only)
    php spark portal:erp-sync --apply    (do it; add --confirm-mass-off to allow a large switch-off)
To run it every night on Windows, make a Task Scheduler task that runs `php spark portal:erp-sync --apply` in
`C:\grievance-portal`.

## Departments and hostels
Department IDs (`dept`) and names (`depname`) come directly from `establishment.dpcode`. The portal does not create,
rename, activate, deactivate, or role-filter departments; all rows are available to students, faculty, non-teaching staff,
and HOD assignments. If an ERP department ID is missing, correct the source data in `establishment.dpcode`.
Hostels remain portal-managed and are linked to ERP hostel codes.

Before deploying this change, back up the database and run `php spark migrate`. Migration 13 remaps all existing people,
grievances, and department-scoped post assignments to `establishment.dpcode.dept`, then removes the portal-owned
`departments` table. It stops with a clear error if any referenced department cannot be matched uniquely by its existing
ERP code or name to `dpcode.depname`; resolve those entries before rerunning the migration.
