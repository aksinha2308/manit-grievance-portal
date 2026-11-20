# =====================================================================================
#  MANIT Grievance Portal: DEMO SETUP
#  Builds three demo databases (portal, ERP, hostel ERP), fills them with made-up data,
#  writes the .env file and prepares the portal for the demo.
#  Run it by double-clicking Setup-Demo.bat. It can be run again at any time: it starts from scratch.
# =====================================================================================

param([switch]$Yes)   # -Yes = do not ask for confirmation (for the person who set it up before)

$ErrorActionPreference = 'Stop'

# ---------- Settings (change only if you know why) --------------------------------------------------
$DbHost        = '127.0.0.1'
$DbPort        = 5432
$SuperUser     = 'postgres'          # PostgreSQL administrator account (its password is asked below)
$PortalDb      = 'demo_portal'       # the portal's own database
$ErpDb         = 'demo_erp'          # demo ERP: students and staff
$HostelDb      = 'demo_hostel'       # demo ERP: hostel allotment
$AppUser       = 'demo_app'          # database user of the portal
$AppPassword   = 'demo_app_pw'
$ErpUser       = 'demo_ro'           # read-only database user for the ERP (created by the ERP SQL files)
$ErpPassword   = 'demo_ro_dev'
$AdminPassword = 'Demo@Admin2026'    # first password of the portal administrator (it must be changed at first login)
$ServerPort    = 8080
# -----------------------------------------------------------------------------------------------------

function Say($text)  { Write-Host $text }
function Step($text) { Write-Host ''; Write-Host ('>> ' + $text) -ForegroundColor Cyan }
function Good($text) { Write-Host ('   OK  ' + $text) -ForegroundColor Green }
function Stop-Setup($text) {
    Write-Host ''
    Write-Host ('   PROBLEM: ' + $text) -ForegroundColor Red
    Write-Host '   Nothing more was done. Fix the problem above and run Setup-Demo.bat again.' -ForegroundColor Red
    exit 1
}

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Here          # the portal folder (this script lives in its "demo" sub-folder)
Set-Location $Root

Say '======================================================'
Say ' MANIT Grievance Portal: demo setup'
Say '======================================================'
Say (' Portal folder: ' + $Root)

# ---------- 1. Check the computer ------------------------------------------------------------------
Step '1. Checking this computer'

if (-not (Test-Path (Join-Path $Root 'spark'))) { Stop-Setup 'This script must stay inside the "demo" folder of the portal (the folder that contains the file "spark").' }
if (-not (Test-Path (Join-Path $Root 'vendor'))) { Stop-Setup 'The portal folder has no "vendor" folder. Use the full portal zip.' }
if (-not (Test-Path (Join-Path $Here 'sql\demo_erp_main.sql'))) { Stop-Setup 'demo\sql\demo_erp_main.sql is missing. Extract the demo kit zip again.' }
if (-not (Test-Path (Join-Path $Root 'app\Commands\DemoData.php'))) { Stop-Setup 'app\Commands\DemoData.php is missing. Extract the demo kit zip again into C:\ and choose Replace.' }

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { Stop-Setup 'PHP was not found. Install PHP 8.2 or newer and add it to the PATH (see README-DEMO-SETUP.txt, step 1).' }
$phpVersion = (& php -r 'echo PHP_VERSION;').Trim()
#if ([version]($phpVersion -replace '^(\d+\.\d+\.\d+).*', '$1') -lt [version]'8.2.0') { Stop-Setup ('PHP ' + $phpVersion + ' is too old. PHP 8.2 or newer is needed.') }
Good ('PHP ' + $phpVersion)

$modules = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
$missing = @()
foreach ($m in @('pgsql', 'intl', 'mbstring', 'zip', 'curl')) { if ($modules -notcontains $m) { $missing += $m } }
if (($modules -notcontains 'xml') -and ($modules -notcontains 'libxml')) { $missing += 'xml' }
if ($missing.Count -gt 0) { Stop-Setup ('PHP extensions are switched off: ' + ($missing -join ', ') + '. Remove the ";" in front of them in php.ini (README-DEMO-SETUP.txt, step 1).') }
Good 'PHP extensions'

