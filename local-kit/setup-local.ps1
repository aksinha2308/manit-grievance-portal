# =====================================================================================
#  MANIT Grievance Portal: LOCAL COPY OF THE LIVE SETUP
#  Builds the two local databases (ERP + portal, hostel ERP), fills them with made-up data, applies the
#  local code fix, writes .env, loads demo authorities and grievances.
#  Run it by double-clicking Setup-Local.bat. It can be run again at any time: it starts from scratch.
#  This folder (local-kit) must sit INSIDE the extracted live portal folder (the one with the file "spark").
# =====================================================================================

param([switch]$Yes)

$ErrorActionPreference = 'Stop'

$DbHost      = '127.0.0.1'
$DbPort      = 5432
$SuperUser   = 'postgres'
$PortalDb    = 'grievance_local'
$HostelDb    = 'grievance_hostel_local'
$AppUser     = 'grievance_app'
$AppPassword = 'local_app_pw'
$ErpUser     = 'erp_ro'
$ErpPassword = 'local_erp_ro_pw'
$ServerPort  = 8080

function Say($t)  { Write-Host $t }
function Step($t) { Write-Host ''; Write-Host ('>> ' + $t) -ForegroundColor Cyan }
function Good($t) { Write-Host ('   OK  ' + $t) -ForegroundColor Green }
function Stop-Setup($t) {
    Write-Host ''
    Write-Host ('   PROBLEM: ' + $t) -ForegroundColor Red
    Write-Host '   Nothing more was done. Fix the problem above and run Setup-Local.bat again.' -ForegroundColor Red
    exit 1
}
function Run-Php {
    param([string[]]$Args2, [string]$Fail)
    & php @Args2
    if ($LASTEXITCODE -ne 0) { Stop-Setup $Fail }
}

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Here
Set-Location $Root

Say '======================================================'
Say ' MANIT Grievance Portal: local copy of the live setup'
Say '======================================================'
Say (' Portal folder: ' + $Root)

