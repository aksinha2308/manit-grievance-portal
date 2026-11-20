# Shared start-up checks for the manual web tests. Each test script sources this file right after setting B (the
# portal address) and PSQL. Instead of hanging, it stops with a plain explanation when something is not ready.
# It also makes psql and curl behave on Windows (Git Bash):
#   - psql never waits for a password (a hidden password prompt looks like a frozen script),
#   - psql output has its Windows line endings (\r) removed,
#   - curl gives up instead of waiting forever, and uses whichever of IPv4 / IPv6 the portal answers on.

# If the script was started with "bash -x", pause tracing during these checks (trace lines would be mixed into the
# answers the checks read); it is switched back on at the end. For a clean trace use TRACE=1 instead (see the end).
case $- in *x*) PREFLIGHT_XTRACE=1; set +x ;; esac

fail() {
  echo
  echo "CANNOT START THE TESTS: $1"
  shift
  for line in "$@"; do echo "  $line"; done
  echo
  exit 2
}

[ -z "$PREFLIGHT_ARG" ] && fail "the temporary admin password was not given." \
  "Run the script like this:  bash $0 '<temporary admin password>'"
[ -d writable/session ] || fail "run the script from the portal's project folder." \
  "For example:  cd /c/projects/grievance-portal   then run the script again."

for c in curl psql php awk sed md5sum mktemp; do
  command -v "$c" >/dev/null 2>&1 || fail "the command '$c' was not found." \
    "psql: add PostgreSQL's bin folder to the PATH, e.g.  export PATH=\"\$PATH:/c/Program Files/PostgreSQL/16/bin\"" \
    "php: make sure C:\\xampp\\php is on the Windows Path. The other commands come with Git Bash."
done

# Scripts that check Excel templates also need Python 3 with the openpyxl library
if grep -q 'python3 -' "$0"; then
  py=$(timeout 30 python3 -c 'import openpyxl; print("ok")' </dev/null 2>&1 | tr -d '\r')
  [ "$py" = "ok" ] || fail "this script needs Python 3 with the openpyxl library, and it is not available:" \
    "${py:-python3 did not answer}" \
    "Install Python 3 from python.org (tick 'Add python.exe to PATH'), then in Command Prompt:  pip install openpyxl" \
    "If Windows opens the Microsoft Store when you type python3, turn off the 'python3.exe' App execution alias" \
    "(Settings → Apps → Advanced app settings → App execution aliases)."
fi

# On Windows, curl and php are native programs and cannot open a Git Bash path such as /tmp/tmp.XXXX (curl would silently
# send nothing for "-F file=@/tmp/..."). Use the same folder under its Windows name (C:/Users/.../Temp/tmp.XXXX) instead.
if [ -n "${T:-}" ] && command -v cygpath >/dev/null 2>&1; then T=$(cygpath -m "$T"); fi

export PGCONNECT_TIMEOUT=10
# A query that waits on a lock or runs too long fails after a while instead of waiting forever
export PGOPTIONS="-c lock_timeout=20000 -c statement_timeout=60000"
# From here on every "psql" in the script never prompts, never reads the terminal, and has \r stripped from its output
# The Windows psql stops reading options at the database name, so "psql ... grievance_test -c 'SELECT 1'" would ignore
# the -c and run nothing. This wrapper moves the database name to the end, whatever order the script wrote it in.
psql() {
  local opts=() pos=()
  while [ $# -gt 0 ]; do
    case "$1" in
      -[hUcdfpvoFPRTL]) opts+=("$1" "$2"); shift 2 ;;
      -*) opts+=("$1"); shift ;;
      *) pos+=("$1"); shift ;;
    esac
  done
  command psql -w "${opts[@]}" "${pos[@]}" </dev/null | tr -d '\r'
}
# Windows programs started from Git Bash can wait on the terminal's input; give php an empty input instead
php() { command php "$@" </dev/null; }