$psql = $null
$cmd = Get-Command psql -ErrorAction SilentlyContinue
if ($cmd) { $psql = $cmd.Source }
if (-not $psql) {
    $found = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue | Sort-Object { [int]($_.Directory.Parent.Name) } -Descending | Select-Object -First 1
    if ($found) { $psql = $found.FullName }
}
if (-not $psql) { Stop-Setup 'PostgreSQL (psql.exe) was not found. Install PostgreSQL 16 first (README-DEMO-SETUP.txt, step 1).' }
Good ('PostgreSQL client: ' + $psql)

# ---------- 2. Ask for the PostgreSQL password -------------------------------------------------------
Step '2. Connecting to PostgreSQL'
Say ('   Enter the password of the PostgreSQL user "' + $SuperUser + '" (set when PostgreSQL was installed). Typing is hidden.')
$secure = Read-Host -AsSecureString '   Password'
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
$env:PGPASSWORD = $plain
$env:PGCLIENTENCODING = 'UTF8'

function Run-Psql {
    param([string]$Db, [string[]]$More)
    $all = @('-h', $DbHost, '-p', "$DbPort", '-U', $SuperUser, '-d', $Db, '-v', 'ON_ERROR_STOP=1', '-q', '-X') + $More
    & $psql @all
    if ($LASTEXITCODE -ne 0) { Stop-Setup ('A database command failed (database "' + $Db + '"). Read the message above.') }
}

$ErrorActionPreference = 'Continue'      # psql prints to stderr when the password is wrong; we handle that ourselves
$test = & $psql -h $DbHost -p "$DbPort" -U $SuperUser -d postgres -X -t -A -c 'select 1' 2>$null
$connectCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if (($connectCode -ne 0) -or (($test | Out-String).Trim() -ne '1')) {
    Stop-Setup ('Could not connect to PostgreSQL on ' + $DbHost + ':' + $DbPort + ' as "' + $SuperUser + '". Is the PostgreSQL service running, and is the password right?')
}
Good 'Connected'

# ---------- 3. Confirm ---------------------------------------------------------------------------------
Say ''
Say ' This will DELETE and re-create these databases (all data in them is lost):'
Say ('     ' + $PortalDb + ', ' + $ErpDb + ', ' + $HostelDb)
Say (' and replace the file ' + (Join-Path $Root '.env') + ' (the old one is kept as .env.backup-<date>).')
if (-not $Yes) {
    $answer = Read-Host ' Type YES (capital letters) to continue'
    if ($answer -cne 'YES') { Say ' Cancelled. Nothing was changed.'; exit 0 }
}

# ---------- 4. Create the databases ---------------------------------------------------------------------
Step '3. Creating the demo databases'
$roleSql = "DO `$`$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$AppUser') THEN CREATE ROLE $AppUser LOGIN PASSWORD '$AppPassword'; ELSE ALTER ROLE $AppUser LOGIN PASSWORD '$AppPassword'; END IF; END `$`$;"
Run-Psql 'postgres' @('-c', $roleSql)
foreach ($db in @($PortalDb, $ErpDb, $HostelDb)) {
    Run-Psql 'postgres' @('-c', "DROP DATABASE IF EXISTS $db WITH (FORCE)")
}
Run-Psql 'postgres' @('-c', "CREATE DATABASE $PortalDb OWNER $AppUser ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0")
Run-Psql 'postgres' @('-c', "CREATE DATABASE $ErpDb ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0")
Run-Psql 'postgres' @('-c', "CREATE DATABASE $HostelDb ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0")
Good 'Databases created'

Step '4. Loading the demo ERP data (students, staff, hostel allotments)'
Run-Psql $ErpDb @('-f', (Join-Path $Here 'sql\demo_erp_main.sql'))
Run-Psql $HostelDb @('-f', (Join-Path $Here 'sql\demo_erp_hostel.sql'))
Good 'Demo ERP loaded'

