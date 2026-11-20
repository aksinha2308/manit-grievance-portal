#!/bin/bash
# End-to-end checks for module 2 (admin configuration). Run on a freshly migrated + seeded database:
#   php spark portal:create-admin --username admin --password 'TempAdmin#1'
#   php spark serve --port 8080 &   then   bash tests/manual/module2_test.sh 'TempAdmin#1'
B=http://localhost:8080
T=$(mktemp -d)
export PGPASSWORD=${PGPASSWORD:-grievance_dev}
PSQL="psql -q -t -A -h 127.0.0.1 -U grievance ${DBNAME:-grievance_db}"
PREFLIGHT_ARG="$1"; source "$(dirname "$0")/_preflight.sh"
pass=0; fail=0
check() { if [ "$2" == "$3" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi; }
has()   { if grep -qF -- "$2" <<<"$3"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (missing '$2')"; fail=$((fail+1)); fi; }
hasnt() { if grep -qF -- "$2" <<<"$3"; then echo "FAIL  $1 (unexpected '$2')"; fail=$((fail+1)); else echo "PASS  $1"; pass=$((pass+1)); fi; }
token() { grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code()  { curl -s -o /dev/null -w "%{http_code}" "$@"; }
sql()   { $PSQL -c "$1"; }

login() { local jar=$T/$1.jar; rm -f $jar; local tok=$(curl -s -c $jar -b $jar $B/login | token)
  curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c $jar -b $jar -d "csrf_test_name=$tok&username=$2&password=$3" $B/login | sed "s/^303/302/"; }
get()   { curl -s -c $T/$1.jar -b $T/$1.jar "$B/$2"; }
# post JAR PATH key=value ...   -> prints nothing; use get afterwards to see the flash
post()  { local jar=$1 path=$2; shift 2; local tok=$(get $jar admin | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -o /dev/null -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }
# postshow JAR PATH FOLLOW key=value ... -> prints the page FOLLOW after the post (flash visible)
postshow() { local jar=$1 path=$2 follow=$3; shift 3; post $jar "$path" "$@"; get $jar "$follow"; }
# postbody JAR PATH key=value ... -> prints the direct response body
postbody() { local jar=$1 path=$2; shift 2; local tok=$(get $jar admin | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }

TEMP="$1"
echo "--- setup: admin logs in"
login a admin "$TEMP" >/dev/null
tok=$(get a account/password | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&current_password=$TEMP&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password
check "admin reaches dashboard" 200 "$(code -c $T/a.jar -b $T/a.jar $B/admin)"

echo "--- every admin screen loads; access control"
for p in admin admin/types admin/posts admin/users admin/users/new admin/users/import admin/post-holders admin/settings admin/audit admin/lists/departments admin/lists/hostels admin/lists/semesters admin/users/import/template; do
  check "GET /$p is 200" 200 "$(code -c $T/a.jar -b $T/a.jar $B/$p)"
done
for tid in $($PSQL -c "select id from grievance_types order by id"); do
  check "matrix page for type $tid" 200 "$(code -c $T/a.jar -b $T/a.jar $B/admin/types/$tid/matrix)"
done
check "anonymous /admin/types redirected" "302" "$(code $B/admin/types)"
check "anonymous /admin/users redirected" "302" "$(code $B/admin/users)"
check "POST without CSRF rejected" 403 "$(code -b $T/a.jar -c $T/a.jar -d 'name=x' $B/admin/lists/departments/add)"
check "unknown list name is 404" 404 "$(code -c $T/a.jar -b $T/a.jar $B/admin/lists/bogus)"

echo "--- lists: departments / hostels / semesters"
R=$(postshow a admin/lists/departments/add admin/lists/departments for_student=1 for_faculty=1 name="Computer Science")
has "department added" "Computer Science" "$R"
check "one entry is used by students and faculty" "true|true|false" "$(sql "select for_student||'|'||for_faculty||'|'||for_non_teaching from departments where name='Computer Science'")"
post a admin/lists/departments/add for_non_teaching=1 name="Library" >/dev/null
post a admin/lists/departments/add for_student=1 name="Mechanical" >/dev/null
check "three departments exist" 3 "$(sql 'select count(*) from departments')"
R=$(postshow a admin/lists/departments/add admin/lists/departments for_student=1 name="computer science")
has "duplicate name rejected (any case)" "already" "$R"
R=$(postshow a admin/lists/departments/add admin/lists/departments name="Chemistry")
has "department with nobody ticked rejected" "Tick at least one" "$R"
check "still three departments" 3 "$(sql 'select count(*) from departments')"
CS=$(sql "select id from departments where name='Computer Science'")
post a admin/lists/departments/$CS/rename name="Computer Science" for_student=1 >/dev/null
check "faculty tick can be removed while unused" f "$(sql "select for_faculty from departments where id=$CS")"
post a admin/lists/departments/$CS/rename name="Computer Science" for_student=1 for_faculty=1 >/dev/null
check "and ticked again" t "$(sql "select for_faculty from departments where id=$CS")"

post a admin/lists/hostels/add name="Hostel A" >/dev/null
post a admin/lists/hostels/add name="Hostel B" >/dev/null
check "two hostels" 2 "$(sql 'select count(*) from hostels')"
MECH=$(sql "select id from departments where name='Mechanical'")
post a admin/lists/departments/$MECH/rename name="Mechanical Engineering" for_student=1 >/dev/null
check "department renamed" "Mechanical Engineering" "$(sql "select name from departments where id=$MECH")"
sql "insert into master_people(role,id_code,name,department_id) values ('student','S1','Test Student',$MECH)" >/dev/null
post a admin/lists/departments/$MECH/rename name="Mechanical Engineering" for_faculty=1 >/dev/null
check "a tick cannot be removed while students use the department" t "$(sql "select for_student from departments where id=$MECH")"
R=$(postshow a admin/lists/departments/$MECH/delete admin/lists/departments)
has "used department cannot be deleted" "can only be deactivated" "$R"
check "used department still exists" 1 "$(sql "select count(*) from departments where id=$MECH")"
post a admin/lists/departments/$MECH/toggle >/dev/null
check "used department can be deactivated" f "$(sql "select active from departments where id=$MECH")"
post a admin/lists/departments/$MECH/toggle >/dev/null
LIB=$(sql "select id from departments where name='Library'")
post a admin/lists/departments/$LIB/delete >/dev/null
check "unused department deleted" 0 "$(sql "select count(*) from departments where id=$LIB")"
post a admin/lists/semesters/add name="Summer term" >/dev/null
check "semester added" 1 "$(sql "select count(*) from semesters where name='Summer term'")"

echo "--- posts"
R=$(postshow a admin/posts/add admin/posts name="Librarian" scope_type="")
check "post added" 1 "$(sql "select count(*) from posts where name='Librarian'")"
R=$(postshow a admin/posts/add admin/posts name="librarian" scope_type="")
has "duplicate post rejected" "already" "$R"
LB=$(sql "select id from posts where name='Librarian'")
post a admin/posts/$LB/delete >/dev/null
check "unused post deleted" 0 "$(sql "select count(*) from posts where name='Librarian'")"
DIR=$(sql "select id from posts where is_director")
R=$(postshow a admin/posts/$DIR/delete admin/posts)
check "Director cannot be deleted" 1 "$(sql "select count(*) from posts where is_director")"
post a admin/posts/$DIR/toggle >/dev/null
check "Director cannot be deactivated" t "$(sql "select active from posts where is_director")"
HODID=$(sql "select id from posts where name='Head of Department'")
post a admin/posts/$HODID/delete >/dev/null
check "post used in a route cannot be deleted" 1 "$(sql "select count(*) from posts where id=$HODID")"

echo "--- settings"
R=$(get a admin/settings)
has "settings page shows prefix" "MANIT" "$R"
R=$(postshow a admin/settings admin/settings institute_name="Test Institute" ref_prefix="a!" ref_seq_digits=6 attach_max_mb=5 attach_max_count=3 contact_pattern='^[0-9]{10}$' overdue_default_days=7 archive_after_years=3 otp_valid_minutes=10 otp_max_attempts=5 otp_resend_seconds=60 otp_max_per_hour=5 session_idle_minutes=30 lockout_attempts=5 lockout_minutes=15 password_min_length=8 allow_multiple_types_faculty=0 allow_multiple_types_non_teaching=0 'attach_allowed_ext[]=pdf')
has "invalid prefix rejected" "must be 2 to 12 capital letters" "$R"
check "prefix unchanged after rejected save" MANIT "$(sql "select value from settings where key='ref_prefix'")"
R=$(postshow a admin/settings admin/settings institute_name="Test Institute" ref_prefix="MANIT" ref_seq_digits=99 attach_max_mb=5 attach_max_count=3 contact_pattern='(' overdue_default_days=7 archive_after_years=3 otp_valid_minutes=10 otp_max_attempts=5 otp_resend_seconds=60 otp_max_per_hour=5 session_idle_minutes=30 lockout_attempts=5 lockout_minutes=15 password_min_length=8 allow_multiple_types_faculty=0 allow_multiple_types_non_teaching=0 'attach_allowed_ext[]=pdf')
has "out-of-range number rejected" "Digits in the sequence number must be" "$R"
has "bad regex rejected" "Contact number pattern is not a valid pattern" "$R"
R=$(postshow a admin/settings admin/settings institute_name="Test Institute" ref_prefix="MANIT" ref_seq_digits=6 attach_max_mb=5 attach_max_count=3 contact_pattern='^[0-9]{10}$' overdue_default_days=7 archive_after_years=3 otp_valid_minutes=10 otp_max_attempts=5 otp_resend_seconds=60 otp_max_per_hour=5 session_idle_minutes=30 lockout_attempts=5 lockout_minutes=15 password_min_length=8 allow_multiple_types_faculty=0 allow_multiple_types_non_teaching=0 'attach_allowed_ext[]=pdf' 'attach_allowed_ext[]=jpg')
has "valid save accepted" "setting(s) saved" "$R"
check "institute name stored" "Test Institute" "$(sql "select value from settings where key='institute_name'")"
check "file types stored" "pdf,jpg" "$(sql "select value from settings where key='attach_allowed_ext'")"
check "settings change audited" 1 "$(sql "select count(*) from audit_log where entity='settings' and action='update'")"
sql "update settings set value='MANIT' where key='ref_prefix'" >/dev/null

echo "--- grievance types"
FAC_TYPES_BEFORE=$(sql "select count(*) from grievance_types where role='faculty'")
R=$(postshow a admin/types/add admin/types role=faculty name="Library" code="LIB")
check "extra faculty type blocked while switch is off" "$FAC_TYPES_BEFORE" "$(sql "select count(*) from grievance_types where role='faculty'")"
GEN=$(sql "select id from grievance_types where code='FGEN'")
post a admin/types/$GEN/update name="General (Faculty)" code="FGEN" sort_order=1 >/dev/null
check "General type renamed" "General (Faculty)" "$(sql "select name from grievance_types where id=$GEN")"
post a admin/types/$GEN/toggle >/dev/null
check "General type cannot be deactivated" t "$(sql "select active from grievance_types where id=$GEN")"
ACD=$(sql "select id from grievance_types where code='ACD'")
R=$(postshow a admin/types/$ACD/update admin/types name="Academic" code="HST" sort_order=1)
check "duplicate code rejected" ACD "$(sql "select code from grievance_types where id=$ACD")"
R=$(postshow a admin/types/$ACD/update admin/types name="Academic" code="A1" sort_order=1)
check "non-letter code rejected" ACD "$(sql "select code from grievance_types where id=$ACD")"
post a admin/types/add role=student name="Library" code="lib" >/dev/null
check "new student type added (code upper-cased)" LIB "$(sql "select code from grievance_types where name='Library'")"
LIBT=$(sql "select id from grievance_types where code='LIB'")
check "new type starts with the Director level only" "1" "$(sql "select count(*) from escalation_levels where type_id=$LIBT and is_final")"
post a admin/types/$LIBT/delete >/dev/null
check "unused type deleted with its matrix" 0 "$(sql "select count(*) from grievance_types where id=$LIBT")"
echo "    (switch on: several faculty types)"
sql "update settings set value='1' where key='allow_multiple_types_faculty'" >/dev/null
post a admin/types/add role=faculty name="Library" code="LIB" >/dev/null
check "faculty type added when switch is on" 1 "$(sql "select count(*) from grievance_types where role='faculty' and code='LIB'")"
sql "update settings set value='0' where key='allow_multiple_types_faculty'" >/dev/null
R=$(postshow a admin/settings admin/settings institute_name="Test Institute" ref_prefix="MANIT" ref_seq_digits=6 attach_max_mb=5 attach_max_count=3 contact_pattern='^[0-9]{10}$' overdue_default_days=7 archive_after_years=3 otp_valid_minutes=10 otp_max_attempts=5 otp_resend_seconds=60 otp_max_per_hour=5 session_idle_minutes=30 lockout_attempts=5 lockout_minutes=15 password_min_length=8 allow_multiple_types_faculty=0 allow_multiple_types_non_teaching=0 'attach_allowed_ext[]=pdf')
check "single-type switch stays as saved (no extra types to block here)" 1 1
sql "delete from escalation_levels where type_id in (select id from grievance_types where code='LIB' and role='faculty'); delete from grievance_types where code='LIB' and role='faculty'" >/dev/null

echo "--- escalation matrix"
lv() { sql "select string_agg(d.name,' > ' order by l.level_no) from escalation_levels l join posts d on d.id=l.post_id where l.type_id=$1"; }
check "ACD matrix as seeded" "Head of Department > Dean (Academics) > Chairman, Student Grievance Committee (Academic) > Director" "$(lv $ACD)"
WELF=$(sql "select id from posts where name='Dean (Student Welfare)'")
post a admin/types/$ACD/matrix/add post_id=$WELF position=2 overdue_days=5 >/dev/null
check "level inserted at position 2" "Head of Department > Dean (Student Welfare) > Dean (Academics) > Chairman, Student Grievance Committee (Academic) > Director" "$(lv $ACD)"
check "levels renumbered 1..5" "1,2,3,4,5" "$(sql "select string_agg(level_no::text,',' order by level_no) from escalation_levels where type_id=$ACD")"
check "overdue days stored" 5 "$(sql "select overdue_days from escalation_levels where type_id=$ACD and post_id=$WELF")"
R=$(postshow a admin/types/$ACD/matrix/add admin/types/$ACD/matrix post_id=$WELF position=2 overdue_days=5)
has "same post twice in a route rejected" "already" "$R"
LV=$(sql "select id from escalation_levels where type_id=$ACD and post_id=$WELF")
post a admin/types/$ACD/matrix/$LV/move direction=down >/dev/null
check "level moved down" "Head of Department > Dean (Academics) > Dean (Student Welfare) > Chairman, Student Grievance Committee (Academic) > Director" "$(lv $ACD)"
post a admin/types/$ACD/matrix/$LV/move direction=down >/dev/null
post a admin/types/$ACD/matrix/$LV/move direction=down >/dev/null
check "a level cannot be moved below the Director" "Director" "$(sql "select d.name from escalation_levels l join posts d on d.id=l.post_id where type_id=$ACD order by level_no desc limit 1")"
DL=$(sql "select id from escalation_levels where type_id=$ACD and is_final")
post a admin/types/$ACD/matrix/$DL/remove >/dev/null
check "Director level cannot be removed" 1 "$(sql "select count(*) from escalation_levels where id=$DL")"
post a admin/types/$ACD/matrix/$DL/move direction=up >/dev/null
check "Director level cannot be moved" "Director" "$(sql "select d.name from escalation_levels l join posts d on d.id=l.post_id where type_id=$ACD order by level_no desc limit 1")"
post a admin/types/$ACD/matrix/add post_id=$DIR position=1 overdue_days=5 >/dev/null
check "Director cannot be added twice" 1 "$(sql "select count(*) from escalation_levels where type_id=$ACD and post_id=$DIR")"
post a admin/types/$ACD/matrix/$LV/days overdue_days=0 >/dev/null
check "overdue days of 0 rejected" "5" "$(sql "select overdue_days from escalation_levels where id=$LV")"
post a admin/types/$ACD/matrix/$LV/days overdue_days=9 >/dev/null
check "overdue days updated" 9 "$(sql "select overdue_days from escalation_levels where id=$LV")"

echo "--- removing a level re-routes open grievances; history kept"
DEAN=$(sql "select id from escalation_levels where type_id=$ACD and post_id=(select id from posts where name='Dean (Academics)')")
CHAIR=$(sql "select id from escalation_levels where type_id=$ACD and post_id=(select id from posts where name='Chairman, Student Grievance Committee (Academic)')")
mk() { sql "insert into grievances(reference_no,type_id,role,id_code,submitter,department_id,subject,description,current_level_id,status) values ('$1',$ACD,'student','S1','{}'::jsonb,$MECH,'s','d',$2,'$3') returning id"; }
G1=$(mk T-1 $DEAN pending | head -1); G2=$(sql "insert into grievances(reference_no,type_id,role,id_code,submitter,department_id,subject,description,current_level_id,status,paused_since) values ('T-2',$ACD,'student','S1','{}'::jsonb,$MECH,'s','d',$DEAN,'awaiting_submitter',now()) returning id" | head -1)
G3=$(mk T-3 $DEAN pending | head -1); sql "update grievances set status='disposed', disposed_at=now() where id=$G3" >/dev/null
sql "insert into grievance_actions(grievance_id,level_id,level_no,level_label,actor_name,action) values ($G1,$DEAN,2,'Dean (Academics)','Submitter','submit')" >/dev/null
post a admin/types/$ACD/matrix/$DEAN/remove >/dev/null
check "level removed" 0 "$(sql "select count(*) from escalation_levels where id=$DEAN")"
NEXT=$(sql "select current_level_id from grievances where id=$G1")
check "open grievance moved to next surviving level (Chairman, after earlier reorder)" "Chairman, Student Grievance Committee (Academic)" "$(sql "select d.name from escalation_levels l join posts d on d.id=l.post_id where l.id=$NEXT")"
check "awaiting-submitter grievance also moved and still waiting" "awaiting_submitter" "$(sql "select status from grievances where id=$G2")"
check "its clock is still paused" t "$(sql "select paused_since is not null from grievances where id=$G2")"
check "disposed grievance untouched (no re-route action)" 0 "$(sql "select count(*) from grievance_actions where grievance_id=$G3")"
check "re-route recorded in history" 1 "$(sql "select count(*) from grievance_actions where grievance_id=$G1 and action='reroute'")"
check "earlier history row survives with its label" "Dean (Academics)" "$(sql "select level_label from grievance_actions where grievance_id=$G1 and action='submit'")"
check "levels renumbered after removal" "1,2,3,4" "$(sql "select string_agg(level_no::text,',' order by level_no) from escalation_levels where type_id=$ACD")"
check "re-route queued a notification event" 1 "$(sql "select count(*) from notification_events where grievance_id=$G1 and event_type='rerouted'")"

echo "--- authority accounts (Google e-mail, no passwords)"
R=$(postshow a admin/users admin/users/new username=hod.cs full_name="Dr. Hod CS" email=HOD.CS@manit.ac.in)
check "account created" 1 "$(sql "select count(*) from users where username='hod.cs'")"
check "e-mail stored in lower case" "hod.cs@manit.ac.in" "$(sql "select email from users where username='hod.cs'")"
check "no password change pending" f "$(sql "select must_change_password from users where username='hod.cs'")"
check "new authority can sign in with Google (test login)" "302 $B/authority" "$(devlogin h hod.cs@manit.ac.in)"
R=$(postshow a admin/users admin/users/new username=hod.cs full_name="Dup" email=dup@manit.ac.in)
has "duplicate username rejected" "already" "$R"
R=$(postshow a admin/users admin/users/new username="bad name" full_name="X" email=x@manit.ac.in)
has "bad username rejected" "Username" "$R"
R=$(postshow a admin/users admin/users/new username=other full_name="Other" email="hod.cs@manit.ac.in")
has "duplicate email rejected" "email" "$R"
R=$(postshow a admin/users admin/users/new username=other full_name="Other" email="")
has "e-mail is required" "Institute e-mail is required" "$R"
R=$(postshow a admin/users admin/users/new username=other full_name="Other" email="other@gmail.com")
has "private e-mail rejected" "staff address ending in @manit.ac.in" "$R"
R=$(postshow a admin/users admin/users/new username=other full_name="Other" email="other@stu.manit.ac.in")
has "student e-mail rejected for an authority" "staff address" "$R"
postbody a admin/users username=typed full_name="Second Authority" email="second@manit.ac.in" >/dev/null
check "second account created" 1 "$(sql "select count(*) from users where username='typed'")"
HC=$(sql "select id from users where username='hod.cs'")
R=$(get a admin/users/$HC/edit); has "edit page loads" "Posts held" "$R"; hasnt "no password reset any more" "Reset password" "$R"
check "password reset route is gone" 404 "$(code -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$(get a admin | token)" $B/admin/users/$HC/reset-password)"
post a admin/users/$HC/update full_name="Dr. H. C. Sharma" email="hod.cs@manit.ac.in" >/dev/null
check "name updated" "Dr. H. C. Sharma" "$(sql "select full_name from users where id=$HC")"
R=$(postshow a admin/users/$HC/update admin/users/$HC/edit full_name="Dr. H. C. Sharma" email="")
has "e-mail cannot be removed" "Institute e-mail is required" "$R"
sql "update users set email=null where username='typed'" >/dev/null
has "account without e-mail is flagged" "No e-mail: cannot sign in" "$(get a admin/users)"
sql "update users set email='second@manit.ac.in' where username='typed'" >/dev/null
sql "update users set failed_attempts=5, locked_until=now()+interval '10 minutes' where id=$HC" >/dev/null
has "locked account is flagged" "Locked" "$(get a admin/users)"
post a admin/users/$HC/unlock >/dev/null
check "unlock clears the lock" "t" "$(sql "select locked_until is null and failed_attempts=0 from users where id=$HC")"

echo "--- post holders"
CSS=$CS
R=$(get a admin/post-holders/$HODID); has "HOD page lists departments" "Computer Science" "$R"; has "shows vacancy" "Vacant" "$R"
post a admin/post-holders/$HODID/assign scope_id=$CSS user_id=$HC >/dev/null
check "HOD assigned to student CS" "$HC" "$(sql "select user_id from post_holders where post_id=$HODID and scope_id=$CSS and active")"
has "HOD page shows who uses the department" "Student, Faculty" "$R"
hasnt "non-teaching-only department is not offered for HOD" "Library" "$R"
post a admin/lists/departments/add for_non_teaching=1 name="Accounts" >/dev/null
RO=$(sql "select id from posts where name='Reporting Officer'")
R2=$(get a admin/post-holders/$RO); has "Reporting Officer page offers non-teaching departments" "Accounts" "$R2"
hasnt "Reporting Officer page does not offer student-only departments" "Mechanical Engineering" "$R2"
check "non-matching department stays vacant" 0 "$(sql "select count(*) from post_holders where post_id=$HODID and scope_id=$MECH")"
post a admin/post-holders/$HODID/assign scope_id=$MECH user_id=$HC >/dev/null
check "one user may hold several posts" 2 "$(sql "select count(*) from post_holders where user_id=$HC and active")"
OTHER=$(sql "select id from users where username='typed'")
post a admin/post-holders/$HODID/assign scope_id=$MECH user_id=$OTHER >/dev/null
check "assigning replaces the holder (one active holder per post)" "$OTHER" "$(sql "select user_id from post_holders where post_id=$HODID and scope_id=$MECH and active")"
check "previous holder's record is kept, ended" 1 "$(sql "select count(*) from post_holders where post_id=$HODID and scope_id=$MECH and not active and ended_at is not null")"
post a admin/post-holders/$HODID/vacate scope_id=$MECH >/dev/null
check "post vacated" 0 "$(sql "select count(*) from post_holders where post_id=$HODID and scope_id=$MECH and active")"
R=$(get a admin); has "dashboard warns about vacant posts" "vacant post" "$R"
R=$(get a admin/post-holders); has "overview shows vacancies in routes" "vacant in a route" "$R"
check "deleting an account that held a post is refused" 1 "$(code -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$(get a admin | token)" $B/admin/users/$HC/delete >/dev/null; sql "select count(*) from users where id=$HC")"
post a admin/users/$HC/toggle >/dev/null
check "deactivating a user ends all their posts" 0 "$(sql "select count(*) from post_holders where user_id=$HC and active")"
check "deactivated user cannot sign in" "302 $B/login" "$(devlogin h hod.cs@manit.ac.in)"
post a admin/users admin/users/new >/dev/null; postbody a admin/users username=fresh full_name="Fresh" email="fresh@manit.ac.in" >/dev/null
UNUSED=$(sql "select id from users where username='fresh'")
post a admin/post-holders/$HODID/assign scope_id=$CSS user_id=$HC >/dev/null
check "inactive user cannot be assigned" 0 "$(sql "select count(*) from post_holders where user_id=$HC and active")"
post a admin/users/$UNUSED/delete >/dev/null
check "account with no history can be deleted" 0 "$(sql "select count(*) from users where id=$UNUSED")"

echo "--- bulk upload"
curl -s -c $T/a.jar -b $T/a.jar -o $T/tpl.xlsx $B/admin/users/import/template
python3 - "$T/tpl.xlsx" <<'PY' && echo "PASS  template opens in openpyxl and has the sheets" && pass=$((pass+1)) || { echo "FAIL  template invalid"; fail=$((fail+1)); }
import sys, openpyxl
wb = openpyxl.load_workbook(sys.argv[1])
assert wb.sheetnames == ['Users','Lists','Instructions'], wb.sheetnames
ws = wb['Users']
assert [c.value for c in ws[1]] == ['Username','Full Name','Email','Post','Department or Hostel']
assert len(ws.data_validations.dataValidation) == 1
PY
mkcsv() { printf '%s\n' "$@" > $T/up.csv; }
up() { local tok=$(get a admin/users/import | token); curl -s -c $T/a.jar -b $T/a.jar -F "csrf_test_name=$tok" -F "file=@$1;filename=$2" $B/admin/users/import; }
H='Username,Full Name,Email,Post,Department or Hostel'
mkcsv "$H" "bulk.hod,Bulk HOD,bulk.hod@manit.ac.in,Head of Department,Computer Science" "bulk.hod,Bulk HOD,,Warden,Hostel B" "bulk.warden,Bulk Warden,Bulk.Warden@manit.ac.in,Warden,Hostel A" "bulk.reg,Bulk Registrar,bulk.reg@manit.ac.in,Registrar,"
R=$(up $T/up.csv up.csv)
has "preview shows counts" "accounts to create" "$R"
has "preview has no problems" "No problems found" "$R"
check "preview wrote nothing to the database" 0 "$(sql "select count(*) from users where username like 'bulk.%'")"
BT=$(grep -o 'name="token" value="[^"]*"' <<<"$R" | head -1 | sed 's/.*value="//;s/"//')
check "preview token file is private (0600)" 600 "$(stat -c %a $PWD/writable/imports/$BT.json)"
R=$(postshow a admin/users/import/confirm admin/users token=$BT)
has "confirm reports what was created" "3 account(s) and 4 post assignment(s) were created" "$R"
check "three accounts created" 3 "$(sql "select count(*) from users where username like 'bulk.%'")"
check "multi-row user holds two posts" 2 "$(sql "select count(*) from post_holders a join users u on u.id=a.user_id where u.username='bulk.hod' and a.active")"
check "bulk e-mail stored in lower case" "bulk.warden@manit.ac.in" "$(sql "select email from users where username='bulk.warden'")"
check "bulk account signs in with Google (test login)" "302 $B/authority" "$(devlogin bw bulk.warden@manit.ac.in)"
R=$(postbody a admin/users/import/confirm token=$BT)
check "confirm token cannot be replayed" 3 "$(sql "select count(*) from users where username like 'bulk.%'")"
post a admin/lists/hostels/add name="Hostel C" >/dev/null
mkcsv "$H" "bulk.hod,Dup,dup@manit.ac.in,,"  "new.one,,one@manit.ac.in,," "new.two,Two,not-an-email,," "new.three,Three,three@manit.ac.in,Nonexistent Post," "new.four,Four,four@manit.ac.in,Head of Department,Nowhere" "new.five,Five,five@manit.ac.in,Head of Department,Accounts" "new.six,Six,six@manit.ac.in,Registrar,x" "new.seven,Seven,seven@manit.ac.in,Registrar," "new.eight,Eight,eight@manit.ac.in,Registrar," "new.nine,Nine,nine@manit.ac.in,Warden,Hostel C" "new.ten,Ten,ten@manit.ac.in,Warden,Hostel C" "new.hod2,Hod2,hod2@manit.ac.in,Head of Department,Computer Science" "new.noemail,No Email,,," "new.gmail,Gmail,x@gmail.com,," "new.dupmail,Dup Mail,bulk.reg@manit.ac.in,,"
R=$(up $T/up.csv up.csv)
has "errors reported" "problem(s) found" "$R"
has "existing username reported" 'already exists' "$R"
has "missing full name reported" "Full Name is required" "$R"
has "bad email reported" "Email is not valid" "$R"
has "missing email reported" "Email is required" "$R"
has "non-institute email reported" "staff address ending in @manit.ac.in" "$R"
has "email of another account reported" "already used by another account" "$R"
has "unknown post reported" 'Nonexistent Post' "$R"
has "unknown department reported" 'Nowhere' "$R"
has "department not used by this post reported" "for this post" "$R"
has "single post with department reported" "is a single post" "$R"
has "post twice in file reported" "A post can have only one holder" "$R"
has "post already held reported" "already held by" "$R"
has "row numbers are shown" "<td>3</td>" "$R"
check "nothing was saved from an invalid file" 0 "$(sql "select count(*) from users where username like 'new.%'")"
mkcsv "Username,Full Name,Email,Temporary Password,Post,Department or Hostel" "a,b,c,d,e,f"
up $T/up.csv up.csv >/dev/null; R=$(get a admin/users/import); has "old template with passwords rejected" "do not match the template" "$R"
mkcsv "wrong,header" "a,b"
up $T/up.csv up.csv >/dev/null; R=$(get a admin/users/import); has "wrong columns rejected" "do not match the template" "$R"
printf 'x' > $T/bad.txt
up $T/bad.txt bad.txt >/dev/null; R=$(get a admin/users/import); has "wrong file type rejected" "Upload an .xlsx or .csv" "$R"
echo "    (xlsx upload round trip)"
python3 - "$T/ok.xlsx" <<'PY'
import sys, openpyxl
wb = openpyxl.Workbook(); ws = wb.active; ws.title = 'Users'
ws.append(['Username','Full Name','Email','Post','Department or Hostel'])
ws.append(['xl.user','Excel User','xl.user@manit.ac.in','Dean (Academics)',''])
wb.save(sys.argv[1])
PY
R=$(up $T/ok.xlsx ok.xlsx); has "xlsx upload parsed" "No problems found" "$R"
BT=$(grep -o 'name="token" value="[^"]*"' <<<"$R" | head -1 | sed 's/.*value="//;s/"//')
post a admin/users/import/cancel token=$BT >/dev/null
check "discard removes the preview file" no "$([ -f $PWD/writable/imports/$BT.json ] && echo yes || echo no)"
check "discard saved nothing" 0 "$(sql "select count(*) from users where username='xl.user'")"
R=$(up $T/ok.xlsx ok.xlsx)
BT=$(grep -o 'name="token" value="[^"]*"' <<<"$R" | head -1 | sed 's/.*value="//;s/"//')
sql "insert into users(username,full_name,password_hash) values ('xl.user','Raced','x')" >/dev/null
R=$(postshow a admin/users/import/confirm admin/users/import token=$BT)
has "a username taken after the preview aborts the whole upload" "since the preview" "$R"
check "aborted upload created nothing else" 1 "$(sql "select count(*) from users where username='xl.user'")"
echo "    (another admin session cannot use a token it did not create)"
R=$(postshow a admin/users/import/confirm admin/users/import token=../../etc/passwd); has "bad token format is refused" "no longer available" "$R"

echo "--- audit log"
R=$(get a admin/audit); has "audit page shows entries" "create" "$R"
has "audit shows who acted" "admin" "$R"
R=$(get a "admin/audit?entity=user&action=create"); has "audit filter works" "user" "$R"
check "no passwords in the audit log" 0 "$(sql "select count(*) from audit_log where after_data::text ilike '%NewAdminPass1%'")"
check "audit log is append-only" "t" "$(sql "do \$\$ begin begin delete from audit_log; exception when others then null; end; end \$\$; select count(*)>0 from audit_log")"

echo "--- authority is kept out of admin screens"
devlogin r bulk.reg@manit.ac.in >/dev/null
for p in admin/types admin/users admin/settings admin/post-holders admin/audit admin/users/import/template; do check "authority forbidden from /$p" 403 "$(code -c $T/r.jar -b $T/r.jar $B/$p)"; done

echo; echo "Passed: $pass   Failed: $fail"; [ $fail -eq 0 ]
