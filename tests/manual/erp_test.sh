#!/bin/bash
# End-to-end checks for people coming from the ERP (sign-in, live profile, blocks). Uses the DUMMY ERP databases
# (erp_dummy, erp_hostel_dummy - see the dummy ERP zip) and the dev server on port 8080 against the portal database:
#   bash tests/manual/erp_test.sh
# Run from the project root on a migrated database with the admin created (a test one, never the live portal).
# It switches the setting person_source to 'erp' and adds a few departments/hostels/title mappings; at the end it
# puts person_source back to what it was.
B=http://localhost:8080
T=$(mktemp -d)
export PGPASSWORD=${PGPASSWORD:-grievance_dev}
PSQL="psql -q -t -A -h 127.0.0.1 -U grievance ${DBNAME:-grievance_test}"
PREFLIGHT_ARG="${1:-not-needed}"; source "$(dirname "$0")/_preflight.sh"
pass=0; fail=0
check() { if [ "$2" == "$3" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi; }
has()   { if grep -qF -- "$2" <<<"$3"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (missing '$2')"; fail=$((fail+1)); fi; }
hasnt() { if grep -qF -- "$2" <<<"$3"; then echo "FAIL  $1 (unexpected '$2')"; fail=$((fail+1)); else echo "PASS  $1"; pass=$((pass+1)); fi; }
sql()   { $PSQL -c "$1"; }
page()  { curl -s -L -c $T/$1.jar -b $T/$1.jar "$B/$2"; }

OLD=$(sql "select coalesce((select value from settings where key='person_source'),'manual')")
echo "--- setup (DUMMY ERP)"
sql "insert into settings(key,value) values('person_source','erp') on conflict (key) do update set value='erp'" >/dev/null
sql "insert into departments(name,active,for_student,for_faculty,for_non_teaching,erp_code)
     select 'Computer Science (erp test)',true,true,true,false,'CSE' where not exists (select 1 from departments where erp_code='CSE')" >/dev/null
sql "insert into hostels(name,active,erp_code)
     select 'Boys Hostel 5 (erp test)',true,'5' where not exists (select 1 from hostels where erp_code='5')" >/dev/null
sql "delete from designation_roles where designation_key in ('accountant')" >/dev/null
# a student whose department (CE) is NOT set up, a student with a mapped department and hostel
STU_OK=2026cse001@stu.manit.ac.in      # CSE, hostel 5 in the dummy ERP
STU_NODEPT=2023ce001@stu.manit.ac.in   # CE: no department with that ERP code
STU_NONE=2999xx999@stu.manit.ac.in     # not in the ERP at all
FAC=ananya.mehta@manit.ac.in           # Professor, CSE
NT=mayank.reddy@manit.ac.in            # Accountant (non-teaching title)

echo "--- student sign-in from the ERP"
r=$(devlogin s1 $STU_OK); has "known student signs in" "302" "$r"; hasnt "no error redirect to login" "/login" "$r"
f=$(page s1 submit)
has "form shows ERP department" "Computer Science (erp test)" "$f"
has "form shows hostel from ERP" "Boys Hostel 5 (erp test)" "$f"
has "form shows room" "room " "$f"
check "ERP created an identity row (source erp)" "1" "$(sql "select count(*) from master_people where lower(email)='$STU_OK' and source='erp'")"
check "no department/semester stored on the row" "" "$(sql "select coalesce(department_id::text,'')||coalesce(hostel_id::text,'') from master_people where lower(email)='$STU_OK'")"

echo "--- student blocks"
r=$(devlogin s2 $STU_NODEPT); f=$(page s2 submit)
has "unmapped department blocks submission" "has not been set up in the portal yet" "$f"
r=$(devlogin s3 $STU_NONE); has "unknown student cannot sign in" "/login" "$r"
f=$(page s3 login); has "not registered message" "not registered" "$f"

echo "--- staff"
r=$(devlogin f1 $FAC); has "faculty signs in" "302" "$r"
f=$(page f1 submit); has "faculty details come from the ERP" "Ananya Mohan Mehta" "$f"
r=$(devlogin n1 $NT); f=$(page n1 login)
has "unlisted non-teaching title blocks sign-in" "has not been set up in the portal yet" "$f"
check "audit entry for the unlisted title" "1" "$(sql "select count(*)>0 from audit_log where action='erp_issue'" | sed 's/t/1/;s/f/0/')"
sql "insert into designation_roles(designation_key,designation_text,role) values('accountant','Accountant','non_teaching') on conflict (designation_key) do update set role='non_teaching'" >/dev/null
r=$(devlogin n2 $NT); has "after the admin adds the title, sign-in works" "302" "$r"

token() { grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
post() { local jar=$1 path=$2 pg=$3; shift 3; local tok=$(page $jar $pg | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done; curl -s -L -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }

echo "--- admin ERP pages"
as_user a admin
f=$(page a admin/erp)
has "ERP page shows the connection" "ERP main database" "$f"
has "main database OK" "OK" "$f"
has "unmapped department code listed" "not mapped" "$f"
has "unlisted job titles are listed for assignment" "not listed" "$f"
DEPT=$(sql "insert into departments(name,active,for_student,for_faculty,for_non_teaching) values('Civil (erp test)',true,true,true,true) returning id")
f=$(post a admin/erp/department admin/erp "id=$DEPT" "code=CE")
has "mapping a department confirms" "now maps to department" "$f"
check "department got the ERP code" "CE" "$(sql "select erp_code from departments where id=$DEPT")"
f=$(post a admin/erp/department admin/erp "id=$DEPT" "code=XX")
has "a department cannot have two ERP codes" "already uses the ERP code" "$f"
f=$(post a admin/erp/department/$DEPT/clear admin/erp)
has "clearing a code works" "was cleared" "$f"
post a admin/erp/department admin/erp "id=$DEPT" "code=CE" >/dev/null
f=$(post a admin/erp/title admin/erp "title=Clerk" "role=non_teaching")
has "assigning a job title confirms" "is now treated as" "$f"
check "title is listed" "1" "$(sql "select count(*) from designation_roles where designation_key='clerk'")"
f=$(post a admin/erp/department/create admin/erp "code=ACC")
has "creating a department without choosing who may use it is refused" "Choose who may use" "$f"
f=$(post a admin/erp/department/create admin/erp "code=ACC" "roles[]=non_teaching")
has "a department can be created from the ERP" "department(s) created from the ERP" "$f"
check "it is named as in the ERP and carries the ERP text" "ACC|ACC" "$(sql "select name||'|'||erp_code from departments where erp_code='ACC'")"
f=$(post a admin/erp/hostel/create-all admin/erp)
has "all new hostels can be created from the ERP" "hostel(s) created from the ERP" "$f"
check "no hostel code is left unmapped" "0" "$(sql "select count(*) from hostels where erp_code is null and name like 'Hostel %'")"
f=$(page a admin/erp/people)
has "people list shows ERP people" "2026cse001" "$f"
f=$(page a "admin/erp/people?q=ananya")
has "people list can be searched" "ananya.mehta@manit.ac.in" "$f"
f=$(page a admin/erp/sync)
has "sync preview explains itself" "would" "$f"
has "sync preview shows the accounts-not-created count" "no portal account yet" "$f"
has "sync preview says accounts are created at first sign-in" "first sign" "$f"
BEFORE=$(sql "select count(*) from master_people where source='erp'")
f=$(post a admin/erp/sync admin/erp/sync "confirm_mass_off=1")
AFTER=$(sql "select count(*) from master_people where source='erp'")
check "sync creates no accounts" "$BEFORE" "$AFTER"
check "sync is in the audit log" "1" "$(sql "select count(*)>0 from audit_log where action='erp_sync'" | sed 's/t/1/;s/f/0/')"
f=$(page a admin/people)
has "the old master list sends the admin to the ERP people list" "People now come from the institute ERP" "$f"
f=$(page a admin)
hasnt "the Master list menu item is hidden when people come from the ERP" "Master list" "$f"
check "a non-admin cannot open the ERP pages" "403" "$(devlogin st1 $STU_OK >/dev/null; curl -s -o /dev/null -w '%{http_code}' -b $T/st1.jar -c $T/st1.jar $B/admin/erp)"

echo "--- deactivation by the ERP list"
check "no master list screens needed: person_source is erp" "erp" "$(sql "select value from settings where key='person_source'")"

sql "update settings set value='$OLD' where key='person_source'" >/dev/null
echo; echo "erp_test: $pass passed, $fail failed"; rm -rf $T
[ $fail -eq 0 ]
