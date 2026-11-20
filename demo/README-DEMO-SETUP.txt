MANIT GRIEVANCE PORTAL: DEMO SETUP GUIDE
For the person who prepares the demo computer (no programming knowledge needed)
=====================================================================================

WHAT YOU WILL END UP WITH
  A portal running on this computer at http://localhost:8080 with made-up demo data:
  students and staff (from a dummy "ERP"), demo authorities, and 12 sample grievances
  in different stages. Nothing connects to the real institute systems or the internet.

TIME NEEDED: about 30 minutes the first time (mostly installing), 5 minutes to reset later.

-------------------------------------------------------------------------------------
PART 1: INSTALL THE PROGRAMS (once per computer)
-------------------------------------------------------------------------------------
Skip any program that is already installed. Windows 10 or 11 is needed.

 1. PHP 8.2 or newer
    - Easiest: install XAMPP (apachefriends.org), choosing the version with PHP 8.2 or 8.3. Install to C:\xampp.
      You only need its PHP.
    - Add PHP to the PATH: Start menu > type "Environment Variables" > Edit the environment variables
      for your account > select "Path" > Edit > New > type  C:\xampp\php  > OK, OK.
    - Switch on the PHP extensions: open  C:\xampp\php\php.ini  in Notepad. Find each of these lines
      and delete the ";" at the start of the line:
          extension=pgsql
          extension=intl
          extension=mbstring
          extension=gd
          extension=zip
          extension=curl
      Save the file.
    - Check: open a NEW Command Prompt and type   php -v   (it must say 8.2 or higher).

 2. PostgreSQL 16 (with pgAdmin)
    - Download the Windows installer from postgresql.org and keep all default choices.
    - When it asks for the password of the "postgres" user, choose one and WRITE IT DOWN.
      The demo setup will ask for it. Keep port 5432.

 3. A web browser (Chrome or Edge).

-------------------------------------------------------------------------------------
PART 2: COPY THE PORTAL FILES
-------------------------------------------------------------------------------------
 1. Extract the full portal zip so that the folder  C:\grievance-portal  exists
    (it must contain the files "spark" and "composer.json" and the folders "app", "public", "vendor").
 2. Extract  patch-18-ui-theme.zip  into  C:\  and choose "Replace" if asked.
 3. Extract  patch-19-demo-kit.zip  into  C:\  and choose "Replace" if asked.
    You should now have the folder  C:\grievance-portal\demo  with the files
    Setup-Demo.bat, Start-Demo.bat and others.

-------------------------------------------------------------------------------------
PART 3: BUILD THE DEMO (about 3 to 5 minutes)
-------------------------------------------------------------------------------------
 1. Open the folder  C:\grievance-portal\demo
 2. Double-click  Setup-Demo.bat   (If Windows says "Windows protected your PC", click
    "More info" then "Run anyway".)
 3. Type the PostgreSQL password when asked (nothing appears while typing; that is normal) and press Enter.
 4. Read the warning. It says three demo databases will be deleted and re-created. They are only the demo
    databases listed there. Type  YES  (capital letters) and press Enter.
 5. Wait. Each step prints a line with "OK". At the end you see   DEMO IS READY.
    If you see a red "PROBLEM", read it, fix what it says and double-click Setup-Demo.bat again.
    It is safe to run it as often as you like: it always starts from scratch.

 What the script creates:
    databases   demo_portal (the portal), demo_erp (students and staff), demo_hostel (hostel allotment)
    db users    demo_app (portal), demo_ro (read-only, for the dummy ERP)
    files       C:\grievance-portal\.env  (your old .env, if any, is kept as .env.backup-<date>)
                C:\grievance-portal\demo\DEMO-LOGINS.txt  (all demo e-mail logins)

-------------------------------------------------------------------------------------
PART 4: START THE PORTAL
-------------------------------------------------------------------------------------
 1. Double-click  Start-Demo.bat. A black window opens and the browser opens the portal.
    KEEP THE BLACK WINDOW OPEN. Closing it stops the portal.
 2. First login as administrator (this one is done by YOU before the demo, not in front of the audience):
    sign-in page > "Administrator login" > username  admin   password  Demo@Admin2026
    The portal asks for a NEW password. Choose one, write it down, and give it to the presenter.
 3. Open  demo\DEMO-LOGINS.txt  (or double-click Show-Logins.bat) to see all demo logins.
 4. The presenter now follows the DEMO CHECKLIST.

Useful helpers in the demo folder:
    Start-Demo.bat    starts the portal and opens the browser
    Show-OTP.bat      shows the latest one-time codes. The portal does NOT send real e-mail or SMS in the demo;
                      the code appears here instead.
    Show-Logins.bat   opens the list of demo logins

-------------------------------------------------------------------------------------
BEFORE EVERY DEMO: RESET (recommended)
-------------------------------------------------------------------------------------
Double-click Setup-Demo.bat again. It deletes everything done in the previous demo and rebuilds the
same starting state (12 sample grievances). You have to choose a new administrator password again.

-------------------------------------------------------------------------------------
IF SOMETHING GOES WRONG
-------------------------------------------------------------------------------------
 "PHP was not found"                  PHP is not in the PATH (Part 1, step 1). Close and reopen the window.
 "PHP extensions are switched off"    Remove the ";" in php.ini for the names listed, then run Setup-Demo.bat again.
 "PostgreSQL (psql.exe) was not found" Install PostgreSQL 16 (Part 1, step 2).
 "Could not connect to PostgreSQL"    Wrong password, or the service is stopped. Start Windows "Services", find
                                      "postgresql-x64-16", click Start.
 Browser shows "This site can't be reached"   The black window of Start-Demo.bat was closed. Start it again.
 Port 8080 already in use             Close the other program, or restart the computer.
 No OTP arrives                       Normal in the demo: double-click Show-OTP.bat and read the code there.
 Fonts look plain                     Fonts are downloaded from Google. Without internet the portal still works
                                      with the browser's own fonts.

Real Google sign-in, real e-mail and SMS are NOT part of this demo kit.
The "test sign-in" shown on the sign-in page exists only in this demo setup. NEVER use these settings
or this kit on a real, live portal.
