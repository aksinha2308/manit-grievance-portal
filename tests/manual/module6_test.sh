#!/bin/bash
# End-to-end checks for module 6 (My grievances + reply, Director oversight, related-grievance banner,
# authority dashboard filters and per-post counts). Needs a FRESH migrated + seeded database, the admin created,
# and the dev server running on port 8080 against that database:
#   bash tests/manual/module6_test.sh '<admin temporary password>'
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
# submission: sign in as the master-list person (test login), then submit with the OTP from the log
begin() { as_person "$1" "$3" >/dev/null; }
fillform() { local jar=$1; shift 2; submit_as $jar "$@"; }
submit_student() { rm -f $T/s.jar; begin s student "$1" >/dev/null; shift; fillform s student "$@" >/dev/null; sql "select max(id) from grievances"; }
# reply <jar> <grievance id> <text> [attachments[]=@file;filename=x ...]  -> prints the redirect location
reply() { local jar=$1 gid=$2 text=$3; shift 3; local tok=$(get $jar my/$gid | token); local args=(-F "csrf_test_name=$tok" --form-string "reply=$text")
  for f in "$@"; do args+=(-F "$f"); done
  curl -s -o /dev/null -w "%{redirect_url}" -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" $B/my/$gid/reply; }

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
for u in hod.ce:'Dr. Civil Hodname' hod.cse:'Dr. CSE Hodname' dean.acad:'Dean Deanname' director:'Director Dirname'; do
  sql "insert into users(username,full_name,password_hash,must_change_password) values ('${u%%:*}','${u#*:}','$HASH',false)" >/dev/null; done
uid() { sql "select id from users where username='$1'"; }
did() { sql "select id from posts where name='$1'"; }
HODD=$(did 'Head of Department')
# hod.ce holds two posts (HOD CE and HOD CSE) to show per-post counts; hod.cse has no post at first
sql "insert into post_holders(post_id,scope_id,user_id) values
 ($HODD,$CE,$(uid hod.ce)),($HODD,$CSE,$(uid hod.ce)),
 ($(did 'Dean (Academics)'),NULL,$(uid dean.acad)),
 ($(sql 'select id from posts where is_director'),NULL,$(uid director))" >/dev/null
sql "insert into master_people(role,id_code,name,department_id,semester_id,hostel_id,contact) values
 ('student','T6S001','Ravi Civil',$CE,$SEM,NULL,'9876500011'),('student','T6S002','Meera Computer',$CSE,$SEM,NULL,'9876500012')" >/dev/null
ACD=$(sql "select id from grievance_types where code='ACD'"); OTH=$(sql "select id from grievance_types where code='OTH'")
printf '%%PDF-1.4\n%%EOF\n' > $T/mine.pdf
printf '%%PDF-1.4\n%% reply\n%%EOF\n' > $T/reply.pdf
G1=$(submit_student T6S001 type_id=$ACD contact=9876500011 "subject=Lab marks missing" "description=CE-301 lab marks" "attachments[]=@$T/mine.pdf;filename=mine.pdf")
G2=$(submit_student T6S001 type_id=$OTH contact=9876500011 "subject=Library card <b>blocked</b>" "description=Card blocked")
G3=$(submit_student T6S002 type_id=$ACD contact=9876500012 "subject=Attendance shortage" "description=Wrongly marked")
REF1=$(sql "select reference_no from grievances where id=$G1"); REF2=$(sql "select reference_no from grievances where id=$G2"); REF3=$(sql "select reference_no from grievances where id=$G3")
check "setup: three grievances" 3 "$(sql 'select count(*) from grievances')"

echo "--- My grievances (replaces tracking by reference number)"
check "old tracking page is gone" 404 "$(code $B/track)"
check "My grievances needs sign-in" "$B/login" "$(curl -s -o /dev/null -w '%{redirect_url}' $B/my)"
as_person t T6S001 >/dev/null
R=$(get t my)
has "lists my first grievance" "$REF1" "$R"; has "lists my second grievance" "$REF2" "$R"
hasnt "does not list another student's grievance" "$REF3" "$R"
has "subject escaped in my list" "Library card &lt;b&gt;blocked" "$R"
has "status shown with the post" "Under review · Head of Department (Civil Engineering (CE))" "$R"
check "another student's grievance is 404" 404 "$(code -b $T/t.jar -c $T/t.jar $B/my/$G3)"
check "a grievance that does not exist is 404" 404 "$(code -b $T/t.jar -c $T/t.jar $B/my/999999)"
check "reply without CSRF is rejected" 403 "$(code -b $T/t.jar -d 'reply=x' $B/my/$G1/reply)"
as_person m T6S002 >/dev/null
R=$(get m my); has "the other student sees only theirs" "$REF3" "$R"; hasnt "and not mine" "$REF1" "$R"
rm -f $T/r.jar; begin r student T6S001 >/dev/null
L=$(submit_as r type_id=$ACD contact=9876500011 subject=Receipt description=Receipt confirm_duplicate=1); R=$(get r submit/done)
has "receipt offers print" "Print / save receipt" "$R"
GR=$(sql "select id from grievances where subject='Receipt'")
has "receipt links to the grievance" "my/$GR" "$R"

echo "--- what the submitter sees"
R=$(get t my/$G1)
has "reference and status" "Under review" "$R"
has "now with the post" "Head of Department (Civil Engineering (CE))" "$R"
has "own attachment listed" "mine.pdf" "$R"
hasnt "no reply form without a question" 'id="reply-panel"' "$R"
AID=$(sql "select id from attachments where grievance_id=$G1")
curl -s -b $T/t.jar -c $T/t.jar -D $T/h -o $T/dl $B/my/$G1/attachments/$AID
check "own attachment downloads intact" "$(md5sum < $T/mine.pdf)" "$(md5sum < $T/dl)"
has "served as a download" 'attachment; filename="mine.pdf"' "$(cat $T/h)"
check "attachment of another grievance is 404" 404 "$(code -b $T/t.jar $B/my/$G1/attachments/999999)"
check "my attachment through another student's grievance is 404" 404 "$(code -b $T/t.jar $B/my/$G3/attachments/$AID)"
check "another student cannot download it" 404 "$(code -b $T/m.jar $B/my/$G1/attachments/$AID)"
check "attachment without sign-in redirects to login" 302 "$(code $B/my/$G1/attachments/$AID)"

echo "--- question and reply"
login h hod.ce 'Auth0rity#Pass'
R=$(get h authority/grievances/$G1); has "authority told the remark is visible to the submitter" "The submitter can read this remark" "$R"
act h $G1 request_info "Which lab session was missed?" >/dev/null
R=$(get t my/$G1)
has "status: waiting for your reply" "Waiting for your reply" "$R"
has "reply form shown" 'id="reply-panel"' "$R"
has "the question is shown" "Which lab session was missed?" "$R"
has "asked by the post" "Question from Head of Department (Civil Engineering (CE))" "$R"
hasnt "authority's name never shown" "Hodname" "$R"
L=$(reply t $G1 "   "); R=$(get t my/$G1)
has "empty reply refused" "Your reply is required" "$R"
check "still waiting" awaiting_submitter "$(sql "select status from grievances where id=$G1")"
L=$(reply t $G1 "Session of 12 October." "attachments[]=@$T/reply.pdf;filename=reply.pdf"); R=$(get t my/$G1)
check "reply returns to the grievance" "$B/my/$G1" "$L"
has "reply confirmation" "Your reply has been sent to Head of Department (Civil Engineering (CE))." "$R"
check "back to pending" pending "$(sql "select status from grievances where id=$G1")"
has "history shows your reply" "Your reply" "$R"
has "reply attachment listed" "reply.pdf" "$R"
hasnt "reply form gone" 'id="reply-panel"' "$R"
L=$(reply t $G1 "again"); R=$(get t my/$G1); has "a second reply is refused" "not waiting for a reply" "$R"
R=$(get h "authority"); has "authority sees the Replied badge" ">Replied<" "$R"
R=$(get h authority/grievances/$G1); has "authority sees the reply" "Session of 12 October." "$R"

echo "--- late answer after escalation"
act h $G1 request_info "Name of the lab instructor?" >/dev/null
act h $G1 escalate "Needs the Dean." >/dev/null
R=$(get t my/$G1)
has "late answer offered" "You can still answer" "$R"
has "explains where the answer goes" "moved on to" "$R"
has "escalation remark visible to the submitter" "Needs the Dean." "$R"
has "escalation destination shown" "Transferred to Dean (Academics)" "$R"
L=$(reply t $G1 "Dr. Rao."); R=$(get t my/$G1)
has "late answer confirmation" "has been added to your grievance for Dean (Academics)" "$R"
check "late answer recorded" late_reply "$(sql "select action from grievance_actions where grievance_id=$G1 order by id desc limit 1")"
login d dean.acad 'Auth0rity#Pass'
R=$(get d authority); has "Dean sees the Late reply badge" ">Late reply<" "$R"

echo "--- idle expiry"
sql "update settings set value='0' where key='session_idle_minutes'" >/dev/null
as_person ti T6S001 >/dev/null; sleep 2
check "idle session expires" "$B/login" "$(curl -s -o /dev/null -w '%{redirect_url}' -b $T/ti.jar -c $T/ti.jar $B/my/$G1)"
sql "update settings set value='30' where key='session_idle_minutes'" >/dev/null

echo "--- disposed"
login x director 'Auth0rity#Pass'
act d $G1 escalate "For the committee." >/dev/null
sql "insert into users(username,full_name,password_hash,must_change_password) values ('chair','Chair Chairname','$HASH',false)" >/dev/null
sql "insert into post_holders(post_id,scope_id,user_id) values ($(did 'Chairman, Student Grievance Committee (Academic)'),NULL,$(uid chair))" >/dev/null
login c2 chair 'Auth0rity#Pass'; act c2 $G1 escalate "To the Director." >/dev/null
act x $G1 dispose "Marks corrected." >/dev/null
R=$(get t my/$G1)
has "disposed status shown" ">Disposed<" "$R"
has "final decision visible" "Marks corrected." "$R"
has "no-appeal guidance" "submit a new grievance" "$R"
hasnt "no reply form after disposal" 'id="reply-panel"' "$R"
for n in Hodname Deanname Dirname Chairname; do hasnt "no authority name on the grievance page ($n)" "$n" "$R"; done
R=$(get t my); has "list shows it as disposed" ">Disposed<" "$R"

echo "--- Director oversight"
R=$(get x authority)
has "Director has the All grievances tab" "All grievances" "$R"
R=$(get x "authority?tab=all")
has "all tab lists a grievance still at an HOD" "$REF3" "$R"
R=$(get x authority/grievances/$G3)
has "Director can open it" "$REF3" "$R"
hasnt "but has no action panel" 'id="action-panel"' "$R"
has "view-only note" "Director oversight" "$R"
L=$(act x $G3 dispose "not mine"); check "Director's action on it changes nothing" pending "$(sql "select status from grievances where id=$G3")"
has "Director told why" "cannot act on it" "$(get x authority/grievances/$G3)"
R=$(get h "authority?tab=all"); hasnt "HOD has no All tab" "All grievances" "$R"

echo "--- related grievances banner"
# "Others" grievances go to Dean (Student Welfare), so the HOD cannot open G2: it must appear as a summary only
G4=$(submit_student T6S001 type_id=$ACD contact=9876500011 "subject=Exam hall seating" "description=Seat clash" confirm_duplicate=1)
R=$(get h authority/grievances/$G4)
has "banner shown" 'id="related"' "$R"
has "banner counts the other grievances" "This person has 3 other grievance(s) in the last 12 months" "$R"
has "a grievance the HOD handled is a link" "authority/grievances/$G1\">$REF1" "$R"
has "a grievance the HOD cannot open is listed" "$REF2" "$R"
hasnt "but without a link" "authority/grievances/$G2\"" "$R"
hasnt "and without its subject" "Library card" "$R"
has "same-type marker" "same type" "$R"
R=$(get h authority/grievances/$G3); hasnt "no banner for a single grievance" 'id="related"' "$R"

echo "--- filters and per-post counts"
R=$(get h authority); has "per-post counts for a two-post holder" 'id="post-counts"' "$R"
has "CSE post card" "Head of Department (Computer Science and Engineering (CSE))" "$R"
R=$(get h "authority?q=meera"); has "search by name" "$REF3" "$R"; hasnt "search excludes others" "$REF2" "$R"
R=$(get x "authority?tab=all&q=${REF2: -10}"); has "search by reference (Director, all)" "$REF2" "$R"; hasnt "search by reference excludes" "$REF3" "$R"
R=$(get x "authority?tab=all&type=$OTH"); has "type filter" "$REF2" "$R"; hasnt "type filter excludes" "$REF3" "$R"
sql "update grievances set level_entered_at = now() - interval '9 days' where id=$G3" >/dev/null
R=$(get h "authority?overdue=1"); has "overdue filter" "$REF3" "$R"; hasnt "overdue filter excludes" "$REF2" "$R"
R=$(get h "authority?q=zzzz"); has "no match message" "No grievance matches these filters" "$R"
has "subject escaped in lists" "Library card &lt;b&gt;blocked" "$(get x 'authority?tab=all')"

echo
echo "Passed: $pass   Failed: $fail"
rm -rf $T
[ $fail -eq 0 ]
