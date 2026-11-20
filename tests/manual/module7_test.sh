#!/bin/bash
# End-to-end checks for module 7 (admin grievance list and detail, exports for admin / Director / authorities,
# summary report, archiving). Needs a FRESH migrated + seeded database, the admin created, and the dev server
# running on port 8080 against that database:
#   bash tests/manual/module7_test.sh '<admin temporary password>'
# Run it from the project root. It leaves test data behind (the history is append-only by design).
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
get()   { curl -s -L -c $T/$1.jar -b $T/$1.jar "$B/$2"; }
login() { as_user "$1" "$2"; }   # authorities sign in with Google (test login); the password argument is ignored
act() { local tok=$(get $1 authority/grievances/$2 | token)
  curl -s -o /dev/null -c $T/$1.jar -b $T/$1.jar --data-urlencode "csrf_test_name=$tok" --data-urlencode "action=$3" --data-urlencode "remark=$4" \
    --data-urlencode "confirm_waiting=1" $B/authority/grievances/$2/act; }
# post <jar> <path> [field=value ...]: POST with a fresh CSRF token taken from <page> (2nd arg after path)
post() { local jar=$1 path=$2 page=$3; shift 3; local tok=$(get $jar $page | token); local args=(--data-urlencode "csrf_test_name=$tok")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done; curl -s -o /dev/null -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" "$B/$path"; }
# submission: sign in as the master-list person (test login), then submit with the OTP from the log
begin() { as_person "$1" "$3" >/dev/null; }
fillform() { local jar=$1; shift 2; submit_as $jar "$@"; }
submit_student() { rm -f $T/s.jar; begin s student "$1" >/dev/null; shift; fillform s student "$@" >/dev/null; sql "select max(id) from grievances"; }
# download <jar> <path>: saves body to $T/dl and headers to $T/dl.h
download() { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/dl.h -o $T/dl "$B/$2"; }
xlsx_cells() { php -r '$z=new ZipArchive; $z->open($argv[1]); echo strip_tags(str_replace("</c>"," | ",$z->getFromName("xl/worksheets/sheet1.xml")));' "$1"; }

TEMP="$1"
echo "--- setup"
rm -f $T/a.jar; tok=$(curl -s -c $T/a.jar -b $T/a.jar $B/login | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&username=admin&password=$TEMP" $B/login
tok=$(get a account/password | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&current_password=$TEMP&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password
sql "insert into departments(name,for_student,for_faculty,for_non_teaching) values ('Civil Engineering (CE)',true,true,false),('Computer Science and Engineering (CSE)',true,true,false)" >/dev/null
CE=$(sql "select id from departments where name like 'Civil%'"); CSE=$(sql "select id from departments where name like 'Computer%'")
SEM=$(sql "select id from semesters order by id limit 1")
HASH=$(php -r 'echo password_hash("Auth0rity#Pass", PASSWORD_DEFAULT);')
for u in hod.ce:'Dr. Civil Hodname' hod.cse:'Dr. CSE Hodname' director:'Director Dirname'; do
  sql "insert into users(username,full_name,password_hash,must_change_password) values ('${u%%:*}','${u#*:}','$HASH',false)" >/dev/null; done
uid() { sql "select id from users where username='$1'"; }
HODD=$(sql "select id from posts where name='Head of Department'")
sql "insert into post_holders(post_id,scope_id,user_id) values ($HODD,$CE,$(uid hod.ce)),($HODD,$CSE,$(uid hod.cse)),
 ($(sql 'select id from posts where is_director'),NULL,$(uid director))" >/dev/null
sql "insert into master_people(role,id_code,name,department_id,semester_id,hostel_id,contact) values
 ('student','T7S001','Ravi Civil',$CE,$SEM,NULL,'9876500011'),('student','T7S002','Meera Computer',$CSE,$SEM,NULL,'9876500012')" >/dev/null
ACD=$(sql "select id from grievance_types where code='ACD'")
printf '%%PDF-1.4\n%%EOF\n' > $T/a.pdf
G1=$(submit_student T7S001 type_id=$ACD contact=9876500011 "subject=Lab marks" "description=CE-301" "attachments[]=@$T/a.pdf;filename=marks.pdf")
G2=$(submit_student T7S001 type_id=$ACD contact=9876500011 "subject==SUM(A1:A9) formula test" "description=Second" confirm_duplicate=1)
G3=$(submit_student T7S002 type_id=$ACD contact=9876500012 "subject=Attendance" "description=CSE grievance")
REF1=$(sql "select reference_no from grievances where id=$G1"); REF2=$(sql "select reference_no from grievances where id=$G2"); REF3=$(sql "select reference_no from grievances where id=$G3")
login h hod.ce 'Auth0rity#Pass'; login o hod.cse 'Auth0rity#Pass'; login x director 'Auth0rity#Pass'
act h $G1 dispose "Marks corrected." >/dev/null
check "setup: 3 grievances, one disposed" "3|1" "$(sql 'select count(*) from grievances')|$(sql "select count(*) from grievances where status='disposed'")"

echo "--- admin grievance list"
R=$(get a admin); has "admin menu has Grievances, Report, Archive" 'admin/grievances' "$R"; has "report link" 'admin/report' "$R"; has "archive link" 'admin/archive' "$R"
R=$(get a admin/grievances)
has "all three listed" "$REF3" "$R"; has "first listed" "$REF1" "$R"
has "Scholar Number shown" "T7S002" "$R"
R=$(get a "admin/grievances?q=meera"); has "search by name" "$REF3" "$R"; hasnt "search excludes others" "$REF1" "$R"
R=$(get a "admin/grievances?q=t7s001"); has "search by Scholar Number (lost reference)" "$REF2" "$R"; hasnt "only that student" "$REF3" "$R"
R=$(get a "admin/grievances?status=disposed"); has "status filter" "$REF1" "$R"; hasnt "status filter excludes" "$REF2" "$R"
R=$(get a "admin/grievances?department=$CSE"); has "department filter" "$REF3" "$R"; hasnt "department filter excludes" "$REF1" "$R"
R=$(get a "admin/grievances?to=2020-01-01"); has "date filter: nothing that old" "No grievance matches these filters" "$R"
R=$(get a "admin/grievances?status=<script>"); hasnt "bad filter values ignored safely" "<script>" "$R"
check "authority cannot open the admin list" 403 "$(code -b $T/h.jar $B/admin/grievances)"
check "not logged in: redirected" 302 "$(code $B/admin/grievances)"

echo "--- admin grievance detail"
R=$(get a admin/grievances/$G1)
has "detail shows the reference" "$REF1" "$R"
has "authority's name visible to the admin" "Dr. Civil Hodname" "$R"
has "view-only panel" 'id="admin-panel"' "$R"
hasnt "no action panel for the admin" 'id="action-panel"' "$R"
has "back link to the admin list" "Back to all grievances" "$R"
has "related banner links to admin pages" "admin/grievances/$G2" "$R"
AID=$(sql "select id from attachments where grievance_id=$G1")
download a admin/grievances/$G1/attachments/$AID
check "admin downloads the attachment" "$(md5sum < $T/a.pdf)" "$(md5sum < $T/dl)"
check "admin cannot act (no route)" 404 "$(code -b $T/a.jar -X POST $B/admin/grievances/$G1/act)"

echo "--- admin exports"
download a "admin/grievances/export?kind=grievances&format=csv"
has "CSV download" 'attachment; filename="grievances-' "$(cat $T/dl.h)"
has "CSV has the contact number" "9876500012" "$(cat $T/dl)"
has "CSV has the decision" "Marks corrected." "$(cat $T/dl)"
has "formula neutralised in CSV" "'=SUM(A1:A9)" "$(cat $T/dl)"
check "CSV has 3 grievance rows" 3 "$(grep -c '^MANIT-' $T/dl)"
has "CSV header" '"Reference no."' "$(head -1 $T/dl)"
download a "admin/grievances/export?kind=grievances&format=xlsx&department=$CSE"
has "Excel download" "spreadsheetml" "$(cat $T/dl.h)"
CELLS=$(xlsx_cells $T/dl); has "Excel respects the filters" "$REF3" "$CELLS"; hasnt "Excel filtered out others" "$REF1" "$CELLS"
download a "admin/grievances/export?kind=history&format=csv&q=$REF1"
has "history export lists the actions" "Disposed" "$(cat $T/dl)"; has "history export names the person" "Dr. Civil Hodname" "$(cat $T/dl)"
check "exports audited" 3 "$(sql "select count(*) from audit_log where action='export' and entity='grievances'")"
has "audit records the filters" '"department"' "$(sql "select after_data from audit_log where action='export' order by id desc offset 1 limit 1")"
L=$(curl -s -o /dev/null -w '%{redirect_url}' -b $T/a.jar -c $T/a.jar "$B/admin/grievances/export?kind=bogus&format=csv"); check "bad export request goes back to the list" "$B/admin/grievances?archived=no" "$L"

echo "--- authority exports (own lists only)"
R=$(get h authority); has "authority has an export menu" 'id="export-menu"' "$R"
download h "authority/export?tab=action&kind=grievances&format=csv"
has "HOD CE export has their pending grievance" "$REF2" "$(cat $T/dl)"; hasnt "HOD CE export excludes the CSE grievance" "$REF3" "$(cat $T/dl)"
download h "authority/export?tab=earlier&kind=history&format=csv"; has "earlier-tab history export" "$REF1" "$(cat $T/dl)"
download o "authority/export?tab=all&kind=grievances&format=csv"; hasnt "a non-Director asking for 'all' only gets their own list" "$REF1" "$(cat $T/dl)"
download x "authority/export?tab=all&kind=grievances&format=xlsx"; CELLS=$(xlsx_cells $T/dl)
has "Director exports everything (1)" "$REF1" "$CELLS"; has "Director exports everything (3)" "$REF3" "$CELLS"
has "authority export audited with its tab" "authority:all" "$(sql "select after_data from audit_log where action='export' order by id desc limit 1")"
check "admin cannot use the authority export" 403 "$(code -b $T/a.jar "$B/authority/export?tab=action&kind=grievances&format=csv")"

echo "--- report"
R=$(get a admin/report); has "admin report page" "Summary report" "$R"
has "report counts by type" 'id="by-type"' "$R"; has "report open-by-post table" "Head of Department (Computer Science and Engineering (CSE))" "$R"
has "report by month" 'id="by-month"' "$R"
download a "admin/report/xlsx"; has "report Excel download" "grievance-report-" "$(cat $T/dl.h)"
R=$(get x authority); has "Director has a report link" 'id="report-link"' "$R"
check "Director opens the report" 200 "$(code -b $T/x.jar $B/authority/report)"
check "HOD cannot open the report" 404 "$(code -b $T/h.jar $B/authority/report)"
R=$(get h authority); hasnt "HOD has no report link" 'id="report-link"' "$R"
R=$(get a "admin/report?from=2020-01-01&to=2020-01-31"); has "empty period shows nothing" "Nothing in this period" "$R"

echo "--- archive"
R=$(get a admin/archive); has "nothing eligible yet" 'id="nothing-eligible"' "$R"
R=$(get a admin/grievances/$G1); has "archive button on a disposed grievance" "possible once disposed more than 3 year(s) ago" "$R"
post a admin/grievances/$G1/archive admin/grievances/$G1; R=$(get a admin/grievances/$G1)
has "too recent to archive" "less than 3 year(s) ago" "$R"
check "archive without CSRF is rejected" 403 "$(code -b $T/a.jar -X POST $B/admin/grievances/$G1/archive)"
sql "update grievances set disposed_at = now() - interval '4 years' where id=$G1" >/dev/null
R=$(get a admin/archive); has "one eligible" "Archive 1 grievance(s)" "$R"; has "preview lists it" "$REF1" "$R"
post a admin/archive admin/archive expected=1; R=$(get a admin/archive)
has "bulk archive done" "1 grievance(s) archived" "$R"
check "archived in the database" t "$(sql "select archived from grievances where id=$G1")"
R=$(get a admin/grievances); hasnt "archived hidden from the default list" "$REF1" "$R"
R=$(get a "admin/grievances?archived=yes"); has "found with the Archived filter" "$REF1" "$R"
R=$(get h "authority?tab=earlier"); hasnt "archived gone from the authority's list" "$REF1" "$R"
as_person t T7S001 >/dev/null
R=$(get t my); has "submitter still sees it in My grievances" "$REF1" "$R"; has "marked as archived in the list" "Archived" "$R"
GA=$(sql "select id from grievances where reference_no='$REF1'")
R=$(get t my/$GA); has "submitter can still open it" "$REF1" "$R"; has "archived note on the grievance page" 'id="archived-note"' "$R"
R=$(get a admin/grievances/$G1); has "archived badge on the detail page" 'id="archived-badge"' "$R"
post a admin/grievances/$G1/restore admin/grievances/$G1; R=$(get a admin/grievances/$G1)
has "restored" "has been restored from the archive" "$R"
check "restore in the database" f "$(sql "select archived from grievances where id=$G1")"
post a admin/grievances/$G1/archive admin/grievances/$G1; check "single archive of an eligible grievance" t "$(sql "select archived from grievances where id=$G1")"
check "archive/restore audited" 3 "$(sql "select count(*) from audit_log where action in ('archive','restore') and entity='grievance'")"
check "nothing deleted" "2|1" "$(sql "select count(*) from grievance_actions where grievance_id=$G1")|$(sql "select count(*) from attachments where grievance_id=$G1")"

echo
echo "Passed: $pass   Failed: $fail"
rm -rf $T
[ $fail -eq 0 ]
