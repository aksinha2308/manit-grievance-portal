#!/bin/bash
# End-to-end checks for module 5 (workflow engine: authority inbox, grievance page, actions, attachments,
# admin warnings, overdue reminders). Needs a FRESH migrated + seeded database, the admin created, and the
# dev server running on port 8080 against that database:
#   bash tests/manual/module5_test.sh '<admin temporary password>'
# Run it from the project root. It writes test grievances and users and cannot clean them up (the action
# trail is append-only by design), so never run it against a database you care about.
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
# act <jar> <grievance id> <action> <remark> [version] [confirm_waiting]  -> prints the redirect location
act() { local tok=$(get $1 authority/grievances/$2 | token); [ -z "$tok" ] && tok=$(get $1 authority | token)
  local args=(--data-urlencode "csrf_test_name=$tok" --data-urlencode "action=$3" --data-urlencode "remark=$4")
  [ -n "$5" ] && args+=(--data-urlencode "version=$5")
  [ -n "$6" ] && args+=(--data-urlencode "confirm_waiting=$6")
  curl -s -o /dev/null -w "%{redirect_url}" -c $T/$1.jar -b $T/$1.jar "${args[@]}" $B/authority/grievances/$2/act; }
version() { sql "select max(id) from grievance_actions where grievance_id=$1"; }
status()  { sql "select status from grievances where id=$1"; }
# submission: sign in as the master-list person (test login), then submit with the OTP from the log
begin() { as_person "$1" "$3" >/dev/null; }
fillform() { local jar=$1; shift 2; submit_as $jar "$@"; }
submit_student() { rm -f $T/s.jar; begin s student "$1" >/dev/null; shift; fillform s student "$@" >/dev/null; sql "select max(id) from grievances"; }

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
for u in hod.ce:'Dr. Civil HOD' hod.cse:'Dr. CSE HOD' dean.acad:'Dean Academics' chair.sgc:'Chair SGC' director:'The Director' newhod.ce:'Dr. New HOD'; do
  sql "insert into users(username,full_name,password_hash,must_change_password) values ('${u%%:*}','${u#*:}','$HASH',false)" >/dev/null; done
uid() { sql "select id from users where username='$1'"; }
did() { sql "select id from posts where name='$1'"; }
HODD=$(did 'Head of Department')
sql "insert into post_holders(post_id,scope_id,user_id) values
 ($HODD,$CE,$(uid hod.ce)),($HODD,$CSE,$(uid hod.cse)),
 ($(did 'Dean (Academics)'),NULL,$(uid dean.acad)),
 ($(did 'Chairman, Student Grievance Committee (Academic)'),NULL,$(uid chair.sgc)),
 ($(sql 'select id from posts where is_director'),NULL,$(uid director))" >/dev/null
sql "insert into master_people(role,id_code,name,department_id,semester_id,hostel_id,contact) values
 ('student','T5S001','Ravi Civil',$CE,$SEM,NULL,'9876500011'),('student','T5S002','Sana Civil',$CE,$SEM,NULL,'9876500012')" >/dev/null
