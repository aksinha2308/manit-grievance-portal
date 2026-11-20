\set ON_ERROR_STOP off
\set QUIET on
\pset tuples_only on
CREATE TEMP TABLE results(name text, outcome text);
CREATE OR REPLACE FUNCTION pg_temp.expect_fail(name text, stmt text) RETURNS void AS $$
BEGIN
  BEGIN EXECUTE stmt; INSERT INTO results VALUES (name,'FAIL (statement was allowed)');
  EXCEPTION WHEN OTHERS THEN INSERT INTO results VALUES (name,'PASS ('||left(SQLERRM,60)||')'); END;
END $$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION pg_temp.expect_ok(name text, stmt text) RETURNS void AS $$
BEGIN
  BEGIN EXECUTE stmt; INSERT INTO results VALUES (name,'PASS');
  EXCEPTION WHEN OTHERS THEN INSERT INTO results VALUES (name,'FAIL ('||left(SQLERRM,60)||')'); END;
END $$ LANGUAGE plpgsql;

BEGIN;
-- single admin
SELECT pg_temp.expect_fail('second admin account is rejected',
  $$INSERT INTO users(username,full_name,password_hash,is_admin) VALUES ('admin2','X','h',true)$$);
-- usernames case-insensitive unique
SELECT pg_temp.expect_fail('username unique ignoring case',
  $$INSERT INTO users(username,full_name,password_hash) VALUES ('HOD.CS','X','h')$$);
-- one active holder per post
SELECT pg_temp.expect_fail('second active holder for same post is rejected',
  $$INSERT INTO post_holders(post_id,scope_id,user_id)
    SELECT post_id,scope_id,(SELECT id FROM users WHERE username='admin') FROM post_holders LIMIT 1$$);
SELECT pg_temp.expect_ok('same post can be held again once the old holder is inactive',
  $$WITH old AS (UPDATE post_holders SET active=false, ended_at=now() RETURNING post_id, scope_id)
    INSERT INTO post_holders(post_id,scope_id,user_id)
    SELECT post_id,scope_id,(SELECT id FROM users WHERE username='admin') FROM old$$);
-- one director
SELECT pg_temp.expect_fail('second Director post is rejected',
  $$INSERT INTO posts(name,is_director) VALUES ('Director 2',true)$$);
-- type codes
SELECT pg_temp.expect_fail('duplicate type code rejected across roles',
  $$INSERT INTO grievance_types(role,name,code) VALUES ('faculty','Other','ACD')$$);
SELECT pg_temp.expect_fail('type code must be 2-4 capital letters',
  $$INSERT INTO grievance_types(role,name,code) VALUES ('student','Bad','a1')$$);
-- matrix: one final level, unique ordering
SELECT pg_temp.expect_fail('second final level in a type is rejected',
  $$INSERT INTO escalation_levels(type_id,level_no,post_id,is_final)
    SELECT type_id,99,post_id,true FROM escalation_levels WHERE is_final LIMIT 1$$);
SELECT pg_temp.expect_ok('levels can be renumbered inside one transaction (deferred unique)',
  $$UPDATE escalation_levels SET level_no = level_no + 1 WHERE type_id = (SELECT id FROM grievance_types WHERE code='ACD')$$);
-- form fields
SELECT pg_temp.expect_fail('core form field cannot be hidden',
  $$UPDATE form_fields SET visible=false WHERE role='student' AND field_key='name'$$);
-- master list
SELECT pg_temp.expect_ok('master list person inserted',
  $$INSERT INTO master_people(role,id_code,name,department_id,contact)
    VALUES ('student','0901CS211001','Test Student',(SELECT id FROM departments LIMIT 1),'9876543210')$$);
SELECT pg_temp.expect_fail('scholar number unique ignoring case',
  $$INSERT INTO master_people(role,id_code,name,department_id) VALUES ('student','0901cs211001','Dup',(SELECT id FROM departments LIMIT 1))$$);
-- grievances + trail
SELECT pg_temp.expect_ok('grievance inserted',
  $$INSERT INTO grievances(reference_no,type_id,role,id_code,submitter,department_id,subject,description,current_level_id)
    SELECT 'MANIT-2026-ACD-000001', t.id,'student','0901CS211001','{"name":"Test Student"}'::jsonb,
           (SELECT id FROM departments LIMIT 1),'Subject','Body',
           (SELECT id FROM escalation_levels WHERE type_id=t.id ORDER BY level_no LIMIT 1)
    FROM grievance_types t WHERE t.code='ACD'$$);
SELECT pg_temp.expect_fail('duplicate reference number rejected',
  $$INSERT INTO grievances(reference_no,type_id,role,id_code,submitter,subject,description,current_level_id)
    SELECT reference_no,type_id,role,id_code,submitter,subject,description,current_level_id FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_fail('awaiting_submitter requires paused_since',
  $$UPDATE grievances SET status='awaiting_submitter'$$);
SELECT pg_temp.expect_ok('awaiting_submitter with paused_since is accepted',
  $$UPDATE grievances SET status='awaiting_submitter', paused_since=now()$$);
SELECT pg_temp.expect_fail('escalation without a remark is rejected',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action) SELECT id,'Dr X','escalate' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_fail('disposal with blank remark is rejected',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action,remark) SELECT id,'Dr X','dispose','   ' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_ok('escalation with a remark is accepted',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action,remark) SELECT id,'Dr X','escalate','Needs Dean review' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_ok('submission and submitter reply need no remark',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action) SELECT id,'Student','submit' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_fail('action trail cannot be edited',
  $$UPDATE grievance_actions SET remark='changed'$$);
SELECT pg_temp.expect_fail('action trail cannot be deleted',
  $$DELETE FROM grievance_actions$$);
SELECT pg_temp.expect_fail('escalation destination cannot be edited (module 5)',
  $$UPDATE grievance_actions SET to_level_label='Somewhere else'$$);
SELECT pg_temp.expect_ok('overdue reminder event type accepted (module 5)',
  $$INSERT INTO notification_events(grievance_id,event_type) SELECT id,'overdue_reminder' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_fail('late reply without text is rejected',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action,remark) SELECT id,'Student','late_reply',' ' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_ok('late reply with text is accepted',
  $$INSERT INTO grievance_actions(grievance_id,actor_name,action,remark) SELECT id,'Student','late_reply','It is CS-301' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_fail('unknown notification event type rejected',
  $$INSERT INTO notification_events(grievance_id,event_type) SELECT id,'emailed' FROM grievances LIMIT 1$$);
SELECT pg_temp.expect_ok('deleting a level keeps history (level_id set null)',
  $$SELECT 1$$);
SELECT * FROM results ORDER BY 1;
ROLLBACK;