DB_UNDER_TEST=$(echo "$PSQL" | awk '{print $NF}')
echo "Pre-flight: checking database '$DB_UNDER_TEST'..."
out=$(command psql -w -q -t -A -h 127.0.0.1 -U grievance -c "SELECT 1" "$DB_UNDER_TEST" </dev/null 2>&1 | tr -d '\r')
if [ "$out" != "1" ]; then
  fail "cannot connect to database '$DB_UNDER_TEST' as user 'grievance'. PostgreSQL said:" \
    "$out" \
    "Set the grievance user's password first:  export PGPASSWORD='<password>'" \
    "and, if the database is not grievance_db:  export DBNAME=grievance_test" \
    "Also check that PostgreSQL is running (services.msc → postgresql-x64-16)."
fi

# The portal must be using the same database as the tests
ENV_DB=$(grep -E '^[[:space:]]*database\.default\.database[[:space:]]*=' .env 2>/dev/null | tail -1 | sed -E "s/^[^=]*=[[:space:]]*//; s/[\"'[:space:]]//g" | tr -d '\r')
if [ -n "$ENV_DB" ] && [ "$ENV_DB" != "$DB_UNDER_TEST" ]; then
  fail "the portal (.env) uses database '$ENV_DB' but the tests are checking '$DB_UNDER_TEST'." \
    "Either set  export DBNAME=$ENV_DB  or change database.default.database in .env and restart the server."
fi

# A fresh database: no grievances, nobody on the master list, admin still on the temporary password
fresh=$(psql -q -t -A -h 127.0.0.1 -U grievance -c \
  "SELECT (SELECT count(*) FROM grievances) || ',' || (SELECT count(*) FROM master_people) || ',' ||
          COALESCE((SELECT must_change_password::text FROM users WHERE is_admin), 'none')" "$DB_UNDER_TEST" 2>&1)
case "$fresh" in
  0,0,true) ;;
  *,*,none) fail "there is no admin account in '$DB_UNDER_TEST'." "Run:  php spark portal:create-admin   and use the password it prints." ;;
  0,0,false) fail "the admin in '$DB_UNDER_TEST' has already changed the temporary password, so this is not a fresh database." \
    "Re-create the test database (setup guide, section 13, steps 2 to 5) and run the script with the new temporary password." ;;
  *) fail "database '$DB_UNDER_TEST' is not fresh (it already has grievances or people: $fresh)." \
    "These tests need a newly created database each time. Re-create it (setup guide, section 13, steps 2 to 5)." ;;
esac

# The portal must answer; pick IPv4 or IPv6, whichever it listens on
echo "Pre-flight: checking the portal at $B ..."
CURL_IP=
code=000
for ip in -4 -6 ""; do
  code=$(command curl $ip -s -o /dev/null -w '%{http_code}' --connect-timeout 3 --max-time 15 "$B/" </dev/null 2>/dev/null)
  if [ "$code" = "200" ]; then CURL_IP=$ip; break; fi
done
[ "$code" = "200" ] || fail "the portal does not answer at $B (HTTP status $code)." \
  "Start it in a separate Command Prompt window, in the project folder:  php spark serve --port 8080" \
  "and leave that window open while the tests run."

# From here on every "curl" gives up after 60 seconds instead of hanging
curl() { command curl $CURL_IP --connect-timeout 5 --max-time 60 "$@" </dev/null; }

# Since the Google sign-in feature, the scripts sign people in through the development-only test login
dev=$(curl -s -o /dev/null -w '%{http_code}' "$B/auth/dev")
[ "$dev" = "200" ] || fail "the test sign-in page ($B/auth/dev) is switched off (HTTP status $dev)." \
  "Add these two lines to the .env file of the portal, then restart  php spark serve --port 8080 :" \
  "  CI_ENVIRONMENT = development" \
  "  google.devLogin = true" \
  "(Only on your own computer. Never on the live portal.)"
grep -qE "^[[:space:]]*otp\.emailMode[[:space:]]*=[[:space:]]*'?smtp" .env 2>/dev/null && fail "otp.emailMode is 'smtp' in .env, so OTP e-mails would really be sent." \
  "For the tests set  otp.emailMode = 'log'  (or remove the line) and restart the server."

