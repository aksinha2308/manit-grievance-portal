# MANIT Module 8, Stage 2: change list (on top of Stage 1)

Merge with vimdiff exactly as in Stage 1. `files/` holds the full new file, `diffs/` the difference from the Stage 1 version (or from the original, for a file not in Stage 1). No ERP code is touched. Nothing here sends scheduled reminders, notification e-mails or SMS.

## A. Production files (merge into the live code)

| File | Change | Reason |
|---|---|---|
| app/Config/Otp.php | New setting `emailOnlyTo` (default empty) | Lets the demo mail codes only to the one real administrator. **Empty = no effect**; live portal leaves it empty. |
| app/Libraries/OtpService.php | In `sendEmail()`: if `emailOnlyTo` is set, any other address is only written to the log | Stops real mail going to made-up demo addresses that could belong to real people. No change when the setting is empty. |
| app/Commands/PortalCheck.php | New check: warns if `emailOnlyTo` is not empty; extension list also gd and xml | A live server must not have the demo safeguard on. |
| app/Commands/PortalTest.php | Two new checks for `emailOnlyTo` (exists, default empty). Total 512 | Keeps the self-test in step. |

## B. Demo-only files (not needed on the live portal)

| File | Change | Reason |
|---|---|---|
| app/Commands/DemoEnv.php (new) | `php spark portal:demo-env`: reads demo-settings.txt, checks it, writes the extra .env lines and the SQL that adds the administrator to the dummy ERP | Real Google and e-mail for the administrator only. |
| app/Commands/DemoData.php | Administrator taken from `--admin-email`; login list text updated | Demo administrator is a real person now, not a fixed one. |
| demo/setup-demo.ps1 | Reads demo-settings.txt, no administrator password, appends the extra settings to .env | Same. |
| demo/demo-settings.example.txt (new) | Template the vendor fills in | Same. |
| demo/README-DEMO-SETUP.txt | Step 0 (settings file); administrator steps updated | Same. |

## C. Verified
`bash tests/run_all_tests.sh` after these changes: portal:test 512/0, auth 76, module2 170, module3 103, module4 166, module5 77, module6 87, module7 81, erp 41, admin_mode 35, all passed.

## D. Not tested here
Real Google sign-in, real SMTP and the Windows PowerShell script itself (no Google account, mail server or Windows in the test environment). The vendor demo document has a sign-off list for these.
