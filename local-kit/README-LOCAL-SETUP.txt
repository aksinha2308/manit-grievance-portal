MANIT GRIEVANCE PORTAL: LOCAL COPY OF THE LIVE SETUP (Windows)
=================================================================
Full instructions: MANIT-Grievance-Portal-Local-Setup-Guide.pdf (read it once; this page is the short version).

WHAT YOU GET
  The live portal code, running on your own computer at http://localhost:8080, with made-up ERP databases
  (students, staff, hostels) and made-up authorities and grievances. Nothing connects to the real ERP.

BEFORE YOU START (once)
  1. Install PHP 8.2 or newer (XAMPP is fine), add its folder to PATH, switch on the extensions
     pgsql, intl, mbstring, zip, curl, openssl in php.ini.
  2. Install PostgreSQL (any recent version; live uses 14). Keep port 5432. Note the "postgres" password.
  3. Extract live-grievance-portal.zip to  C:\grievance-local  (it must contain the file "spark" and the folder "vendor").
  4. Extract this kit so that the folder  C:\grievance-local\local-kit  exists.
  5. Optional, for the administrator screens: copy local-kit\local-settings.example.txt to local-settings.txt and fill it in
     (your staff e-mail, and the Google client that institute IT creates for http://localhost:8080).

BUILD IT
  Double-click  local-kit\Setup-Local.bat . Type the postgres password, then YES. Takes about 3 minutes.
  It can be run again whenever you want a clean start.

USE IT
  local-kit\Start-Local.bat       starts the portal (keep the window open)
  local-kit\Show-OTP.bat          shows one-time codes (nothing is mailed)
  demo\DEMO-LOGINS.txt            made-up logins. Use "Test sign-in" with these e-mails.
                                  (Its ADMINISTRATOR line is out of date: use /admin-login with Google.)

WHAT THE SETUP CHANGES
  - Creates two databases: grievance_local, grievance_hostel_local, and the accounts grievance_app and erp_ro.
  - Replaces C:\grievance-local\.env (old one kept as .env.backup-date).
  - Replaces ONE code file, app\Libraries\Erp\ErpSync.php (original kept as ErpSync.php.orig). See code-changes.
  Never run it on a computer that holds the real portal.