# ---------- 5. Write .env ----------------------------------------------------------------------------------
Step '5. Writing the .env settings file'
$envPath = Join-Path $Root '.env'
if (Test-Path $envPath) {
    $backup = $envPath + '.backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
    Copy-Item $envPath $backup
    Good ('Old .env saved as ' + (Split-Path -Leaf $backup))
}
$envText = @"
# DEMO settings written by demo\setup-demo.ps1. Only for the demo computer.
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost:$ServerPort/'
app.indexPage = ''
app.forceGlobalSecureRequests = false
portal.displayTimezone = 'Asia/Kolkata'

database.default.hostname = $DbHost
database.default.database = $PortalDb
database.default.username = $AppUser
database.default.password = $AppPassword
database.default.DBDriver = Postgre
database.default.port = $DbPort
database.default.charset = UTF8
database.default.DBCollat =
database.default.options = '-c timezone=UTC'

# Test sign-in (type an e-mail instead of using Google). DEMO ONLY.
google.devLogin = true

# OTP codes are written to writable\logs\otp-<date>.log instead of being sent
otp.emailMode = 'log'
otp.smsDriver = 'log'

database.erp.hostname = $DbHost
database.erp.database = $ErpDb
database.erp.username = $ErpUser
database.erp.password = $ErpPassword
database.erp.DBDriver = Postgre
database.erp.port = $DbPort

database.erp_hostel.hostname = $DbHost
database.erp_hostel.database = $HostelDb
database.erp_hostel.username = $ErpUser
database.erp_hostel.password = $ErpPassword
database.erp_hostel.DBDriver = Postgre
database.erp_hostel.port = $DbPort
"@
[System.IO.File]::WriteAllText($envPath, $envText, (New-Object System.Text.UTF8Encoding($false)))
Good '.env written'

foreach ($d in @('cache', 'logs', 'session', 'uploads', 'debugbar')) {
    $p = Join-Path $Root ('writable\' + $d)
    if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p | Out-Null }
}

# ---------- 6. Build the portal ------------------------------------------------------------------------------
Step '6. Creating the portal tables'
& php spark migrate --all
if ($LASTEXITCODE -ne 0) { Stop-Setup 'The database tables could not be created. Read the message above.' }
& php spark db:seed InitialDataSeeder
if ($LASTEXITCODE -ne 0) { Stop-Setup 'The starting data could not be loaded. Read the message above.' }
& php spark portal:create-admin --username admin --name 'Portal Administrator' --password $AdminPassword
if ($LASTEXITCODE -ne 0) { Stop-Setup 'The administrator account could not be created. Read the message above.' }
Good 'Portal tables, starting data and administrator account created'

Step '7. Checking the ERP connection'
& php spark portal:erp-test
if ($LASTEXITCODE -ne 0) { Stop-Setup 'The portal cannot read the demo ERP databases. Read the message above.' }

Step '8. Loading the demo data (authorities, departments, hostels, 12 sample grievances)'
& php spark portal:demo-data
if ($LASTEXITCODE -ne 0) { Stop-Setup 'The demo data could not be loaded. Read the message above.' }

$count = & $psql -h $DbHost -p "$DbPort" -U $SuperUser -d $PortalDb -X -t -A -c 'select count(*) from grievances'
Good ('Sample grievances in the portal: ' + ($count | Out-String).Trim() + ' (expected 12)')

$env:PGPASSWORD = ''

Say ''
Say '======================================================'
Say ' DEMO IS READY'
Say '======================================================'
Say ' 1. Double-click Start-Demo.bat to start the portal and open the browser.'
Say '    (Keep the black window open while presenting.)'
Say (' 2. Administrator login: username  admin   password  ' + $AdminPassword)
Say '    The portal asks for a NEW password at the first login. Choose one and write it down.'
Say ' 3. All other demo logins are listed in demo\DEMO-LOGINS.txt (double-click to open).'
Say ' 4. The demo checklist tells you what to show and in which order.'
Say ''
