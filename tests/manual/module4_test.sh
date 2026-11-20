#!/bin/bash
# End-to-end checks for module 4 (grievance submission; since module 7A: Google sign-in + OTP). Needs a FRESH migrated + seeded database, the admin
# created, and the dev server running on port 8080 against that database:
#   bash tests/manual/module4_test.sh '<admin temporary password>'
# Run it from the project root. It writes test grievances and cannot clean them up (the action trail is
# append-only by design), so never run it against a database you care about.
B=http://localhost:8080
T=$(mktemp -d)
export PGPASSWORD=${PGPASSWORD:-grievance_dev}
PSQL="psql -q -t -A -h 127.0.0.1 -U grievance ${DBNAME:-grievance_db}"
PREFLIGHT_ARG="$1"; source "$(dirname "$0")/_preflight.sh"
pass=0; fail=0
check() { if [ "$2" == "$3" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi; }
has()   { if grep -qF -- "$2" <<<"$3"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (missing '$2')"; fail=$((fail+1)); fi; }
hasnt() { if grep -qF -- "$2" <<<"$3"; then echo "FAIL  $1 (unexpected '$2')"; fail=$((fail+1)); else echo "PASS  $1"; pass=$((pass+1)); fi; }
matches() { if grep -qE -- "$2" <<<"$3"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 ('$3' !~ $2)"; fail=$((fail+1)); fi; }
token() { grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code()  { curl -s -o /dev/null -w "%{http_code}" "$@"; }
sql()   { $PSQL -c "$1"; }
get()   { curl -s -L -c $T/$1.jar -b $T/$1.jar "$B/$2"; }
adminpost() { local tok=$(get a admin | token); local args=(--data-urlencode "csrf_test_name=$tok"); local path=$1; shift
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done; curl -s -o /dev/null -c $T/a.jar -b $T/a.jar "${args[@]}" "$B/$path"; }

# ---- helpers for the submission flow ---------------------------------------------------------------
# begin <jar> <slug> <id code>  -> signs in (test login) as the master-list person with that ID; prints "status redirect"
begin() { local email=$(sql "select email from master_people where lower(id_code)=lower('$3')"); echo "$email" > $T/$1.email
  devlogin $1 "${email:-unknown-$3@stu.manit.ac.in}"; }
# sendotp <jar>  -> asks for an OTP (keeps nothing typed); prints the redirect location
sendotp() { local tok=$(get $1 submit | token)
  curl -s -o /dev/null -w "%{redirect_url}" -c $T/$1.jar -b $T/$1.jar --data-urlencode "csrf_test_name=$tok" $B/submit/otp; }
# post_form <jar> <curl -w format> [follow] fields...  (sends an OTP first and adds it, unless an otp= field is given)
post_form() { local jar=$1 fmt=$2; shift 2; local follow=(); [ "$1" = "-L" ] && { follow=(-L); shift; }
  local args=() hasotp=no
  for kv in "$@"; do [[ "$kv" == otp=* ]] && hasotp=yes; done
  if [ $hasotp = no ]; then sendotp $jar >/dev/null; args+=(--form-string "otp=$(otp_code "$(cat $T/$jar.email 2>/dev/null)")"); fi
  local tok=$(get $jar submit | token); args+=(-F "csrf_test_name=$tok")
  for kv in "$@"; do if [[ "$kv" == attachments* ]]; then args+=(-F "$kv"); else args+=(--form-string "$kv"); fi; done
  curl -s "${follow[@]}" -o "$( [ ${#follow[@]} -eq 0 ] && echo /dev/null || echo - )" -w "$fmt" -c $T/$jar.jar -b $T/$jar.jar "${args[@]}" $B/submit; }
# fillform <jar> <slug> fields...  -> prints the redirect location
fillform() { local jar=$1; shift 2; post_form $jar "%{redirect_url}" "$@"; }
# show <jar> <slug> fields...  -> prints the page shown after the submit (follows the redirect)
show() { local jar=$1; shift 2; post_form $jar "" -L "$@"; }
gcount() { sql "select count(*) from grievances"; }

TEMP="$1"
echo "--- admin setup"
rm -f $T/a.jar; tok=$(curl -s -c $T/a.jar -b $T/a.jar $B/login | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&username=admin&password=$TEMP" $B/login
tok=$(get a account/password | token)
curl -s -o /dev/null -c $T/a.jar -b $T/a.jar -d "csrf_test_name=$tok&current_password=$TEMP&new_password=NewAdminPass1&confirm_password=NewAdminPass1" $B/account/password
adminpost admin/lists/departments/add for_student=1 for_faculty=1 name="Computer Science"
adminpost admin/lists/hostels/add name="Hostel A"
D=$(sql "select id from departments where name='Computer Science'"); H=$(sql "select id from hostels where name='Hostel A'"); SEM=$(sql "select id from semesters order by id limit 1 offset 2")
SEMNAME=$(sql "select name from semesters where id=$SEM")
sql "insert into master_people(role,id_code,name,department_id,semester_id,hostel_id,contact,email) values
 ('student','T4S001','Asha Verma',$D,$SEM,$H,'9876543210','asha@stu.manit.ac.in'),
 ('student','T4S002','Day Scholar Dev',$D,$SEM,NULL,'9876500002','dev@stu.manit.ac.in'),
 ('student','T4S003','Inactive Ishan',$D,$SEM,NULL,'9876500003','ishan@stu.manit.ac.in'),
 ('faculty','T4F001','Dr. Fiona Faculty',$D,NULL,NULL,'9876500004','fiona@manit.ac.in'),
 ('non_teaching','T4N001','Nikhil Staff',$D,NULL,NULL,'9876500005','nikhil@manit.ac.in')" >/dev/null
sql "update master_people set active=false where id_code='T4S003'" >/dev/null
otp_no_wait
check "setup: 5 people" 5 "$(sql 'select count(*) from master_people')"

echo "--- sign-in and access"
R=$(get x "")
has "home asks visitors to sign in" "Sign in to continue" "$R"
hasnt "home no longer offers tracking by reference" 'href="'"$B"'/track"' "$R"
check "submit page needs sign-in" "302" "$(code $B/submit)"
check "old per-role submit pages are gone" 404 "$(code $B/submit/student)"
check "old tracking page is gone" 404 "$(code $B/track)"
check "old captcha image is gone" 404 "$(code $B/captcha)"
check "a deactivated person cannot sign in" "302 $B/login" "$(begin z student T4S003)"
check "student signs in" "302 $B/my" "$(begin s student T4S001)"
R=$(get s ""); has "home offers submission once signed in" 'href="'"$B"'/submit"' "$R"; has "home offers My grievances" 'href="'"$B"'/my"' "$R"
check "GET /submit is 200" 200 "$(code -c $T/s.jar -b $T/s.jar $B/submit)"
check "receipt without a submission redirects" 302 "$(code -c $T/s.jar -b $T/s.jar $B/submit/done)"
check "POST form without CSRF is rejected" 403 "$(code -d 'subject=x' $B/submit)"
check "POST send-OTP without CSRF is rejected" 403 "$(code -b $T/s.jar -d 'x=1' $B/submit/otp)"

echo "--- OTP"
rm -f writable/logs/otp-*.log
R=$(get s submit)
has "OTP box shown" 'id="otp-card"' "$R"; has "OTP goes to the masked e-mail" "a***@stu.manit.ac.in" "$R"; has "OTP goes to the masked master-list mobile" "******3210" "$R"
N0=$(gcount)
R=$(show s student type_id=1 contact=9876543210 subject="No OTP" description="Body" otp=)
has "submitting without an OTP is refused" "Enter the 6-digit OTP" "$R"
has "typed text kept after the OTP error" 'value="No OTP"' "$R"
tok=$(get s submit | token)
J=$(curl -s -c $T/s.jar -b $T/s.jar -H "X-Requested-With: XMLHttpRequest" --data-urlencode "csrf_test_name=$tok" $B/submit/otp)
matches "background Send OTP answers with JSON (form and files stay on the page)" '"ok": ?true' "$J"
has "background answer names e-mail and mobile" 'a***@stu.manit.ac.in and your mobile ******3210' "$J"
NEWTOK=$(tr -d '\n' <<<"$J" | sed -n 's/.*"csrf": *"\([^"]*\)".*/\1/p'); matches "background answer carries the next CSRF token" '^[0-9a-f]{32}$' "$NEWTOK"
CA=$(otp_code asha@stu.manit.ac.in); matches "background OTP written to the log" '^[0-9]{6}$' "$CA"
J=$(curl -s -c $T/s.jar -b $T/s.jar -H "X-Requested-With: XMLHttpRequest" --data-urlencode "csrf_test_name=$NEWTOK" $B/submit/otp)
matches "the new token works for the next background request" '"ok": ?(true|false)' "$J"
L=$(sendotp s); check "Send OTP without JavaScript returns to the form" "$B/submit" "$L"
R=$(get s submit); has "OTP sent message names e-mail and mobile" "sent to your e-mail a***@stu.manit.ac.in and your mobile ******3210" "$R"
has "button now offers a new OTP" "Send a new OTP" "$R"
C1=$(otp_code asha@stu.manit.ac.in); matches "OTP e-mail written to the log" '^[0-9]{6}$' "$C1"
check "same OTP sent by SMS to the master-list mobile" "$C1" "$(otp_code 9876543210)"
check "only a hash is stored" 0 "$(sql "select count(*) from otp_codes where code_hash='$C1'")"
WRONG=$([ "$C1" = "000000" ] && echo 111111 || echo 000000)
R=$(show s student type_id=1 contact=9876543210 subject="Wrong OTP" description="Body" otp=$WRONG)
has "wrong OTP refused, tries left shown" "The OTP is not correct. 4 tries left." "$R"
check "nothing saved with a wrong OTP" "$N0" "$(gcount)"
R=$(show s student type_id= contact=9876543210 subject="" description="" otp=$C1)
has "form errors are still shown with a right OTP" "Subject is required" "$R"
L=$(fillform s student type_id=4 contact=9000011111 subject="OTP ok" description="Body" otp=$C1)
check "the same OTP still works after a form error" "$B/submit/done" "$L"
check "edited contact kept for the grievance only" "9000011111|9876543210" "$(sql "select g.submitter->>'contact' from grievances g where subject='OTP ok'")|$(sql "select contact from master_people where id_code='T4S001'")"
R=$(show s student type_id=4 contact=9876543210 subject="Reuse" description="Body" otp=$C1)
has "an OTP works for one grievance only" "Send OTP" "$R"; check "and nothing more was saved" $((N0+1)) "$(gcount)"
sendotp s >/dev/null; C2=$(otp_code asha@stu.manit.ac.in); sendotp s >/dev/null; C3=$(otp_code asha@stu.manit.ac.in)
if [ "$C2" != "$C3" ]; then R=$(show s student type_id=4 contact=9876543210 subject="Old OTP" description="Body" otp=$C2); has "a newer OTP replaces the older one" "not correct" "$R"; else echo "PASS  a newer OTP replaces the older one (codes happened to match)"; pass=$((pass+1)); fi
sql "update otp_codes set expires_at=now()-interval '1 second' where used_at is null" >/dev/null
R=$(show s student type_id=4 contact=9876543210 subject="Expired" description="Body" otp=$C3); has "expired OTP refused" "expired" "$R"
sendotp s >/dev/null; C4=$(otp_code asha@stu.manit.ac.in); W4=$([ "$C4" = "000000" ] && echo 111111 || echo 000000)
for i in 1 2 3 4 5; do show s student type_id=4 contact=9876543210 subject="Brute" description="Body" otp=$W4 >/dev/null; done
R=$(show s student type_id=4 contact=9876543210 subject="Brute" description="Body" otp=$C4); has "after 5 wrong entries even the right OTP is refused" "too many times" "$R"
sql "update settings set value='60' where key='otp_resend_seconds'" >/dev/null
sendotp s >/dev/null; L=$(sendotp s); R=$(get s submit); has "resend wait enforced" "Please wait" "$R"
otp_no_wait
sql "update master_people set contact=null where id_code='T4S002'" >/dev/null
begin d student T4S002 >/dev/null; sendotp d >/dev/null; R=$(get d submit)
has "no mobile on record: OTP goes to e-mail only" "sent to your e-mail d***@stu.manit.ac.in." "$R"
sql "update master_people set contact='9876500002' where id_code='T4S002'" >/dev/null
check "OTP requests are not audited as sign-ins" 0 "$(sql "select count(*) from audit_log where action='otp'")"
check "OTP rows belong to the right people" 0 "$(sql "select count(*) from otp_codes o join master_people p on p.id=o.person_id where p.id_code not in ('T4S001','T4S002')")"

echo "--- the form"
R=$(get s submit)
has "name shown" "Asha Verma" "$R"; has "scholar number shown" "T4S001" "$R"; has "department shown" "Computer Science" "$R"
has "semester shown" "$SEMNAME" "$R"; has "hostel shown" "Hostel A" "$R"; has "contact pre-filled" 'value="9876543210"' "$R"
has "all four types offered" "Hostel Related" "$R"; has "Academic offered" "Academic" "$R"
has "attachment input shown" 'name="attachments[]"' "$R"
check "details are locked (5 disabled inputs)" 5 "$(grep -o 'disabled>' <<<"$R" | wc -l)"
begin d student T4S002 >/dev/null; R=$(get d submit)
has "day scholar sees the day-scholar label" "Day scholar (no hostel)" "$R"
has "Hostel Related is blocked for a day scholar" "not available: no hostel recorded" "$R"

echo "--- validation"
N0=$(gcount)
R=$(show s student type_id= contact= subject= description=)
has "empty form: type error" "Choose the type of grievance" "$R"; has "empty form: contact error" "valid contact number" "$R"
has "empty form: subject error" "Subject is required" "$R"; has "empty form: description error" "Description is required" "$R"
R=$(show s student type_id=1 contact=12345 subject=Test description=Body)
has "short contact refused" "valid contact number" "$R"
R=$(show s student type_id=999 contact=9876543210 subject=Test description=Body); has "unknown type refused" "Choose the type of grievance" "$R"
R=$(show s student type_id=1 contact=9876543210 subject="<script>alert(1)</script>" description="")
has "typed subject kept after an error" "&lt;script&gt;alert(1)&lt;/script&gt;" "$R"; hasnt "typed subject is escaped" "<script>alert(1)</script>" "$R"
LONG=$(python3 -c "print('x'*5001)"); R=$(show s student type_id=1 contact=9876543210 subject=Test description="$LONG"); has "over-long description refused" "Description is required" "$R"
check "nothing was saved by invalid submissions" "$N0" "$(gcount)"
begin d student T4S002 >/dev/null
R=$(show d student type_id=2 contact=9876500002 subject=Hostel description=Body)
has "Hostel Related refused for a day scholar" "for hostel residents" "$R"; check "and nothing was saved" "$N0" "$(gcount)"

echo "--- successful submission"
YEAR=$(TZ=Asia/Kolkata date +%Y)
begin s student T4S001 >/dev/null
L=$(fillform s student type_id=1 contact=9111111111 subject="Marks not updated" description="My internal marks are missing." name=Hacker department=99 semester=99)
check "submission redirects to the receipt" "$B/submit/done" "$L"
R=$(get s submit/done)
has "receipt shows the reference number" "MANIT-$YEAR-ACD-000001" "$R"; has "receipt shows the type" "Academic" "$R"
has "receipt shows where it went" "Head of Department (Computer Science)" "$R"
R=$(get s submit/done); has "refreshing the receipt keeps the number" "MANIT-$YEAR-ACD-000001" "$R"
check "one grievance saved" $((N0+1)) "$(gcount)"
G="reference_no='MANIT-$YEAR-ACD-000001'"
check "status is pending" pending "$(sql "select status from grievances where $G")"
check "starts at level 1" 1 "$(sql "select l.level_no from grievances g join escalation_levels l on l.id=g.current_level_id where g.$G")"
check "name comes from the master list" "Asha Verma" "$(sql "select submitter->>'name' from grievances where $G")"
check "edited contact is stored in the snapshot" 9111111111 "$(sql "select submitter->>'contact' from grievances where $G")"
check "master list contact unchanged" 9876543210 "$(sql "select contact from master_people where id_code='T4S001'")"
check "department id from the master list" "$D" "$(sql "select department_id from grievances where $G")"
check "hostel id from the master list" "$H" "$(sql "select hostel_id from grievances where $G")"
check "snapshot has the department name" "Computer Science" "$(sql "select submitter->>'department' from grievances where $G")"
check "snapshot has the ID label" "Scholar Number" "$(sql "select submitter->>'id_label' from grievances where $G")"
check "first action is 'submit' by the submitter" "submit|Asha Verma|" "$(sql "select action||'|'||actor_name||'|'||coalesce(actor_user_id::text,'') from grievance_actions a join grievances g on g.id=a.grievance_id where g.$G")"
check "action records the post it went to" "Head of Department (Computer Science)" "$(sql "select level_label from grievance_actions a join grievances g on g.id=a.grievance_id where g.$G")"
check "two notification events logged" 2 "$(sql "select count(*) from notification_events e join grievances g on g.id=e.grievance_id where g.$G")"
check "the form opens again for another grievance" 200 "$(code -c $T/s.jar -b $T/s.jar $B/submit)"
R=$(get s my); has "My grievances lists the new grievance" "MANIT-$YEAR-ACD-000001" "$R"
check "reference counter at 1" 1 "$(sql "select last_seq from reference_counters where type_code='ACD'")"

echo "--- reference numbers and duplicates"
begin s student T4S001 >/dev/null
L=$(fillform s student type_id=2 contact=9876543210 subject="Mess" description="Food quality."); check "hostel grievance accepted for a hostel resident" "$B/submit/done" "$L"
has "HST reference starts at 1" "MANIT-$YEAR-HST-000001" "$(get s submit/done)"
check "hostel grievance goes to the Warden of that hostel" "Warden (Hostel A)" "$(sql "select level_label from grievance_actions a join grievances g on g.id=a.grievance_id where g.reference_no='MANIT-$YEAR-HST-000001'")"
begin s student T4S001 >/dev/null
R=$(show s student type_id=1 contact=9876543210 subject="Marks again" description="Second.")
has "duplicate pending grievance is warned about" "already have 1 pending" "$R"; has "warning names the earlier reference" "MANIT-$YEAR-ACD-000001" "$R"; has "confirmation checkbox is offered" 'name="confirm_duplicate"' "$R"
check "warned submission is not saved" $((N0+2)) "$(gcount)"
L=$(fillform s student type_id=1 contact=9876543210 subject="Marks again" description="Second." confirm_duplicate=1)
check "confirmed duplicate is accepted" "$B/submit/done" "$L"; has "sequence continues per type and year" "MANIT-$YEAR-ACD-000002" "$(get s submit/done)"
sql "update grievances set status='disposed', disposed_at=now(), current_level_id=NULL where reference_no like '%ACD%'" >/dev/null
begin s student T4S001 >/dev/null
L=$(fillform s student type_id=1 contact=9876543210 subject="Marks third" description="Third."); check "no warning once the earlier ones are disposed" "$B/submit/done" "$L"
has "third ACD is 000003" "MANIT-$YEAR-ACD-000003" "$(get s submit/done)"
sql "update settings set value='5' where key='ref_seq_digits'; update settings set value='GRV' where key='ref_prefix'" >/dev/null
begin s student T4S001 >/dev/null; fillform s student type_id=3 contact=9876543210 subject="Rule" description="Discipline." >/dev/null
has "prefix and digit settings are used" "GRV-$YEAR-DSC-00001" "$(get s submit/done)"
sql "update settings set value='6' where key='ref_seq_digits'; update settings set value='MANIT' where key='ref_prefix'" >/dev/null

echo "--- faculty and non-teaching"
begin f faculty T4F001 >/dev/null; R=$(get f submit)
has "faculty form: single type is fixed" "General" "$R"; hasnt "faculty form: no type drop-down" 'id="type_id"' "$R"; has "faculty ID label" "Employee Code" "$R"
L=$(fillform f faculty contact=9876500004 subject="Lab access" description="Need access."); check "faculty submission accepted" "$B/submit/done" "$L"
has "faculty reference uses FGEN" "MANIT-$YEAR-FGEN-000001" "$(get f submit/done)"
check "faculty goes to the HOD of the department" "Head of Department (Computer Science)" "$(sql "select level_label from grievance_actions a join grievances g on g.id=a.grievance_id where g.reference_no like '%FGEN-000001'")"
begin n non-teaching T4N001 >/dev/null
L=$(fillform n non-teaching contact=9876500005 subject="Leave" description="Leave issue."); check "non-teaching submission accepted" "$B/submit/done" "$L"
has "non-teaching reference uses NGEN" "MANIT-$YEAR-NGEN-000001" "$(get n submit/done)"
check "non-teaching goes to the Reporting Officer of the section" "Reporting Officer (Computer Science)" "$(sql "select level_label from grievance_actions a join grievances g on g.id=a.grievance_id where g.reference_no like '%NGEN-000001'")"

rm -rf writable/cache/*
echo "--- attachments"
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF\n' > $T/doc.pdf
python3 -c "
import struct,zlib
def ch(t,d): c=struct.pack('>I',len(d))+t+d; return c+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
open('$T/pic.png','wb').write(b'\x89PNG\r\n\x1a\n'+ch(b'IHDR',struct.pack('>IIBBBBB',1,1,8,2,0,0,0))+ch(b'IDAT',zlib.compress(b'\x00\xff\x00\x00'))+ch(b'IEND',b''))"
echo "just text" > $T/fake.pdf; echo "MZ binary" > $T/evil.exe; : > $T/empty.pdf; head -c 2200000 /dev/zero > $T/big.pdf; printf '%%PDF-' | dd of=$T/big.pdf conv=notrunc 2>/dev/null
N1=$(gcount)
begin s student T4S002 >/dev/null
L=$(fillform s student type_id=1 contact=9876500002 subject="With files" description="See attached." "attachments[]=@$T/doc.pdf;filename=report.pdf" "attachments[]=@$T/pic.png;filename=photo.png")
check "grievance with two attachments accepted" "$B/submit/done" "$L"
REF=$(sql "select reference_no from grievances where subject='With files'")
check "two attachment rows" 2 "$(sql "select count(*) from attachments a join grievances g on g.id=a.grievance_id where g.reference_no='$REF'")"
check "original name kept" "report.pdf" "$(sql "select original_name from attachments a join grievances g on g.id=a.grievance_id where g.reference_no='$REF' and mime_type='application/pdf'")"
check "mime recorded from the extension" "image/png" "$(sql "select mime_type from attachments a join grievances g on g.id=a.grievance_id where g.reference_no='$REF' and original_name='photo.png'")"
ST=$(sql "select stored_name from attachments a join grievances g on g.id=a.grievance_id where g.reference_no='$REF' and original_name='photo.png'")
matches "stored under a random name" '^[0-9a-f]{32}\.png$' "$ST"
check "file exists in private storage" yes "$([ -f writable/attachments/${ST:0:2}/$ST ] && echo yes || echo no)"
check "stored file is not reachable from the web" 404 "$(code $B/writable/attachments/${ST:0:2}/$ST)"
check "nor from the public folder" 404 "$(code $B/attachments/${ST:0:2}/$ST)"
check "stored file is identical to the upload" "$(md5sum < $T/pic.png)" "$(md5sum < writable/attachments/${ST:0:2}/$ST)"
N2=$(gcount)
try() { begin s student T4S002 >/dev/null; show s student type_id=4 contact=9876500002 subject="Files test" description="x" "$@"; }
R=$(try "attachments[]=@$T/doc.pdf" "attachments[]=@$T/doc.pdf" "attachments[]=@$T/pic.png" "attachments[]=@$T/pic.png"); has "more than the allowed files refused" "at most 3 file" "$R"
R=$(try "attachments[]=@$T/evil.exe"); has "disallowed type refused" "this file type is not allowed" "$R"
R=$(try "attachments[]=@$T/fake.pdf"); has "fake PDF (wrong content) refused" "does not look like a real pdf" "$R"
R=$(try "attachments[]=@$T/empty.pdf"); has "empty file refused" "is empty" "$R"
sql "update settings set value='1' where key='attach_max_mb'" >/dev/null
R=$(try "attachments[]=@$T/big.pdf"); has "oversize file refused" "too large" "$R"
sql "update settings set value='5' where key='attach_max_mb'" >/dev/null
R=$(try "attachments[]=@$T/doc.pdf;filename=a.docx"); has "type not enabled by the admin refused" "this file type is not allowed" "$R"
sql "update settings set value='pdf,jpg,jpeg,png,docx' where key='attach_allowed_ext'" >/dev/null
R=$(try "attachments[]=@$T/doc.pdf;filename=a.docx"); has "an admin-enabled type is still content-checked" "does not look like a real docx" "$R"
sql "update settings set value='pdf,jpg,jpeg,png' where key='attach_allowed_ext'" >/dev/null
check "no grievance saved by the refused uploads" "$N2" "$(gcount)"
begin s student T4S002 >/dev/null
L=$(fillform s student type_id=4 contact=9876500002 subject="Traversal" description="x" "attachments[]=@$T/doc.pdf;filename=../../evil<b>.pdf")
check "path-like file name accepted but neutralised" "$B/submit/done" "$L"
ON=$(sql "select original_name from attachments a join grievances g on g.id=a.grievance_id where g.subject='Traversal'")
hasnt "no slash kept in the stored original name" "/" "$ON"; hasnt "no markup kept in the stored original name" "<" "$ON"
check "traversal file stored in the attachments folder" yes "$([ -n "$(find writable/attachments -name "$(sql "select stored_name from attachments a join grievances g on g.id=a.grievance_id where g.subject='Traversal'")")" ] && echo yes || echo no)"
sql "update settings set value='0' where key='attach_max_count'" >/dev/null
begin s student T4S002 >/dev/null; R=$(get s submit); hasnt "attachments off: input hidden" 'name="attachments[]"' "$R"
R=$(show s student type_id=4 contact=9876500002 subject="Off" description="x" "attachments[]=@$T/doc.pdf"); has "attachments off: upload refused" "not accepted at the moment" "$R"
sql "update settings set value='3' where key='attach_max_count'" >/dev/null

rm -rf writable/cache/*
echo "--- custom fields from the admin"
adminpost admin/fields/student/add label="Mess number" field_type=text mandatory=1
adminpost admin/fields/student/add label="Block" field_type=dropdown options=$'North\nSouth'
adminpost admin/fields/student/add label="Incident date" field_type=date
adminpost admin/fields/student/add label="Amount paid" field_type=number
begin s student T4S001 >/dev/null; R=$(get s submit)
has "custom text field shown" "Mess number" "$R"; has "custom dropdown shown" "North" "$R"; has "custom date shown" 'type="date"' "$R"; has "custom number shown" 'type="number"' "$R"
MK=$(sql "select field_key from form_fields where label='Mess number'"); BK=$(sql "select field_key from form_fields where label='Block'")
DK=$(sql "select field_key from form_fields where label='Incident date'"); NK=$(sql "select field_key from form_fields where label='Amount paid'")
R=$(show s student type_id=4 contact=9876543210 subject="CF" description="x"); has "mandatory custom field enforced" "Mess number is required" "$R"
R=$(show s student type_id=4 contact=9876543210 subject="CF" description="x" "cf[$MK]=M-12" "cf[$BK]=East"); has "dropdown value outside the options refused" "Block is not valid" "$R"
R=$(show s student type_id=4 contact=9876543210 subject="CF" description="x" "cf[$MK]=M-12" "cf[$DK]=31-02-2026"); has "bad date refused" "Incident date is not valid" "$R"
R=$(show s student type_id=4 contact=9876543210 subject="CF" description="x" "cf[$MK]=M-12" "cf[$NK]=abc"); has "bad number refused" "Amount paid is not valid" "$R"
L=$(fillform s student type_id=4 contact=9876543210 subject="CF ok" description="x" confirm_duplicate=1 "cf[$MK]=M-12" "cf[$BK]=North" "cf[$DK]=2026-10-01" "cf[$NK]=1250.50" "cf[cf_unknown]=sneaky")
check "valid custom values accepted" "$B/submit/done" "$L"
check "custom text stored" "M-12" "$(sql "select custom->'$MK'->>'value' from grievances where subject='CF ok'")"
check "label stored with the value" "Mess number" "$(sql "select custom->'$MK'->>'label' from grievances where subject='CF ok'")"
check "dropdown stored" "North" "$(sql "select custom->'$BK'->>'value' from grievances where subject='CF ok'")"
check "date stored" "2026-10-01" "$(sql "select custom->'$DK'->>'value' from grievances where subject='CF ok'")"
check "number stored" "1250.50" "$(sql "select custom->'$NK'->>'value' from grievances where subject='CF ok'")"
check "unknown field names are ignored" f "$(sql "select jsonb_exists(custom,'cf_unknown') from grievances where subject='CF ok'")"
MID=$(sql "select id from form_fields where field_key='$MK'"); adminpost admin/fields/student/$MID/update label="Mess no." mandatory=0 visible=1
check "stored label survives a rename" "Mess number" "$(sql "select custom->'$MK'->>'label' from grievances where subject='CF ok'")"
adminpost admin/fields/student/$MID/update label="Mess no." mandatory=0 visible=0
begin s student T4S001 >/dev/null; R=$(get s submit); hasnt "hidden custom field not shown" "Mess no." "$R"
L=$(fillform s student type_id=4 contact=9876543210 subject="Hidden ok" description="x" confirm_duplicate=1); check "hidden mandatory field is not required" "$B/submit/done" "$L"
adminpost admin/fields/student/$(sql "select id from form_fields where field_key='semester'")/update label="Semester" mandatory=1 visible=0
begin s student T4S001 >/dev/null; R=$(get s submit); hasnt "hidden built-in field not shown" "$SEMNAME" "$R"
fillform s student type_id=4 contact=9876543210 subject="No sem" description="x" confirm_duplicate=1 >/dev/null
check "grievance saved with the semester field hidden" 1 "$(sql "select count(*) from grievances where subject='No sem'")"
check "hidden built-in field left out of the snapshot" "" "$(sql "select coalesce(submitter->>'semester','') from grievances where subject='No sem'")"
adminpost admin/fields/student/$(sql "select id from form_fields where field_key='semester'")/update label="Semester" mandatory=1 visible=1

rm -rf writable/cache/*
echo "--- types and availability"
sql "update grievance_types set active=false where code='OTH'" >/dev/null
begin s student T4S001 >/dev/null; R=$(get s submit); hasnt "inactive type not offered" "Others" "$R"
R=$(show s student type_id=4 contact=9876543210 subject="Inactive" description="x"); has "inactive type refused" "Choose the type of grievance" "$R"
sql "update grievance_types set active=true where code='OTH'" >/dev/null
sql "delete from escalation_levels where type_id=(select id from grievance_types where code='OTH')" >/dev/null 2>&1
begin s student T4S001 >/dev/null; R=$(get s submit); has "a type with levels still offered (others)" "Discipline" "$R"
sql "update grievance_types set active=false where role='faculty'" >/dev/null
R=$(get f ""); has "role without an active type shown as not available" "Not available yet" "$R"
check "its submission page redirects to My grievances" "302 $B/my" "$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c $T/f.jar -b $T/f.jar $B/submit | sed 's/^303/302/')"
sql "update grievance_types set active=true where role='faculty'" >/dev/null

echo "--- eligibility can change mid-way"
begin s student T4S001 >/dev/null
sql "update master_people set active=false where id_code='T4S001'" >/dev/null
N3=$(gcount); R=$(show s student type_id=3 contact=9876543210 subject="Late" description="x")
check "deactivated after lookup: submission refused" "$N3" "$(gcount)"
sql "update master_people set active=true where id_code='T4S001'" >/dev/null

echo "--- atomic save"
mv writable/attachments writable/attachments.bak; touch writable/attachments
CT=$(sql "select last_seq from reference_counters where type_code='DSC'"); N4=$(gcount)
begin s student T4S002 >/dev/null
R=$(show s student type_id=3 contact=9876500002 subject="Will fail" description="x" "attachments[]=@$T/doc.pdf")
has "storage failure is reported" "could not be saved" "$R"
check "no grievance left behind" "$N4" "$(gcount)"
check "reference counter rolled back (no gap)" "$CT" "$(sql "select last_seq from reference_counters where type_code='DSC'")"
check "no orphan action rows" 0 "$(sql "select count(*) from grievance_actions a left join grievances g on g.id=a.grievance_id where g.id is null")"
rm -f writable/attachments; mv writable/attachments.bak writable/attachments

echo "--- rate limit"
rm -rf writable/cache/*
begin s student T4S001 >/dev/null
for i in $(seq 1 80); do sendotp s >/dev/null; done
R=$(get s submit); has "too many OTP requests from one address are throttled" "Too many attempts" "$R"
rm -rf writable/cache/*

echo; echo "Passed: $pass   Failed: $fail"; [ $fail -eq 0 ]