# ---------- 1. Check the computer -----------------------------------------------------------------
Step '1. Checking this computer'
if (-not (Test-Path (Join-Path $Root 'spark')))  { Stop-Setup 'This folder (local-kit) must be inside the portal folder, the one that contains the file "spark".' }
if (-not (Test-Path (Join-Path $Root 'vendor'))) { Stop-Setup 'The portal folder has no "vendor" folder. Use the full live zip.' }
foreach ($f in @('sql\00_roles_and_databases.sql', 'sql\01_erp_main_schema.sql', 'sql\02_erp_hostel_schema.sql', 'sql\03_erp_main_data.sql', 'sql\04_erp_hostel_data.sql', 'sql\05_portal_hostel_columns.sql', 'code-changes\app\Libraries\Erp\ErpSync.php')) {
    if (-not (Test-Path (Join-Path $Here $f))) { Stop-Setup ('local-kit\' + $f + ' is missing. Extract the local kit again.') }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { Stop-Setup 'PHP was not found. Install PHP 8.2 or newer and add it to the PATH (see README-LOCAL-SETUP.txt).' }
$phpVersion = (& php -r 'echo PHP_VERSION;').Trim()
if ([version]($phpVersion -replace '^(\d+\.\d+\.\d+).*', '$1') -lt [version]'8.2.0') { Stop-Setup ('PHP ' + $phpVersion + ' is too old. PHP 8.2 or newer is needed.') }
Good ('PHP ' + $phpVersion)

$modules = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
$missing = @()
foreach ($m in @('pgsql', 'intl', 'mbstring', 'zip', 'curl', 'openssl')) { if ($modules -notcontains $m) { $missing += $m } }
if (($modules -notcontains 'xml') -and ($modules -notcontains 'libxml')) { $missing += 'xml' }
if ($missing.Count -gt 0) { Stop-Setup ('PHP extensions are switched off: ' + ($missing -join ', ') + '. Remove the ";" in front of them in php.ini (README-LOCAL-SETUP.txt).') }
Good 'PHP extensions'

$psql = $null
$cmd = Get-Command psql -ErrorAction SilentlyContinue
if ($cmd) { $psql = $cmd.Source }
if (-not $psql) {
    $found = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue | Sort-Object { [int]($_.Directory.Parent.Name) } -Descending | Select-Object -First 1
    if ($found) { $psql = $found.FullName }
}
if (-not $psql) { Stop-Setup 'PostgreSQL (psql.exe) was not found. Install PostgreSQL first (README-LOCAL-SETUP.txt).' }
Good ('PostgreSQL client: ' + $psql)

# ---------- 1b. local-settings.txt --------------------------------------------------------------------
Step '2. Reading local-kit\local-settings.txt'
$settings = @{ ADMIN_EMAIL = ''; GOOGLE_CLIENT_ID = ''; GOOGLE_CLIENT_SECRET = '' }
$sf = Join-Path $Here 'local-settings.txt'
if (Test-Path $sf) {
    foreach ($line in (Get-Content $sf -Encoding UTF8)) {
        $l = $line.Trim()
        if ($l -eq '' -or $l.StartsWith('#')) { continue }
        $i = $l.IndexOf('=')
        if ($i -lt 1) { Stop-Setup ('local-settings.txt: this line has no "=": ' + $l) }
        $k = $l.Substring(0, $i).Trim().ToUpper()
        if ($settings.ContainsKey($k)) { $settings[$k] = $l.Substring($i + 1).Trim() }
    }
    Good 'local-settings.txt read'
} else {
    Say '   local-settings.txt not found: continuing without Google and without an administrator e-mail.'
}
$adminEmail = $settings['ADMIN_EMAIL'].ToLower()
if ($adminEmail -ne '' -and $adminEmail -notmatch '^[^@\s]+@manit\.ac\.in$') { Stop-Setup 'ADMIN_EMAIL must be a staff address ending in @manit.ac.in.' }
if (($settings['GOOGLE_CLIENT_ID'] -eq '') -ne ($settings['GOOGLE_CLIENT_SECRET'] -eq '')) { Stop-Setup 'Fill both GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET, or leave both empty.' }
if ($settings['GOOGLE_CLIENT_ID'] -match "['\s]" -or $settings['GOOGLE_CLIENT_SECRET'] -match "['\s]") { Stop-Setup 'The Google values must not contain spaces or quote marks.' }

# ---------- 2. PostgreSQL password --------------------------------------------------------------------
Step '3. Connecting to PostgreSQL'
Say ('   Enter the password of the PostgreSQL user "' + $SuperUser + '" (set when PostgreSQL was installed). Typing is hidden.')
$secure = Read-Host -AsSecureString '   Password'
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
$env:PGPASSWORD = $plain
$env:PGCLIENTENCODING = 'UTF8'

function Run-Psql {
    param([string]$Db, [string[]]$More, [string]$User = $SuperUser)
    $all = @('-h', $DbHost, '-p', "$DbPort", '-U', $User, '-d', $Db, '-v', 'ON_ERROR_STOP=1', '-q', '-X') + $More
    & $psql @all
    if ($LASTEXITCODE -ne 0) { Stop-Setup ('A database command failed (database "' + $Db + '"). Read the message above.') }
}

$ErrorActionPreference = 'Continue'
$test = & $psql -h $DbHost -p "$DbPort" -U $SuperUser -d postgres -X -t -A -c 'select 1' 2>$null
$connectCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if (($connectCode -ne 0) -or (($test | Out-String).Trim() -ne '1')) {
    Stop-Setup ('Could not connect to PostgreSQL on ' + $DbHost + ':' + $DbPort + ' as "' + $SuperUser + '". Is the PostgreSQL service running, and is the password right?')
}
Good 'Connected'

# ---------- 3. Confirm ---------------------------------------------------------------------------------
Say ''
Say ' This will DELETE and re-create these local databases (all data in them is lost):'
Say ('     ' + $PortalDb + ', ' + $HostelDb)
Say (' It replaces the file ' + (Join-Path $Root '.env') + ' (the old one is kept as .env.backup-<date>)')
Say ' and puts one changed file into the portal code (local-kit\code-changes; the original is kept as .orig).'
if (-not $Yes) {
    $answer = Read-Host ' Type YES (capital letters) to continue'
    if ($answer -cne 'YES') { Say ' Cancelled. Nothing was changed.'; exit 0 }
}

# ---------- 4. Databases --------------------------------------------------------------------------------
Step '4. Creating the local databases and the made-up ERP'
Run-Psql 'postgres' @('-f', (Join-Path $Here 'sql\00_roles_and_databases.sql'))
Run-Psql $PortalDb   @('-f', (Join-Path $Here 'sql\01_erp_main_schema.sql'))
Run-Psql $PortalDb   @('-f', (Join-Path $Here 'sql\03_erp_main_data.sql'))
Run-Psql $HostelDb   @('-f', (Join-Path $Here 'sql\02_erp_hostel_schema.sql'))
Run-Psql $HostelDb   @('-f', (Join-Path $Here 'sql\04_erp_hostel_data.sql'))
Good 'ERP schemas and data loaded'

# ---------- 5. Code change ------------------------------------------------------------------------------
Step '5. Applying the one local code change (ERP Sync fix)'
$target = Join-Path $Root 'app\Libraries\Erp\ErpSync.php'
if (-not (Test-Path ($target + '.orig'))) { Copy-Item $target ($target + '.orig') }
Copy-Item (Join-Path $Here 'code-changes\app\Libraries\Erp\ErpSync.php') $target -Force
Good 'app\Libraries\Erp\ErpSync.php replaced (original kept as ErpSync.php.orig)'

# ---------- 6. .env --------------------------------------------------------------------------------------
Step '6. Writing the .env settings file'
$envPath = Join-Path $Root '.env'
if (Test-Path $envPath) {
    $backup = $envPath + '.backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
    Copy-Item $envPath $backup
    Good ('Old .env kept as ' + (Split-Path -Leaf $backup))
}
$lines = @(
    'CI_ENVIRONMENT = development',
    "app.baseURL = 'http://localhost:$ServerPort/'",
    "app.indexPage = ''",
    'app.forceGlobalSecureRequests = false',
    'cookie.secure = false',
    "database.default.hostname = $DbHost",
    "database.default.database = $PortalDb",
    "database.default.username = $AppUser",
    "database.default.password = $AppPassword",
    'database.default.DBDriver = Postgre',
    "database.default.port = $DbPort",
    'database.default.charset = UTF8',
    'database.default.DBCollat =',
    'database.default.schema = grievance',
    'database.default.DBDebug = true',
    "database.default.options = '-c timezone=UTC'",
    "portal.displayTimezone = 'Asia/Kolkata'",
    'google.devLogin = true',
    "google.studentDomain = 'stu.manit.ac.in'",
    "google.staffDomain = 'manit.ac.in'",
    "otp.emailMode = 'log'",
    "otp.smsDriver = ''",
    "database.erp.hostname = $DbHost",
    "database.erp.database = $PortalDb",
    "database.erp.username = $ErpUser",
    "database.erp.password = $ErpPassword",
    'database.erp.DBDriver = Postgre',
    "database.erp.port = $DbPort",
    "database.erp_hostel.hostname = $DbHost",
    "database.erp_hostel.database = $HostelDb",
    "database.erp_hostel.username = $ErpUser",
    "database.erp_hostel.password = $ErpPassword",
    'database.erp_hostel.DBDriver = Postgre',
    "database.erp_hostel.port = $DbPort"
)
if ($settings['GOOGLE_CLIENT_ID'] -ne '') {
    $lines += "google.clientId = '" + $settings['GOOGLE_CLIENT_ID'] + "'"
    $lines += "google.clientSecret = '" + $settings['GOOGLE_CLIENT_SECRET'] + "'"
}
[IO.File]::WriteAllText($envPath, (($lines -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))
Good '.env written'

# ---------- 7. Portal tables -------------------------------------------------------------------------------
Step '7. Creating the portal tables (migrations, starting data, hostel and room columns)'
Run-Php @('spark', 'migrate') 'The database migration failed. Read the message above.'
Run-Php @('spark', 'db:seed', 'InitialDataSeeder') 'Loading the starting data failed. Read the message above.'
Run-Psql $PortalDb @('-f', (Join-Path $Here 'sql\05_portal_hostel_columns.sql'))
Good 'Portal tables ready'

# ---------- 8. Accounts and demo data -----------------------------------------------------------------------
Step '8. Creating the administrator account, switching people to the ERP, loading demo data'
Run-Php @('spark', 'portal:create-admin', '--password', 'LocalAdmin#123') 'Creating the administrator failed.'
if ($adminEmail -ne '') {
    Run-Php @('spark', 'portal:admin-email', $adminEmail) 'Setting the administrator e-mail failed.'
}
Run-Php @('spark', 'portal:person-source', 'erp') 'Switching people to the ERP failed.'
Run-Php @('spark', 'portal:demo-data') 'Loading the demo data failed. Read the message above.'
Good 'Demo data loaded (see demo\DEMO-LOGINS.txt)'

$env:PGPASSWORD = ''
Say ''
Say '======================================================'
Say ' LOCAL COPY IS READY'
Say '======================================================'
Say ' 1. Double-click local-kit\Start-Local.bat and keep its window open.'
Say ' 2. Sign in at http://localhost:8080 with "Test sign-in" and an e-mail from demo\DEMO-LOGINS.txt.'
if ($adminEmail -ne '' -and $settings['GOOGLE_CLIENT_ID'] -ne '') {
    Say (' 3. Administrator: open http://localhost:8080/admin-login and sign in with Google as ' + $adminEmail + '.')
} else {
    Say ' 3. Administrator screens need ADMIN_EMAIL and the Google client in local-settings.txt (then run Setup-Local.bat again).'
}
Say ' 4. One-time codes appear when you double-click local-kit\Show-OTP.bat (no mail or SMS is sent).'
