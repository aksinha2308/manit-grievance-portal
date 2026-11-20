#!/bin/bash
# End-to-end checks for module 1 (auth, roles, lockout, idle timeout, CSRF, audit log)
B=http://localhost:8080
T=$(mktemp -d)
PSQL="psql -q -t -A -h 127.0.0.1 -U grievance ${DBNAME:-grievance_db}"
export PGPASSWORD=${PGPASSWORD:-grievance_dev}
PREFLIGHT_ARG="$1"; source "$(dirname "$0")/_preflight.sh"
pass=0; fail=0
check() { # name expected actual
  if [ "$2" == "$3" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi
}
token() { grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code()  { curl -s -o /dev/null -w "%{http_code}" "$@"; }
redir() { curl -s -o /dev/null -w "%{http_code} %{redirect_url}" "$@" | sed "s/^303/302/"; }

new_jar() { rm -f $T/$1.jar; }
login() { # jar user pass -> prints status+redirect (always a fresh cookie jar)
  local jar=$T/$1.jar
  rm -f $jar
  local tok=$(curl -s -c $jar -b $jar $B/login | token)
  curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c $jar -b $jar -d "csrf_test_name=$tok&username=$2&password=$3" $B/login | sed "s/^303/302/"
}
page() { curl -s -c $T/$1.jar -b $T/$1.jar "${@:2}"; }

echo "--- public & unauthenticated"
check "public home is 200" 200 "$(code $B/)"
check "unauthenticated /admin redirects to login" "302 $B/login" "$(redir $B/admin)"
check "unauthenticated /authority redirects to login" "302 $B/login" "$(redir $B/authority)"
check "POST /login without CSRF token is rejected" 403 "$(code -d 'username=admin&password=x' $B/login)"

echo "--- wrong credentials: generic message, no user enumeration"
new_jar a
R1=$(login a admin wrongpass)
page a $B/login | grep -q "Invalid username or password" && M1=ok || M1=missing
check "wrong password redirects back to login" "302 $B/login" "$R1"
check "wrong password shows generic error" ok "$M1"
new_jar a
login a nosuchuser whatever >/dev/null
page a $B/login | grep -q "Invalid username or password" && M2=ok || M2=missing
check "unknown user shows the same generic error" ok "$M2"

echo "--- admin first login forces password change"
$PSQL -c "UPDATE users SET failed_attempts=0, locked_until=NULL WHERE username='admin'"
TEMP="$1"
new_jar adm
check "admin temp-password login goes to change-password" "302 $B/account/password" "$(login adm admin "$TEMP")"
check "admin cannot reach /admin before changing password" "302 $B/account/password" "$(redir -c $T/adm.jar -b $T/adm.jar $B/admin)"
tok=$(page adm $B/account/password | token)
page adm -o /dev/null -d "csrf_test_name=$tok&current_password=WRONG&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password
page adm $B/account/password | grep -q "current password is not correct" && M=ok || M=missing
check "wrong current password is rejected" ok "$M"
tok=$(page adm $B/account/password | token)
page adm -o /dev/null -d "csrf_test_name=$tok&current_password=$TEMP&new_password=short&confirm_password=short" $B/account/password
page adm $B/account/password | grep -q "at least 8 characters" && M=ok || M=missing
check "too-short new password is rejected" ok "$M"
tok=$(page adm $B/account/password | token)
check "valid password change succeeds" "302 $B/admin" "$(redir -c $T/adm.jar -b $T/adm.jar -d "csrf_test_name=$tok&current_password=$TEMP&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password)"
check "admin reaches /admin after change" 200 "$(code -c $T/adm.jar -b $T/adm.jar $B/admin)"
check "admin is forbidden from /authority" 403 "$(code -c $T/adm.jar -b $T/adm.jar $B/authority)"
check "must_change_password cleared in DB" f "$($PSQL -c "SELECT must_change_password FROM users WHERE username='admin'")"
check "old temporary password no longer works" "302 $B/login" "$(login adm2 admin "$TEMP")"

echo "--- fixtures: a department, an authority account (Google e-mail, no password) holding a post, a student"
# (works whether or not the older guide's hod.cs fixture was already created)
DEP=$($PSQL -c "SELECT id FROM departments WHERE lower(name)='computer science'")
[ -z "$DEP" ] && DEP=$($PSQL -c "INSERT INTO departments(name,for_student,for_faculty) VALUES ('Computer Science',true,true) RETURNING id" | head -1)
$PSQL -c "UPDATE departments SET for_student=true, for_faculty=true, active=true WHERE id=$DEP" >/dev/null
$PSQL -c "INSERT INTO users(username,full_name,email,password_hash,must_change_password) SELECT 'hod.cs','Dr. HOD CS','hod.cs@manit.ac.in','x',false WHERE NOT EXISTS (SELECT 1 FROM users WHERE lower(username)='hod.cs')" >/dev/null
$PSQL -c "UPDATE users SET email='hod.cs@manit.ac.in', must_change_password=false, active=true WHERE lower(username)='hod.cs'" >/dev/null
$PSQL -c "INSERT INTO post_holders(post_id,scope_id,user_id) SELECT d.id, $DEP, u.id FROM posts d, users u WHERE d.name='Head of Department' AND u.username='hod.cs' AND NOT EXISTS (SELECT 1 FROM post_holders a WHERE a.post_id=d.id AND a.scope_id=$DEP AND a.active)" >/dev/null
$PSQL -c "INSERT INTO master_people(role,id_code,name,department_id,contact,email) VALUES ('student','A001','Asha Student',$DEP,'9876543210','asha@stu.manit.ac.in')" >/dev/null
check "fixtures created" "1 1" "$($PSQL -c "SELECT count(*) FROM users WHERE username='hod.cs'") $($PSQL -c "SELECT count(*) FROM master_people")"

echo "--- sign-in page"
R=$(page x $B/login)
echo "$R" | grep -q 'id="google-signin"\|id="google-missing"' && M=ok || M=missing
check "sign-in page offers Google (or says it is not set up)" ok "$M"
echo "$R" | grep -q 'id="admin-login"' && M=ok || M=missing
check "sign-in page keeps the admin password login" ok "$M"
check "Google start without set-up goes back to login (or to Google)" 302 "$(redir $B/auth/google | cut -c1-3)"
check "Google callback with a forged state is refused" "302 $B/login" "$(redir -c $T/f.jar -b $T/f.jar "$B/auth/google/callback?state=forged&code=x")"
page f $B/login | grep -q "expired or was opened in another browser" && M=ok || M=missing
check "forged state explains the problem" ok "$M"

echo "--- authority (Google e-mail)"
check "authority sign-in goes to /authority" "302 $B/authority" "$(devlogin hod hod.cs@manit.ac.in)"
check "authority dashboard is 200" 200 "$(code -c $T/hod.jar -b $T/hod.jar $B/authority)"
page hod $B/authority | grep -q "Head of Department (Computer Science)" && M=ok || M=missing
check "authority dashboard lists the post held" ok "$M"
check "authority is forbidden from /admin" 403 "$(code -c $T/hod.jar -b $T/hod.jar $B/admin)"
check "authority is not on the master list: no submitting" 403 "$(code -c $T/hod.jar -b $T/hod.jar $B/submit)"
check "authority has no password page" 403 "$(code -c $T/hod.jar -b $T/hod.jar $B/account/password)"
check "e-mail case does not matter" "302 $B/authority" "$(devlogin hod2 HOD.CS@MANIT.AC.IN)"
check "logout via GET is not allowed" 404 "$(code -c $T/hod.jar -b $T/hod.jar $B/logout)"
tok=$(page hod $B/authority | token)
check "logout via POST works" "302 $B/login" "$(redir -c $T/hod.jar -b $T/hod.jar -d "csrf_test_name=$tok" $B/logout)"
page hod $B/login | grep -q "You have been logged out" && M=ok || M=missing
check "logout shows confirmation message" ok "$M"
check "after logout /authority redirects to login" "302 $B/login" "$(redir -c $T/hod.jar -b $T/hod.jar $B/authority)"
$PSQL -c "UPDATE users SET password_hash=(SELECT password_hash FROM users WHERE username='admin') WHERE username='hod.cs'" >/dev/null
check "an authority cannot use a password" "302 $B/login" "$(login pw hod.cs NewAdminPass1)"
page pw $B/login | grep -q "Invalid username or password" && M=ok || M=missing
check "authority password login shows the generic error" ok "$M"

echo "--- students and outsiders"
check "student sign-in goes to My grievances" "302 $B/my" "$(devlogin stu asha@stu.manit.ac.in)"
check "My grievances is 200" 200 "$(code -c $T/stu.jar -b $T/stu.jar $B/my)"
check "student is forbidden from /authority" 403 "$(code -c $T/stu.jar -b $T/stu.jar $B/authority)"
check "student is forbidden from /admin" 403 "$(code -c $T/stu.jar -b $T/stu.jar $B/admin)"
check "unknown institute e-mail refused" "302 $B/login" "$(devlogin u nobody@stu.manit.ac.in)"
page u $B/login | grep -q "is not registered in the grievance portal" && M=ok || M=missing
check "unknown e-mail explains why" ok "$M"
check "private Gmail refused" "302 $B/login" "$(devlogin g asha@gmail.com)"
page g $B/login | grep -q "institute Google account" && M=ok || M=missing
check "private Gmail explains why" ok "$M"
check "a student address cannot open an authority account" "302 $B/login" "$(devlogin s hod.cs@stu.manit.ac.in)"
check "unauthenticated /my redirects to login" "302 $B/login" "$(redir $B/my)"
check "unauthenticated /submit redirects to login" "302 $B/login" "$(redir $B/submit)"
check "test sign-in needs the CSRF token" 403 "$(code -d 'email=asha@stu.manit.ac.in' $B/auth/dev)"

echo "--- a staff member who is also an authority"
$PSQL -c "INSERT INTO master_people(role,id_code,name,department_id,email) VALUES ('faculty','F001','Dr. HOD CS',$DEP,'hod.cs@manit.ac.in')" >/dev/null
check "one Google sign-in, lands on the authority dashboard" "302 $B/authority" "$(devlogin both hod.cs@manit.ac.in)"
check "can open the authority dashboard" 200 "$(code -c $T/both.jar -b $T/both.jar $B/authority)"
check "can open the submission form" 200 "$(code -c $T/both.jar -b $T/both.jar $B/submit)"
R=$(page both $B/my)
echo "$R" | grep -q 'href="'"$B"'/authority"' && echo "$R" | grep -q 'href="'"$B"'/my"' && M=ok || M=missing
check "menu offers both My grievances and the dashboard" ok "$M"
$PSQL -c "DELETE FROM master_people WHERE id_code='F001'" >/dev/null

echo "--- deactivation takes effect immediately"
devlogin hod hod.cs@manit.ac.in >/dev/null
check "signed-in authority can load dashboard" 200 "$(code -c $T/hod.jar -b $T/hod.jar $B/authority)"
$PSQL -c "UPDATE users SET active=false WHERE username='hod.cs'"
check "deactivated user is bounced to login" "302 $B/login" "$(redir -c $T/hod.jar -b $T/hod.jar $B/authority)"
check "deactivated user cannot sign in" "302 $B/login" "$(devlogin hod2 hod.cs@manit.ac.in)"
$PSQL -c "UPDATE users SET active=true WHERE username='hod.cs'"
devlogin stu asha@stu.manit.ac.in >/dev/null
$PSQL -c "UPDATE master_people SET active=false WHERE id_code='A001'"
check "deactivated student is bounced to login" "302 $B/login" "$(redir -c $T/stu.jar -b $T/stu.jar $B/my)"
check "deactivated student cannot sign in" "302 $B/login" "$(devlogin stu2 asha@stu.manit.ac.in)"
$PSQL -c "UPDATE master_people SET active=true WHERE id_code='A001'"

echo "--- admin password lockout after 5 failures"
for i in 1 2 3 4 5; do new_jar lk; login lk admin wrong >/dev/null; done
check "failed_attempts reset after lock" 0 "$($PSQL -c "SELECT failed_attempts FROM users WHERE username='admin'")"
check "account is locked in DB" t "$($PSQL -c "SELECT locked_until > now() FROM users WHERE username='admin'")"
new_jar lk
check "correct password is refused while locked" "302 $B/login" "$(login lk admin NewAdminPass1)"
page lk $B/login | grep -q "Too many failed attempts" && M=ok || M=missing
check "locked account shows lockout message" ok "$M"
LOCKMIN=$($PSQL -c "SELECT round(extract(epoch FROM (locked_until-now()))/60) FROM users WHERE username='admin'")
check "lock lasts about 15 minutes" 15 "$LOCKMIN"
$PSQL -c "UPDATE users SET locked_until = now() - interval '1 second' WHERE username='admin'"
new_jar lk
check "login works again once lock expires" "302 $B/admin" "$(login lk admin NewAdminPass1)"

echo "--- idle timeout"
$PSQL -c "UPDATE settings SET value='0' WHERE key='session_idle_minutes'"
devlogin idle hod.cs@manit.ac.in >/dev/null
devlogin idles asha@stu.manit.ac.in >/dev/null
sleep 2
check "idle session is expired" "302 $B/login" "$(redir -c $T/idle.jar -b $T/idle.jar $B/authority)"
page idle $B/login | grep -q "inactivity" && M=ok || M=missing
check "idle logout explains why" ok "$M"
check "idle student session is expired too" "302 $B/login" "$(redir -c $T/idles.jar -b $T/idles.jar $B/my)"
$PSQL -c "UPDATE settings SET value='30' WHERE key='session_idle_minutes'"
devlogin idle hod.cs@manit.ac.in >/dev/null
check "session stays valid within idle limit" 200 "$(code -c $T/idle.jar -b $T/idle.jar $B/authority)"

echo "--- headers"
H=$(curl -s -D - -o /dev/null -c $T/idle.jar -b $T/idle.jar $B/authority)
echo "$H" | grep -qi "cache-control: no-store" && M=ok || M=missing
check "logged-in pages send no-store" ok "$M"
echo "$H" | grep -qi "x-frame-options" && M=ok || M=missing
check "secure headers present (X-Frame-Options)" ok "$M"
echo "$H" | grep -qi "x-content-type-options: nosniff" && M=ok || M=missing
check "secure headers present (nosniff)" ok "$M"

echo "--- audit log"
check "password change was audited" t "$($PSQL -c "SELECT count(*) >= 1 FROM audit_log WHERE action='password_change' AND username='admin'")"
check "admin creation was audited" 1 "$($PSQL -c "SELECT count(*) FROM audit_log WHERE action='create' AND entity='user' AND username='cli'")"
ERR=$($PSQL -c "UPDATE audit_log SET action='x'" 2>&1 | grep -c "append-only")
check "audit_log UPDATE is blocked" 1 "$ERR"
ERR=$($PSQL -c "DELETE FROM audit_log" 2>&1 | grep -c "append-only")
check "audit_log DELETE is blocked" 1 "$ERR"
ERR=$($PSQL -c "TRUNCATE audit_log" 2>&1 | grep -c "append-only")
check "audit_log TRUNCATE is blocked" 1 "$ERR"
check "sign-ins are audited" t "$($PSQL -c "SELECT count(*) >= 3 FROM audit_log WHERE action='login'")"
check "no password hash text in audit log" 0 "$($PSQL -c "SELECT count(*) FROM audit_log WHERE after_data::text LIKE '%\$2y\$%' OR before_data::text LIKE '%\$2y\$%'")"

echo
echo "RESULT: $pass passed, $fail failed"
[ $fail -eq 0 ]
