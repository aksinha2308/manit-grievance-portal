-- Run as postgres, connected to grievance_local.
-- Stands in for the institute ERP's main database (schemas academic and establishment) and creates the
-- empty schema "grievance" that holds the portal's own tables.
-- Every table and column below is one the live code reads. See DB-SCHEMA-NOTES in the guide for which are
-- confirmed by queries in the code and which are guesses.

CREATE SCHEMA IF NOT EXISTS academic;
CREATE SCHEMA IF NOT EXISTS establishment;
CREATE SCHEMA IF NOT EXISTS grievance AUTHORIZATION grievance_app;

-- ---------------------------------------------------------------- establishment
CREATE TABLE establishment.dpcode (          -- official department list (portal routes and labels use it)
    dept     integer      PRIMARY KEY,       -- department number (must be unique and not null)
    depname  varchar(150) NOT NULL           -- full department name
);

CREATE TABLE establishment.dgcode (          -- official designation list
    code     integer      PRIMARY KEY,       -- designation number (live code treats 2, 3, 4 as faculty)
    long     varchar(150) NOT NULL           -- full designation name
);

CREATE TABLE establishment.empmas (          -- employees
    emp_code   varchar(20)  PRIMARY KEY,
    empname    varchar(150) NOT NULL,
    empdesg    integer,                      -- designation number -> dgcode.code
    empdept    integer REFERENCES establishment.dpcode(dept),
    mobile_no  varchar(20),
    inst_email varchar(150)                  -- institute e-mail used to sign in
);

-- ---------------------------------------------------------------- academic
CREATE TABLE academic.program_master (
    program_master_id    integer PRIMARY KEY,
    program_name         varchar(100),       -- not read by the code; for readability only
    degree_level_id_code integer             -- the live query excludes level 82
);

CREATE TABLE academic.studentmas (           -- students
    studentuid varchar(20)  PRIMARY KEY,
    full_name  varchar(150) NOT NULL,
    fname      varchar(80),
    mname      varchar(80),
    lname      varchar(80),
    roll_no    varchar(30)  NOT NULL,        -- scholar number; the e-mail prefix of the student
    curr_dept  integer REFERENCES establishment.dpcode(dept),
    -- columns used only by the "people from the ERP" list and Sync (Config/Erp.php guesses):
    mobile_no   varchar(20),
    curr_sem    integer,
    course_name varchar(100)
);

CREATE TABLE academic.student_program_enrollment_versions (
    id                           serial PRIMARY KEY,
    studentuid                   varchar(20) NOT NULL REFERENCES academic.studentmas(studentuid),
    program_master_id            integer     NOT NULL REFERENCES academic.program_master(program_master_id),
    semester_term_no_id_code     integer     NOT NULL,   -- the portal shows this minus 3
    semester_reg_completion_status varchar(5) NOT NULL   -- 'R' = registered
);

-- Config/Erp.php (used by "People in the ERP" and Sync) reads employees from academic.empmas with different column
-- names. A view keeps it working locally. If live has no such table, that screen fails there too.
CREATE VIEW academic.empmas AS
    SELECT emp_code, empname, empdesg::text AS empdesg, empdept::text AS empdept, mobile_no AS empmob, inst_email
      FROM establishment.empmas;

-- ---------------------------------------------------------------- access
GRANT USAGE ON SCHEMA academic, establishment TO erp_ro, grievance_app;
GRANT SELECT ON ALL TABLES IN SCHEMA academic, establishment TO erp_ro, grievance_app;
GRANT REFERENCES ON establishment.dpcode TO grievance_app;   -- the portal links its tables to dpcode
GRANT ALL ON SCHEMA grievance TO grievance_app;
