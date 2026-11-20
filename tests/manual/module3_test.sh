#!/bin/bash
# End-to-end checks for module 3 (form fields, master list). Fresh migrated + seeded DB, admin created:
#   bash tests/manual/module3_test.sh 'TempAdmin#1'
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
post()  { local jar=$1 path=$2; shift 2; local tok=$(get $jar admin | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -o /dev/null -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }
postshow() { local jar=$1 path=$2 follow=$3; shift 3; post $jar "$path" "$@"; get $jar "$follow"; }
postbody() { local jar=$1 path=$2; shift 2; local tok=$(get $jar admin | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }
up() { local role=$1 file=$2 name=$3; local tok=$(get a admin | token); curl -s -c $T/a.jar -b $T/a.jar -F "csrf_test_name=$tok" -F "file=@$file;filename=$name" $B/admin/people/$role/import; }
tokenof() { grep -o 'name="token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//;s/"//'; }

TEMP="$1"
login a admin "$TEMP" >/dev/null
tok=$(get a account/password | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&current_password=$TEMP&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password

echo "--- screens and access"
for p in admin/fields admin/fields/student admin/fields/faculty admin/fields/non_teaching admin/people admin/people/student admin/people/faculty admin/people/non_teaching admin/people/student/new admin/people/student/import admin/people/faculty/import admin/people/student/import/template admin/people/faculty/import/template admin/people/non_teaching/import/template; do
  check "GET /$p is 200" 200 "$(code -c $T/a.jar -b $T/a.jar $B/$p)"
done
check "unknown role is 404 (fields)" 404 "$(code -c $T/a.jar -b $T/a.jar $B/admin/fields/bogus)"
check "unknown role is 404 (people)" 404 "$(code -c $T/a.jar -b $T/a.jar $B/admin/people/bogus)"
check "anonymous /admin/fields redirected" 302 "$(code $B/admin/fields)"
check "anonymous /admin/people redirected" 302 "$(code $B/admin/people)"
check "POST without CSRF rejected (people)" 403 "$(code -b $T/a.jar -c $T/a.jar -d 'x=1' $B/admin/people/student)"

echo "--- setup lists"
post a admin/lists/departments/add for_student=1 for_faculty=1 name="Computer Science" >/dev/null
post a admin/lists/departments/add for_student=1 name="Mechanical" >/dev/null
post a admin/lists/departments/add for_non_teaching=1 name="Library" >/dev/null
post a admin/lists/hostels/add name="Hostel A" >/dev/null

echo "--- form fields"
R=$(get a admin/fields/student)
has "student form shows Scholar Number" "Scholar Number" "$R"
has "preview mentions fixed fields" "Subject" "$R"
NAME=$(sql "select id from form_fields where role='student' and field_key='name'")
post a admin/fields/student/$NAME/update label="Full Name" visible=0 mandatory=0 >/dev/null
check "core field renamed" "Full Name" "$(sql "select label from form_fields where id=$NAME")"
check "core field stays visible" t "$(sql "select visible from form_fields where id=$NAME")"
check "core field stays mandatory" t "$(sql "select mandatory from form_fields where id=$NAME")"
SEM=$(sql "select id from form_fields where role='student' and field_key='semester'")
post a admin/fields/student/$SEM/update label="Semester" mandatory=0 visible=1 >/dev/null
check "built-in non-core field can be made optional" f "$(sql "select mandatory from form_fields where id=$SEM")"
post a admin/fields/student/$SEM/update label="Semester" mandatory=1 visible=1 >/dev/null
post a admin/fields/student/add label="Mess number" field_type=text mandatory=1 >/dev/null
post a admin/fields/student/add label="Block" field_type=dropdown options=$'North\nSouth' >/dev/null
check "two custom fields added" 2 "$(sql "select count(*) from form_fields where role='student' and field_key like 'cf_%'")"
R=$(postshow a admin/fields/student/add admin/fields/student label="mess NUMBER" field_type=text)
has "duplicate label refused" "already exists" "$R"
R=$(postshow a admin/fields/student/add admin/fields/student label="Bad" field_type=dropdown options="One")
has "dropdown with one choice refused" "at least two" "$R"
MESS=$(sql "select id from form_fields where field_key like 'cf_mess%'")
post a admin/fields/student/$MESS/move direction=up >/dev/null
check "field moved up" "$(sql "select count(*) from form_fields where role='student'")" "$(sql "select count(distinct sort_order) from form_fields where role='student'")"
R=$(get a admin/fields/student); has "preview shows the custom dropdown choices" "South" "$R"
post a admin/fields/student/$NAME/delete >/dev/null
check "built-in field cannot be deleted" 1 "$(sql "select count(*) from form_fields where id=$NAME")"
post a admin/fields/student/$MESS/delete >/dev/null
check "unused custom field deleted" 0 "$(sql "select count(*) from form_fields where id=$MESS")"
check "other roles unaffected" 4 "$(sql "select count(*) from form_fields where role='faculty'")"

echo "--- master list: one person"
CS=$(sql "select id from departments where name='Computer Science'")
post a admin/people/student department_id=$CS id_code=0901CS211001 name="Asha Rao" semester_id=1 hostel_id=1 contact=9876543210 >/dev/null
check "student added" "Asha Rao" "$(sql "select name from master_people where id_code='0901CS211001'")"
R=$(postshow a admin/people/student admin/people/student/new department_id=$CS id_code=0901cs211001 name="Dup" semester_id=1 contact="")
has "duplicate Scholar Number refused (any case)" "already on the list" "$R"
R=$(postshow a admin/people/student admin/people/student/new department_id=$CS id_code=S9 name="Bad" semester_id=1 contact="12")
has "bad contact refused" "Contact number" "$R"
MECH=$(sql "select id from departments where name='Mechanical'")
LIBD=$(sql "select id from departments where name='Library'")
R=$(postshow a admin/people/student admin/people/student/new department_id=$LIBD id_code=S8 name="Wrong dept" semester_id=1 contact="")
has "department not used by students refused" "Choose a department" "$R"
PID=$(sql "select id from master_people where id_code='0901CS211001'")
R=$(get a admin/people/student/$PID/edit); has "edit page loads" "Asha Rao" "$R"
post a admin/people/student/$PID/update department_id=$MECH name="Asha R." semester_id=2 hostel_id="" contact=9876543210 >/dev/null
check "person updated (day scholar)" "Asha R.||Mechanical" "$(sql "select p.name||'|'||coalesce(p.hostel_id::text,'')||'|'||d.name from master_people p join departments d on d.id=p.department_id where p.id=$PID")"
R=$(get a "admin/people/student?q=asha"); has "search finds the person" "0901CS211001" "$R"
R=$(get a "admin/people/student?q=nobody"); has "search with no match" "No one found" "$R"
post a admin/people/student/$PID/toggle >/dev/null
check "person deactivated" f "$(sql "select active from master_people where id=$PID")"
post a admin/people/student/$PID/toggle >/dev/null
sql "insert into grievances(reference_no,type_id,role,id_code,submitter,department_id,subject,description,current_level_id) values ('M3-1',(select id from grievance_types where code='ACD'),'student','0901CS211001','{}'::jsonb,$MECH,'s','d',(select l.id from escalation_levels l join grievance_types t on t.id=l.type_id where t.code='ACD' and l.level_no=1))" >/dev/null
post a admin/people/student/$PID/delete >/dev/null
check "person with grievances cannot be deleted" 1 "$(sql "select count(*) from master_people where id=$PID")"
post a admin/people/student department_id=$CS id_code=TMP1 name="Temp" semester_id=1 hostel_id="" contact="" >/dev/null
TMP=$(sql "select id from master_people where id_code='TMP1'")
post a admin/people/student/$TMP/delete >/dev/null
check "person without grievances deleted" 0 "$(sql "select count(*) from master_people where id=$TMP")"
echo "--- master list: institute e-mail (Google sign-in)"
post a admin/people/student/$PID/update department_id=$MECH name="Asha R." semester_id=2 hostel_id="" contact=9876543210 email="Asha.Rao@stu.manit.ac.in" >/dev/null
check "student e-mail saved in lower case" "asha.rao@stu.manit.ac.in" "$(sql "select email from master_people where id=$PID")"
R=$(get a admin/people/student); has "list shows the e-mail" "asha.rao@stu.manit.ac.in" "$R"
R=$(get a "admin/people/student?q=asha.rao@"); has "search by e-mail" "0901CS211001" "$R"
R=$(postshow a admin/people/student admin/people/student/new department_id=$CS id_code=S7 name="Staff mail" semester_id=1 contact="" email="s7@manit.ac.in")
has "staff e-mail refused for a student" "must be an institute address ending in @stu.manit.ac.in" "$R"
R=$(postshow a admin/people/student admin/people/student/new department_id=$CS id_code=S7 name="Gmail" semester_id=1 contact="" email="s7@gmail.com")
has "private e-mail refused" "@stu.manit.ac.in" "$R"
R=$(postshow a admin/people/student admin/people/student/new department_id=$CS id_code=S7 name="Dup mail" semester_id=1 contact="" email="ASHA.RAO@stu.manit.ac.in")
has "e-mail of another person refused" "already used by 0901CS211001" "$R"
post a admin/people/faculty department_id=$CS id_code=F77 name="Dr. Faculty Mail" contact="" email="f77@manit.ac.in" >/dev/null
check "faculty e-mail saved" "f77@manit.ac.in" "$(sql "select email from master_people where id_code='F77'")"
R=$(postshow a admin/people/faculty admin/people/faculty/new department_id=$CS id_code=F78 name="Stu mail" contact="" email="f78@stu.manit.ac.in")
has "student e-mail refused for faculty" "ending in @manit.ac.in" "$R"
check "the person can now sign in" "302 $B/my" "$(devlogin p asha.rao@stu.manit.ac.in)"
F77=$(sql "select id from master_people where id_code='F77'")
post a admin/people/faculty/$F77/update department_id=$CS name="Dr. Faculty Mail" contact="" email="" >/dev/null
check "clearing the e-mail on the form removes it" "" "$(sql "select coalesce(email,'') from master_people where id=$F77")"
R=$(get a admin/people/faculty); has "person without e-mail is flagged" "none: cannot sign in" "$R"
check "without e-mail they cannot sign in" "302 $B/login" "$(devlogin p2 f77@manit.ac.in)"
post a admin/people/faculty/$F77/delete >/dev/null
R=$(get a admin/people/faculty/$PID/edit); check "wrong-role edit URL is 404" 404 "$(code -c $T/a.jar -b $T/a.jar $B/admin/people/faculty/$PID/edit)"

echo "--- master list upload (csv)"
curl -s -c $T/a.jar -b $T/a.jar -o $T/tpl.xlsx $B/admin/people/student/import/template
python3 - "$T/tpl.xlsx" <<'PY' && echo "PASS  student template valid" && pass=$((pass+1)) || { echo "FAIL  student template"; fail=$((fail+1)); }
import sys, openpyxl
wb = openpyxl.load_workbook(sys.argv[1]); assert wb.sheetnames == ['People','Lists','Instructions']
assert [c.value for c in wb['People'][1]] == ['Scholar Number','Name','Department','Semester','Hostel','Contact','Email']
assert len(wb['People'].data_validations.dataValidation) == 3
lists = wb['Lists']; assert 'Computer Science' in [c.value for c in lists['A']]
PY
H='Scholar Number,Name,Department,Semester,Hostel,Contact'
printf '%s\n' "$H" "0901CS211001,Asha Rao,Computer Science,Semester 3,Hostel A,9876543210" "0901CS211002,Bhanu,Computer Science,2,,9000000002" "0901ME211001,Chetan,Mechanical,Semester 1,Hostel A," > $T/s.csv
R=$(up student $T/s.csv s.csv)
has "preview shows counts" "new people" "$R"
has "preview lists the change to the existing student" "0901CS211001" "$R"
has "preview has no problems" "No problems found" "$R"
check "preview saved nothing" 1 "$(sql "select count(*) from master_people where role='student'")"
BT=$(tokenof "$R")
R=$(postbody a admin/people/import/confirm token=$BT)
check "confirm redirects back (follow)" 3 "$(sql "select count(*) from master_people where role='student'")"
check "existing student updated" "Semester 3" "$(sql "select s.name from master_people p join semesters s on s.id=p.semester_id where p.id_code='0901CS211001'")"
check "existing student's department follows the file" "Computer Science" "$(sql "select d.name from master_people p join departments d on d.id=p.department_id where p.id_code='0901CS211001'")"
check "contact blank in file keeps stored number" "9876543210" "$(sql "select contact from master_people where id_code='0901CS211001'")"
check "file without an Email column keeps the stored e-mail" "asha.rao@stu.manit.ac.in" "$(sql "select email from master_people where id_code='0901CS211001'")"
check "preview file removed after confirm" 0 "$(ls $PWD/writable/imports/$BT.json 2>/dev/null | wc -l)"
R=$(postshow a admin/people/import/confirm admin/people/student/import token=$BT)
has "token cannot be replayed" "expired" "$R"
check "no duplicates after replay" 3 "$(sql "select count(*) from master_people where role='student'")"
echo "    (Email column)"
printf '%s\n' "$H,Email" "0901CS211002,Bhanu,Computer Science,2,,9000000002,Bhanu@stu.manit.ac.in" "0901ME211001,Chetan,Mechanical,Semester 1,Hostel A,," > $T/e.csv
R=$(up student $T/e.csv e.csv); has "file with e-mails has no problems" "No problems found" "$R"; has "preview shows the e-mail change" "e-mail: none" "$R"
postbody a admin/people/import/confirm token=$(tokenof "$R") >/dev/null
check "e-mail saved from the file (lower case)" "bhanu@stu.manit.ac.in" "$(sql "select email from master_people where id_code='0901CS211002'")"
printf '%s\n' "$H,Email" "0901ME211001,Chetan,Mechanical,Semester 1,Hostel A,,bhanu@stu.manit.ac.in" "0901CS211009,Nine,Computer Science,2,,,nine@manit.ac.in" "0901CS211010,Ten,Computer Science,2,,,ten@stu.manit.ac.in" "0901CS211011,Eleven,Computer Science,2,,,ten@stu.manit.ac.in" > $T/e.csv
R=$(up student $T/e.csv e.csv)
has "e-mail of another person reported" "already belongs to 0901CS211002" "$R"
has "wrong-domain e-mail reported" "must be an institute address ending in @stu.manit.ac.in" "$R"
has "e-mail repeated in the file reported" "is repeated: first on row" "$R"

echo "--- upload errors"
printf '%s\n' "$H" "S0,,Computer Science,Semester 1,,," "S1,One,Computer Science,Semester 1,,," "S2,Two,Nowhere,Semester 1,,," "S3,Three,Library,Semester 1,,," "S1,Dup,Computer Science,Semester 1,,," "S5,Five,Computer Science,Semester 99,,," "S6,Six,Computer Science,Semester 1,Hostel Z,," "S7,Seven,Computer Science,Semester 1,,12" > $T/bad.csv
R=$(up student $T/bad.csv bad.csv)
has "errors reported" "problem(s) found" "$R"
has "missing name" "Name is required" "$R"
has "unknown department" "Nowhere" "$R"
has "department not used by students" "not marked as used by student" "$R"
has "repeated id" "repeated" "$R"
has "unknown semester" "Semester 99" "$R"
has "unknown hostel" "Hostel Z" "$R"
has "bad contact" "does not match" "$R"
check "nothing saved from the bad file" 3 "$(sql "select count(*) from master_people where role='student'")"
BT=$(tokenof "$R")
R=$(postshow a admin/people/import/confirm admin/people/student/import token=$BT)
check "a file with errors cannot be confirmed" 3 "$(sql "select count(*) from master_people where role='student'")"
printf '%s\n' "wrong,header" "a,b" > $T/w.csv; up student $T/w.csv w.csv >/dev/null; R=$(get a admin/people/student/import)
has "wrong columns refused" "do not match the template" "$R"
printf x > $T/x.txt; up student $T/x.txt x.txt >/dev/null; R=$(get a admin/people/student/import)
has "wrong file type refused" "Upload an .xlsx or .csv" "$R"

echo "--- faculty / non-teaching upload (xlsx)"
python3 - "$T/f.xlsx" <<'PY'
import sys, openpyxl
wb = openpyxl.Workbook(); ws = wb.active; ws.title = 'People'
ws.append(['Employee Code','Name','Department','Contact'])
ws.append(['E001','Prof One','Computer Science','9111111111'])
ws.append(['E002','Prof Two','Computer Science',''])
wb.save(sys.argv[1])
PY
R=$(up faculty $T/f.xlsx f.xlsx); has "xlsx accepted" "No problems found" "$R"
BT=$(tokenof "$R"); postbody a admin/people/import/confirm token=$BT >/dev/null
check "faculty saved" 2 "$(sql "select count(*) from master_people where role='faculty'")"
check "faculty have no semester/hostel" 0 "$(sql "select count(*) from master_people where role='faculty' and (semester_id is not null or hostel_id is not null)")"
python3 - "$T/n.xlsx" <<'PY'
import sys, openpyxl
wb = openpyxl.Workbook(); ws = wb.active; ws.title = 'People'
ws.append(['Employee Code','Name','Department','Contact'])
ws.append(['N001','Clerk','Computer Science',''])
ws.append(['N002','Librarian','Library',''])
wb.save(sys.argv[1])
PY
R=$(up non_teaching $T/n.xlsx n.xlsx); has "non-teaching file with a student department refused" "not marked as used by non-teaching" "$R"
has "valid row in the same file is still not saved" "problem(s) found" "$R"
check "non-teaching nothing saved" 0 "$(sql "select count(*) from master_people where role='non_teaching'")"
echo "    (tokens are bound to their type of upload)"
R=$(up faculty $T/f.xlsx f.xlsx); BT=$(tokenof "$R")
R=$(postshow a admin/users/import/confirm admin/users/import token=$BT)
has "a master-list token cannot confirm an authority upload" "not an authority upload" "$R"
post a admin/people/import/cancel token=$BT >/dev/null

echo "--- large upload"
python3 - "$T/big.csv" <<'PY'
import sys
with open(sys.argv[1],'w') as f:
    f.write('Scholar Number,Name,Department,Semester,Hostel,Contact\n')
    for i in range(10000):
        f.write(f'BIG{i:06d},Student {i},Computer Science,Semester {i%8+1},{"Hostel A" if i%3==0 else ""},9{i:09d}\n')
PY
S=$(date +%s)
R=$(up student $T/big.csv big.csv); has "10,000-row file previewed" "10000" "$R"; has "10,000 new people" "No problems found" "$R"
BT=$(tokenof "$R"); postbody a admin/people/import/confirm token=$BT >/dev/null
E=$(date +%s)
check "10,000 rows saved" 10003 "$(sql "select count(*) from master_people where role='student'")"
echo "    (took $((E-S)) s)"
R=$(get a "admin/people/student?page=3"); has "pagination works" "Page 3 of" "$R"

echo "--- authority is kept out"
sql "update users set must_change_password=false where username='admin'" >/dev/null
sql "insert into users(username,full_name,email,password_hash,must_change_password) values ('auth3','Auth','auth3@manit.ac.in','x',false)" >/dev/null
devlogin r auth3@manit.ac.in >/dev/null
for p in admin/fields admin/people admin/people/student admin/people/student/import/template; do check "authority forbidden from /$p" 403 "$(code -c $T/r.jar -b $T/r.jar $B/$p)"; done

echo; echo "Passed: $pass   Failed: $fail"; [ $fail -eq 0 ]