ACD=$(sql "select id from grievance_types where code='ACD'")
printf '%%PDF-1.4\n%%EOF\n' > $T/proof.pdf
G1=$(submit_student T5S001 type_id=$ACD contact=9876500011 "subject=Marks <script>alert(1)</script> missing" "description=Line one
Line two" "attachments[]=@$T/proof.pdf;filename=proof.pdf")
G2=$(submit_student T5S002 type_id=$ACD contact=9876500012 subject="Attendance" description="Shortage marked wrongly")
REF1=$(sql "select reference_no from grievances where id=$G1")
check "setup: two grievances at HOD (CE)" "2" "$(sql "select count(*) from grievances g join escalation_levels l on l.id=g.current_level_id where l.level_no=1")"

echo "--- access"
login h hod.ce 'Auth0rity#Pass'; login o hod.cse 'Auth0rity#Pass'; login d dean.acad 'Auth0rity#Pass'
login c chair.sgc 'Auth0rity#Pass'; login x director 'Auth0rity#Pass'
R=$(get h authority)
has "HOD inbox lists the grievance" "$REF1" "$R"
has "inbox shows the post held" "Head of Department (Civil Engineering (CE))" "$R"
has "needs-action count is 2" 'Needs action <span class="badge text-bg-primary">2</span>' "$R"
has "subject is escaped in the list" "Marks &lt;script&gt;" "$R"
hasnt "no raw script tag" "<script>alert(1)" "$R"
R=$(get o authority); hasnt "other department's HOD does not see it" "$REF1" "$R"
check "other HOD gets 404 on the page" 404 "$(code -b $T/o.jar $B/authority/grievances/$G1)"
check "a later level (Dean) gets 404 for now" 404 "$(code -b $T/d.jar $B/authority/grievances/$G1)"
check "not logged in: redirected to login" 302 "$(code $B/authority/grievances/$G1)"
check "admin cannot open authority pages" 403 "$(code -b $T/a.jar $B/authority/grievances/$G1)"
check "unknown grievance id is 404" 404 "$(code -b $T/h.jar $B/authority/grievances/999999)"
R=$(get h authority/grievances/$G1)
has "page shows the reference" "$REF1" "$R"
has "page shows the submitter contact" "9876500011" "$R"
has "page shows the description" "Line two" "$R"
has "page shows the attachment" "proof.pdf" "$R"
has "action panel shown to the holder" 'id="action-panel"' "$R"
has "escalate names the next post" "send to Dean (Academics)" "$R"
has "subject escaped on the page" "Marks &lt;script&gt;alert(1)&lt;/script&gt; missing" "$R"

echo "--- attachments"
AID=$(sql "select id from attachments where grievance_id=$G1")
curl -s -b $T/h.jar -D $T/att.h -o $T/att.bin $B/authority/grievances/$G1/attachments/$AID
check "holder downloads the attachment intact" "$(md5sum < $T/proof.pdf)" "$(md5sum < $T/att.bin)"
has "served as a download" "attachment; filename=\"proof.pdf\"" "$(cat $T/att.h)"
has "nosniff header" "X-Content-Type-Options: nosniff" "$(cat $T/att.h)"
check "other HOD cannot download it" 404 "$(code -b $T/o.jar $B/authority/grievances/$G1/attachments/$AID)"
check "attachment id of another grievance is 404" 404 "$(code -b $T/h.jar $B/authority/grievances/$G2/attachments/$AID)"
check "attachments are not reachable in the web root" 404 "$(code $B/../writable/attachments/)"

echo "--- validation"
check "action without CSRF is rejected" 403 "$(code -b $T/h.jar -d 'action=dispose&remark=x' $B/authority/grievances/$G1/act)"
L=$(act h $G1 dispose "   "); R=$(get h authority/grievances/$G1)
check "blank remark: back to the page" "$B/authority/grievances/$G1" "$L"
has "blank remark message" "A remark is required" "$R"
check "blank remark changed nothing" pending "$(status $G1)"
L=$(act h $G1 bogus "x"); R=$(get h authority/grievances/$G1); has "unknown action refused" "Choose what to do" "$R"
L=$(act o $G1 dispose "not mine"); check "other HOD's POST is 404 (redirect none)" "" "$L"
check "other HOD changed nothing" pending "$(status $G1)"
OLD=$(version $G1)
L=$(act h $G1 request_info "Which semester's marks?" $OLD)
check "request info: back to the inbox" "$B/authority" "$L"
check "request info: awaiting submitter" awaiting_submitter "$(status $G1)"
R=$(get h authority); has "success message" "Information has been requested" "$R"
R=$(get h "authority?tab=waiting"); has "listed under waiting" "$REF1" "$R"
L=$(act h $G1 escalate "stale page" $OLD); R=$(get h authority/grievances/$G1)
has "stale page refused" "was updated while you were looking" "$R"
check "stale page changed nothing" awaiting_submitter "$(status $G1)"
has "page explains the wait" "Waiting for the submitter" "$R"
has "page explains what escalating now means" "the question is withdrawn" "$R"
has "confirmation tick-box shown while waiting" 'id="confirm-waiting"' "$R"
has "request-info option disabled while waiting" 'id="a-info" value="request_info" disabled' "$R"
has "history shows the question" "Which semester&#039;s marks?" "$R"

echo "--- escalate up the route"
L=$(act h $G1 escalate "Needs the Dean's decision." $(version $G1)); R=$(get h authority/grievances/$G1)
has "escalating while waiting without the tick-box is refused" "has not replied to your question yet" "$R"
check "refusal changed nothing" awaiting_submitter "$(status $G1)"
has "typed remark is kept after the refusal" "Needs the Dean&#039;s decision." "$R"
L=$(act h $G1 escalate "Needs the Dean's decision." $(version $G1) 1)
check "escalated with the tick-box (allowed while waiting)" "pending|Dean (Academics)" "$(status $G1)|$(sql "select d.name from grievances g join escalation_levels l on l.id=g.current_level_id join posts d on d.id=l.post_id where g.id=$G1")"
R=$(get h authority/grievances/$G1)
hasnt "no tick-box once it is no longer waiting" 'id="confirm-waiting"' "$(get d authority/grievances/$G1)"
hasnt "HOD: no action panel any more" 'id="action-panel"' "$R"
has "HOD: told it is view-only" "no longer at a post you hold" "$R"
has "history shows the destination" "to <strong>Dean (Academics)</strong>" "$R"
R=$(get h "authority?tab=earlier"); has "HOD: in the earlier tab" "$REF1" "$R"
L=$(act h $G1 dispose "too late"); R=$(get h authority/grievances/$G1); has "HOD cannot act after escalating" "no longer at a post you hold, so you cannot act" "$R"
R=$(get d authority); has "Dean now has it" "$REF1" "$R"
act d $G1 escalate "Committee to review." >/dev/null
act c $G1 escalate "For the Director." >/dev/null
R=$(get x authority/grievances/$G1)
has "Director: escalate not possible" "not possible: this is the last level" "$R"
has "Director: escalate option disabled" 'value="escalate" disabled' "$R"
L=$(act x $G1 escalate "beyond"); R=$(get x authority/grievances/$G1); has "escalating past the Director refused" "last level of the route" "$R"
L=$(act x $G1 dispose "Marks corrected by the exam cell.")
check "Director disposed it" disposed "$(status $G1)"
R=$(get x authority/grievances/$G1)
has "disposed page shows the decision" "Marks corrected by the exam cell." "$R"
hasnt "no action panel when disposed" 'id="action-panel"' "$R"
check "history order" "submit,request_info,escalate,escalate,escalate,dispose" "$(sql "select string_agg(action, ',' order by id) from grievance_actions where grievance_id=$G1")"
check "notification events" "arrived_at_level:4,disposed:1,escalated:3,info_requested:1,submitted:1" "$(sql "select string_agg(event_type||':'||n, ',' order by event_type) from (select event_type, count(*) n from notification_events where grievance_id=$G1 group by 1) x")"

echo "--- access follows the post; vacant posts"
sql "update post_holders set active=false, ended_at=now() where post_id=$HODD and scope_id=$CE and active" >/dev/null
R=$(get a admin)
has "admin warned about the grievance at a vacant post" 'id="stuck"' "$R"
has "stuck list names the post" "Head of Department (Civil Engineering (CE))" "$R"
has "stuck list names the grievance" "$(sql "select reference_no from grievances where id=$G2")" "$R"
check "former HOD loses the page" 404 "$(code -b $T/h.jar $B/authority/grievances/$G2)"
R=$(get h authority); hasnt "former HOD's inbox is empty" "$(sql "select reference_no from grievances where id=$G2")" "$R"
sql "insert into post_holders(post_id,scope_id,user_id) values ($HODD,$CE,$(uid newhod.ce))" >/dev/null
login n newhod.ce 'Auth0rity#Pass'
R=$(get n authority); has "new HOD sees the pending grievance" "$(sql "select reference_no from grievances where id=$G2")" "$R"
check "new HOD can open the earlier grievance of the post" 200 "$(code -b $T/n.jar $B/authority/grievances/$G1)"
R=$(get a admin); hasnt "warning gone once filled" 'id="stuck"' "$R"

echo "--- overdue"
sql "update grievances set level_entered_at = now() - interval '9 days' where id=$G2" >/dev/null
R=$(get n authority)
has "overdue banner" 'id="overdue-banner"' "$R"
has "overdue badge in the list" "Overdue" "$R"
R=$(get a admin); has "admin counts the overdue grievance" "Overdue" "$R"
OUT=$(php spark portal:reminders)
has "reminder command logs one" "1 overdue reminder(s) logged" "$OUT"
OUT=$(php spark portal:reminders)
has "running it again logs none" "No new overdue reminders" "$OUT"
act n $G2 request_info "Please share the attendance sheet." >/dev/null
R=$(get n "authority?tab=waiting"); hasnt "waiting grievances are not shown overdue" "table-danger" "$R"
act n $G2 dispose "Closed: no reply after reminder." "" 1 >/dev/null
check "dispose while waiting clears the pause" "disposed|" "$(status $G2)|$(sql "select paused_since from grievances where id=$G2")"

echo
echo "Passed: $pass   Failed: $fail"
rm -rf $T
[ $fail -eq 0 ]