# devlogin <jar> <e-mail>  -> signs in as that institute e-mail (fresh cookie jar); prints "status redirect"
devlogin() { local jar=$T/$1.jar; rm -f "$jar"
  local tok=$(curl -s -c "$jar" -b "$jar" "$B/auth/dev" | grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$jar" -b "$jar" --data-urlencode "csrf_test_name=$tok" --data-urlencode "email=$2" "$B/auth/dev" | sed "s/^303/302/"; }
# otp_code [e-mail or mobile] -> the newest OTP written to writable/logs/otp-*.log (optionally for one address)
otp_code() { local f=$(ls -t writable/logs/otp-*.log 2>/dev/null | head -1); [ -n "$f" ] || return 0
  grep -F -- " to ${1:-}" "$f" | tail -1 | sed -n 's/.*: \([0-9]\{6\}\) is your OTP.*/\1/p'; }
# make every OTP request possible at once (no resend wait) for the rest of the script
otp_no_wait() { psql -q -t -A -h 127.0.0.1 -U grievance -c "UPDATE settings SET value='0' WHERE key='otp_resend_seconds'; UPDATE settings SET value='1000' WHERE key='otp_max_per_hour'" "$DB_UNDER_TEST" >/dev/null; }

# as_user <jar> <username>  -> signs in as that authority account (gives it <username>@manit.ac.in if it has no e-mail)
as_user() { local e=$($PSQL -c "select coalesce(email,'') from users where username='$2'")
  if [ -z "$e" ]; then e="$2@manit.ac.in"; $PSQL -c "update users set email='$e' where username='$2'" >/dev/null; fi
  devlogin $1 "$e" >/dev/null; }
# as_person <jar> <id code>  -> signs in as that master-list person (gives them an institute e-mail if missing)
as_person() { local e=$($PSQL -c "select coalesce(email,'') from master_people where lower(id_code)=lower('$2')")
  if [ -z "$e" ]; then
    e=$($PSQL -c "select lower(regexp_replace(id_code,'[^A-Za-z0-9]','','g')) || '@' || case when role='student' then 'stu.manit.ac.in' else 'manit.ac.in' end from master_people where lower(id_code)=lower('$2')")
    $PSQL -c "update master_people set email='$e' where lower(id_code)=lower('$2')" >/dev/null
  fi
  echo "$e" > $T/$1.email; otp_no_wait; devlogin $1 "$e"; }
# submit_as <jar> fields...  -> sends an OTP, then submits the form with it; prints the redirect location
submit_as() { local jar=$1; shift
  local tok=$(curl -s -c $T/$jar.jar -b $T/$jar.jar $B/submit | grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -c $T/$jar.jar -b $T/$jar.jar --data-urlencode "csrf_test_name=$tok" $B/submit/otp
  tok=$(curl -s -c $T/$jar.jar -b $T/$jar.jar $B/submit | grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  local args=(-F "csrf_test_name=$tok" --form-string "otp=$(otp_code "$(cat $T/$jar.email)")")
  for kv in "$@"; do if [[ "$kv" == attachments* ]]; then args+=(-F "$kv"); else args+=(--form-string "$kv"); fi; done
  curl -s -o /dev/null -w "%{redirect_url}" -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" $B/submit; }

echo "Pre-flight OK: database '$DB_UNDER_TEST', portal at $B. Running the tests (a few minutes)..."

# TRACE=1 writes every command the script runs to tests/manual/trace.txt (the screen stays readable).
# If a run seems stuck, look at the last lines from a second Git Bash window:  tail -n 8 tests/manual/trace.txt
if [ "${TRACE:-}" = "1" ]; then
  exec 7>"$(dirname "$0")/trace.txt"
  BASH_XTRACEFD=7
  PS4='+ line ${LINENO}: '
  echo "Tracing to $(dirname "$0")/trace.txt"
  set -x
elif [ "${PREFLIGHT_XTRACE:-}" = "1" ]; then
  set -x
fi
echo
