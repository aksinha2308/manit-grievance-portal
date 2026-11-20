-- =====================================================================================
--  DEMO ERP (main).  Made-up people. Used ONLY for the demo; loaded by setup-demo.ps1 into database demo_erp.
--  Based on the dummy ERP v2, then tidied for the demo (see "DEMO ADJUSTMENTS" at the end of this file):
--    * department values are FULL NAMES (like the real ERP), not short codes
--    * job titles, e-mails and mobile numbers are cleaned
--    * three cases are left on purpose for the presenter to show:
--        1) employee 10041 'Hostel Superintendent' has a job title the portal does not know yet
--        2) employees 10039 and 10040 share one e-mail address (duplicate in the ERP -> sign-in refused)
--        3) anyone who is not in this list is "not registered" (type an unknown e-mail on the test login)
-- =====================================================================================
-- =====================================================================================
--  DUMMY ERP, MAIN DATABASE  (database name: demo_erp, schema: academic)
--  Mirrors the real ERP's table and column names where they are known (see README).
--  Columns marked ASSUMED are guesses until the real table is checked.
--  Made-up people. Safe to run again: it starts clean.
--     1) CREATE DATABASE demo_erp;   2) connect to it and run this file.
-- =====================================================================================
DROP SCHEMA IF EXISTS academic CASCADE;
CREATE SCHEMA academic;

CREATE TABLE academic.deptmas (                -- ASSUMED table: department codes and names
    dept_code varchar(10) PRIMARY KEY,
    dept_name varchar(150) NOT NULL
);

CREATE TABLE academic.studentmas (
    institute_id  integer      NOT NULL,       -- known (meaning not yet known)
    studentuid    bigint       PRIMARY KEY,    -- known
    full_name     varchar(150) NOT NULL,       -- known
    fname         varchar(60),                 -- known
    mname         varchar(60),                 -- known
    lname         varchar(60),                 -- known
    roll_no       varchar(30)  NOT NULL,       -- known (stored in capitals)
    curr_dept     varchar(10)  REFERENCES academic.deptmas,   -- known name; ASSUMED to hold a short code
    stu_email     varchar(150),                -- ASSUMED (requested): institute e-mail, may be missing/untidy
    mobile_no     varchar(25),                 -- ASSUMED: may be missing/untidy
    curr_sem      smallint,                    -- ASSUMED
    course_name   varchar(40)                  -- ASSUMED (B.Tech, M.Tech ...)
);
CREATE INDEX studentmas_roll_idx ON academic.studentmas (roll_no);

CREATE TABLE academic.empmas (                 -- faculty AND non-teaching staff in one table; no "type" column
    institute_id integer      NOT NULL,        -- known
    emp_code     varchar(10)  PRIMARY KEY,     -- known
    empname      varchar(150) NOT NULL,        -- known
    empdesg      varchar(80),                  -- known: free text, e.g. 'Professor' or 'Accountant'
    empdept      varchar(10)  REFERENCES academic.deptmas,   -- known name; ASSUMED short code
    empmob       varchar(25),                  -- known: may be missing/untidy
    inst_email   varchar(150)                  -- known: may be untidy (capitals, trailing space)
);
CREATE INDEX empmas_email_idx ON academic.empmas (lower(btrim(inst_email)));

INSERT INTO academic.deptmas (dept_code, dept_name) VALUES
  ('CSE', 'Computer Science & Engineering'),
  ('ECE', 'Electronics & Communication Engineering'),
  ('EE', 'Electrical Engineering'),
  ('ME', 'Mechanical Engineering'),
  ('CE', 'Civil Engineering'),
  ('CHE', 'Chemical Engineering'),
  ('MSM', 'Materials Science & Metallurgical Engineering'),
  ('MATH', 'Mathematics, Bioinformatics & Computer Applications'),
  ('PHY', 'Physics'),
  ('CHEM', 'Chemistry'),
  ('CES', 'Centre for Energy Studies'),
  ('BIO', 'Biosciences (closed)'),
  ('ACC', 'Accounts Section'),
  ('EST', 'Establishment Section'),
  ('LIB', 'Central Library'),
  ('ACS', 'Academic Section'),
  ('WRK', 'Works & Estate Section'),
  ('SWO', 'Student Welfare Office');

INSERT INTO academic.studentmas (institute_id, studentuid, full_name, fname, mname, lname, roll_no, curr_dept, stu_email, mobile_no, curr_sem, course_name) VALUES
  (1, 2200001, 'Komal Lal Mishra', 'Komal', 'Lal', 'Mishra', '2026CSE001', 'CSE', '2026cse001@stu.manit.ac.in', '9800007919', 1, 'B.Tech'),
  (1, 2200002, 'Mayank Shukla', 'Mayank', NULL, 'Shukla', '2026CSE002', 'CSE', '2026cse002@stu.manit.ac.in', '9800015838', 1, 'B.Tech'),
  (1, 2200003, 'Siddharth Lal Malviya', 'Siddharth', 'Lal', 'Malviya', '2025CSE001', 'CSE', '2025cse001@stu.manit.ac.in', '9800023757', 3, 'B.Tech'),
  (1, 2200004, 'Isha Mohan Tiwari', 'Isha', 'Mohan', 'Tiwari', '2025CSE002', 'CSE', NULL, '9800031676', 3, 'B.Tech'),
  (1, 2200005, 'Dinesh Sharma', 'Dinesh', NULL, 'Sharma', '2024CSE001', 'CSE', '2024cse001@stu.manit.ac.in', '9800039595', 5, 'B.Tech'),
  (1, 2200006, 'Ankit Rani Agrawal', 'Ankit', 'Rani', 'Agrawal', '2024CSE002', 'CSE', '2024cse002@stu.manit.ac.in', '+91 98765 43210', 5, 'B.Tech'),
  (1, 2200007, 'Yamini Rani Khan', 'Yamini', 'Rani', 'Khan', '2023CSE001', 'CSE', '2023cse001@stu.manit.ac.in', '9800055433', 7, 'B.Tech'),
  (1, 2200008, 'Abhishek Devi Patel', 'Abhishek', 'Devi', 'Patel', '2023CSE002', 'CSE', '2023cse002@stu.manit.ac.in', '9800063352', 7, 'B.Tech'),
  (1, 2200009, 'Ritu Mohan Deshmukh', 'Ritu', 'Mohan', 'Deshmukh', 'MT26CSE001', 'CSE', 'mt26cse001@stu.manit.ac.in', NULL, 1, 'M.Tech'),
  (1, 2200010, 'Piyush Kumar Nair', 'Piyush', 'Kumar', 'Nair', 'MT25CSE001', 'CSE', 'mt25cse001@stu.manit.ac.in', '9800079190', 3, 'M.Tech'),
  (1, 2200011, 'Tarun Rani Bhargava', 'Tarun', 'Rani', 'Bhargava', 'PH24CSE001', 'CSE', 'ph24cse001@stu.manit.ac.in', '9800087109', NULL, 'Ph.D'),
  (1, 2200012, 'Vandana Prasad Kushwaha', 'Vandana', 'Prasad', 'Kushwaha', '2026ECE001', 'ECE', 'ph24cse001@stu.manit.ac.in', '9800095028', 1, 'B.Tech'),
  (1, 2200013, 'Shivam Rani Kushwaha', 'Shivam', 'Rani', 'Kushwaha', '2026ECE002', 'ECE', '2026ece002@stu.manit.ac.in', '9800102947', 1, 'B.Tech'),
  (1, 2200014, 'Harsh Shukla', 'Harsh', NULL, 'Shukla', '2025ECE001', 'ECE', '2025ece001@stu.manit.ac.in', '9800110866', 3, 'B.Tech'),
  (1, 2200015, 'Pranav Verma', 'Pranav', NULL, 'Verma', '2025ECE002', 'ECE', '2025ECE002@STU.MANIT.AC.IN ', '9800118785', 3, 'B.Tech'),
  (1, 2200016, 'Meera Singh', 'Meera', NULL, 'Singh', '2024ECE001', 'ECE', '2024ece001@stu.manit.ac.in', '9800126704', 5, 'B.Tech'),
  (1, 2200017, 'Rahul Soni', 'Rahul', NULL, 'Soni', '2024ECE002', 'ECE', '2024ece002@stu.manit.ac.in', '9800134623', 5, 'B.Tech'),
  (1, 2200018, 'Vandana Prasad Saxena', 'Vandana', 'Prasad', 'Saxena', '2023ECE001', 'ECE', 'vandana.saxena@stu.manit.ac.in', '9800142542', 7, 'B.Tech'),
  (1, 2200019, 'Mukesh Raghuwanshi', 'Mukesh', NULL, 'Raghuwanshi', '2023ECE002', 'ECE', '2023ece002@stu.manit.ac.in', '9800150461', 7, 'B.Tech'),
  (1, 2200020, 'Divya Nair', 'Divya', NULL, 'Nair', 'MT26ECE001', 'ECE', 'mt26ece001@stu.manit.ac.in', '9800158380', 1, 'M.Tech'),
  (1, 2200021, 'Abhishek Rani Raghuwanshi', 'Abhishek', 'Rani', 'Raghuwanshi', 'MT25ECE001', 'ECE', 'mt25ece001@stu.manit.ac.in', '9800166299', 3, 'M.Tech'),
  (1, 2200022, 'Yash Kumar Sharma', 'Yash', 'Kumar', 'Sharma', 'PH24ECE001', 'ECE', 'ph24ece001@stu.manit.ac.in', '9800174218', NULL, 'Ph.D'),
  (1, 2200023, 'Vandana Bhargava', 'Vandana', NULL, 'Bhargava', '2026EE001', 'EE', '2026ee001@stu.manit.ac.in', '9800182137', 1, 'B.Tech'),
  (1, 2200024, 'Tarun Mohan Jain', 'Tarun', 'Mohan', 'Jain', '2026EE002', 'EE', '2026ee002@stu.manit.ac.in', '9800190056', 1, 'B.Tech'),
  (1, 2200025, 'Mukesh Rani Kushwaha', 'Mukesh', 'Rani', 'Kushwaha', '2025EE001', 'EE', '2025ee001@stu.manit.ac.in', '9800197975', 3, 'B.Tech'),
  (1, 2200026, 'Aditya Devi Deshmukh', 'Aditya', 'Devi', 'Deshmukh', '2025EE002', 'EE', '2025ee002@stu.manit.ac.in', '9800205894', 3, 'B.Tech'),
  (1, 2200027, 'Rahul Malviya', 'Rahul', NULL, 'Malviya', '2024EE001', 'EE', '2024ee001@stu.manit.ac.in', '9800213813', 5, 'B.Tech'),
  (1, 2200028, 'Yamini Rani Chouhan', 'Yamini', 'Rani', 'Chouhan', '2024EE002', 'EE', '2024ee002@stu.manit.ac.in', '9800221732', 5, 'B.Tech'),
  (1, 2200029, 'Pranav Patel', 'Pranav', NULL, 'Patel', '2023EE001', 'EE', '2023ee001@stu.manit.ac.in', '9800229651', 7, 'B.Tech'),
  (1, 2200030, 'Anjali Prasad Ansari', 'Anjali', 'Prasad', 'Ansari', '2023EE002', 'EE', '2023ee002@stu.manit.ac.in', '9800237570', 7, 'B.Tech'),
  (1, 2200031, 'Trisha Chandra Shukla', 'Trisha', 'Chandra', 'Shukla', 'MT26EE001', 'EE', 'mt26ee001@stu.manit.ac.in', '9800245489', 1, 'M.Tech'),
  (1, 2200032, 'Aarav Kumar Yadav', 'Aarav', 'Kumar', 'Yadav', 'MT25EE001', 'EE', 'mt25ee001@stu.manit.ac.in', '9800253408', 3, 'M.Tech'),
  (1, 2200033, 'Nitin Prasad Rathore', 'Nitin', 'Prasad', 'Rathore', 'PH22EE001', 'EE', 'ph22ee001@stu.manit.ac.in', '9800261327', NULL, 'Ph.D'),
  (1, 2200034, 'Lokesh Kulkarni', 'Lokesh', NULL, 'Kulkarni', '2026ME001', 'ME', '2026me001@stu.manit.ac.in', '9800269246', 1, 'B.Tech'),
  (1, 2200035, 'Naveen Kumar Dubey', 'Naveen', 'Kumar', 'Dubey', '2026ME002', 'ME', '2026me002@stu.manit.ac.in', '9800277165', 1, 'B.Tech'),
  (1, 2200036, 'Rishabh Chandra Kushwaha', 'Rishabh', 'Chandra', 'Kushwaha', '2025ME001', 'ME', '2025me001@stu.manit.ac.in', '9800285084', 3, 'B.Tech'),
  (1, 2200037, 'Pranav Pandey', 'Pranav', NULL, 'Pandey', '2025ME002', 'ME', '2025me002@stu.manit.ac.in', '9800293003', 3, 'B.Tech'),
  (1, 2200038, 'Abhishek Prasad Deshmukh', 'Abhishek', 'Prasad', 'Deshmukh', '2024ME001', 'ME', '2024me001@stu.manit.ac.in', '9800300922', 5, 'B.Tech'),
  (1, 2200039, 'Manish Pandey', 'Manish', NULL, 'Pandey', '2024ME002', 'ME', '2024me002@stu.manit.ac.in', '9800308841', 5, 'B.Tech'),
  (1, 2200040, 'Ravi Chandra Bansal', 'Ravi', 'Chandra', 'Bansal', '2023ME001', 'ME', '2023me001@stu.manit.ac.in', '9800316760', 7, 'B.Tech'),
  (1, 2200041, 'Nitin Kumar Bansal', 'Nitin', 'Kumar', 'Bansal', '2023ME002', 'ME', '2023me002@stu.manit.ac.in', '9800324679', 7, 'B.Tech'),
  (1, 2200042, 'Varun Kumar Patel', 'Varun', 'Kumar', 'Patel', 'MT26ME001', 'ME', 'mt26me001@stu.manit.ac.in', '9800332598', 1, 'M.Tech'),
  (1, 2200043, 'Naveen Kumar Verma', 'Naveen', 'Kumar', 'Verma', 'MT25ME001', 'ME', 'mt25me001@stu.manit.ac.in', '9800340517', 3, 'M.Tech'),
  (1, 2200044, 'Mohit Devi Malviya', 'Mohit', 'Devi', 'Malviya', 'PH24ME001', 'ME', 'ph24me001@stu.manit.ac.in', '9800348436', NULL, 'Ph.D'),
  (1, 2200045, 'Siddharth Bhargava', 'Siddharth', NULL, 'Bhargava', '2026CE001', 'CE', '2026ce001@stu.manit.ac.in', '9800356355', 1, 'B.Tech'),
  (1, 2200046, 'Manish Dubey', 'Manish', NULL, 'Dubey', '2026CE002', 'CE', '2026ce002@stu.manit.ac.in', '9800364274', 1, 'B.Tech'),
  (1, 2200047, 'Ashok Reddy', 'Ashok', NULL, 'Reddy', '2025CE001', 'CE', '2025ce001@stu.manit.ac.in', '9800372193', 3, 'B.Tech'),
  (1, 2200048, 'Rohan Kumar Mehta', 'Rohan', 'Kumar', 'Mehta', '2025CE002', 'CE', '2025ce002@stu.manit.ac.in', '9800380112', 3, 'B.Tech'),
  (1, 2200049, 'Diya Singh', 'Diya', NULL, 'Singh', '2024CE001', 'CE', '2024ce001@stu.manit.ac.in', '9800388031', 5, 'B.Tech'),
  (1, 2200050, 'Nitin Raghuwanshi', 'Nitin', NULL, 'Raghuwanshi', '2024CE002', 'CE', '2024ce002@stu.manit.ac.in', '9800395950', 5, 'B.Tech'),
  (1, 2200051, 'Sanjay Agrawal', 'Sanjay', NULL, 'Agrawal', '2023CE001', 'CE', '2023ce001@stu.manit.ac.in', '9800403869', 7, 'B.Tech'),
  (1, 2200052, 'Sanjay Joshi', 'Sanjay', NULL, 'Joshi', '2023CE002', 'CE', '2023ce002@stu.manit.ac.in', '9800411788', 7, 'B.Tech'),
  (1, 2200053, 'Varun Rani Kulkarni', 'Varun', 'Rani', 'Kulkarni', 'MT26CE001', 'CE', 'mt26ce001@stu.manit.ac.in', '9800419707', 1, 'M.Tech'),
  (1, 2200054, 'Karan Mohan Bansal', 'Karan', 'Mohan', 'Bansal', 'MT25CE001', 'CE', 'mt25ce001@stu.manit.ac.in', '9800427626', 3, 'M.Tech'),
  (1, 2200055, 'Sneha Bhargava', 'Sneha', NULL, 'Bhargava', 'PH23CE001', 'CE', 'ph23ce001@stu.manit.ac.in', '9800435545', NULL, 'Ph.D'),
  (1, 2200056, 'Ravi Deshmukh', 'Ravi', NULL, 'Deshmukh', '2026CHE001', 'CHE', '2026che001@stu.manit.ac.in', '9800443464', 1, 'B.Tech'),
  (1, 2200057, 'Komal Raghuwanshi', 'Komal', NULL, 'Raghuwanshi', '2026CHE002', 'CHE', '2026che002@stu.manit.ac.in', '9800451383', 1, 'B.Tech'),
  (1, 2200058, 'Radhika Prasad Kulkarni', 'Radhika', 'Prasad', 'Kulkarni', '2025CHE001', 'CHE', '2025che001@stu.manit.ac.in', '9800459302', 3, 'B.Tech'),
  (1, 2200059, 'Pankaj Prasad Sharma', 'Pankaj', 'Prasad', 'Sharma', '2025CHE002', 'CHE', '2025che002@stu.manit.ac.in', '9800467221', 3, 'B.Tech'),
  (1, 2200060, 'Gaurav Shukla', 'Gaurav', NULL, 'Shukla', '2024CHE001', 'CHE', '2024che001@stu.manit.ac.in', '9800475140', 5, 'B.Tech'),
  (1, 2200061, 'Deepak Devi Verma', 'Deepak', 'Devi', 'Verma', '2024CHE002', 'CHE', '2024che002@stu.manit.ac.in', '9800483059', 5, 'B.Tech'),
  (1, 2200062, 'Naveen Prasad Agrawal', 'Naveen', 'Prasad', 'Agrawal', '2023CHE001', 'CHE', '2023che001@stu.manit.ac.in', '9800490978', 7, 'B.Tech'),
  (1, 2200063, 'Yash Agrawal', 'Yash', NULL, 'Agrawal', '2023CHE002', 'CHE', '2023che002@stu.manit.ac.in', '9800498897', 7, 'B.Tech'),
  (1, 2200064, 'Sneha Prasad Pandey', 'Sneha', 'Prasad', 'Pandey', 'MT26CHE001', 'CHE', 'mt26che001@stu.manit.ac.in', '9800506816', 1, 'M.Tech'),
  (1, 2200065, 'Manish Mishra', 'Manish', NULL, 'Mishra', 'MT25CHE001', 'CHE', 'mt25che001@stu.manit.ac.in', '9800514735', 3, 'M.Tech'),
  (1, 2200066, 'Madhuri Chouhan', 'Madhuri', NULL, 'Chouhan', 'PH24CHE001', 'CHE', 'ph24che001@stu.manit.ac.in', '9800522654', NULL, 'Ph.D'),
  (1, 2200067, 'Abhishek Mohan Iyer', 'Abhishek', 'Mohan', 'Iyer', '2026MSM001', 'MSM', '2026msm001@stu.manit.ac.in', '9800530573', 1, 'B.Tech'),
  (1, 2200068, 'Rohan Devi Deshmukh', 'Rohan', 'Devi', 'Deshmukh', '2026MSM002', 'MSM', '2026msm002@stu.manit.ac.in', '9800538492', 1, 'B.Tech'),
  (1, 2200069, 'Sneha Kumar Patel', 'Sneha', 'Kumar', 'Patel', '2025MSM001', 'MSM', '2025msm001@stu.manit.ac.in', '9800546411', 3, 'B.Tech'),
  (1, 2200070, 'Aarav Prasad Singh', 'Aarav', 'Prasad', 'Singh', '2025MSM002', 'MSM', '2025msm002@stu.manit.ac.in', '9800554330', 3, 'B.Tech'),
  (1, 2200071, 'Tanvi Prasad Tiwari', 'Tanvi', 'Prasad', 'Tiwari', '2024MSM001', 'MSM', '2024msm001@stu.manit.ac.in', '9800562249', 5, 'B.Tech'),
  (1, 2200072, 'Piyush Devi Khan', 'Piyush', 'Devi', 'Khan', '2024MSM002', 'MSM', '2024msm002@stu.manit.ac.in', '9800570168', 5, 'B.Tech'),
  (1, 2200073, 'Shivam Rani Gupta', 'Shivam', 'Rani', 'Gupta', '2023MSM001', 'MSM', '2023msm001@stu.manit.ac.in', '9800578087', 7, 'B.Tech'),
  (1, 2200074, 'Suresh Rani Bhargava', 'Suresh', 'Rani', 'Bhargava', '2023MSM002', 'MSM', '2023msm002@stu.manit.ac.in', '9800586006', 7, 'B.Tech'),
  (1, 2200075, 'Amit Ansari', 'Amit', NULL, 'Ansari', 'MT26MSM001', 'MSM', 'mt26msm001@stu.manit.ac.in', '9800593925', 1, 'M.Tech'),
  (1, 2200076, 'Harsh Mohan Bansal', 'Harsh', 'Mohan', 'Bansal', 'MT25MSM001', 'MSM', 'mt25msm001@stu.manit.ac.in', '9800601844', 3, 'M.Tech'),
  (1, 2200077, 'Arjun Kumar Soni', 'Arjun', 'Kumar', 'Soni', 'PH24MSM001', 'MSM', 'ph24msm001@stu.manit.ac.in', '9800609763', NULL, 'Ph.D'),
  (1, 2200078, 'Pooja Prasad Deshmukh', 'Pooja', 'Prasad', 'Deshmukh', 'MS26MATH001', 'MATH', 'ms26math001@stu.manit.ac.in', '9800617682', 1, 'M.Sc'),
  (1, 2200079, 'Nisha Iyer', 'Nisha', NULL, 'Iyer', 'MS25MATH001', 'MATH', 'ms25math001@stu.manit.ac.in', '9800625601', 3, 'M.Sc'),
  (1, 2200080, 'Lokesh Lal Reddy', 'Lokesh', 'Lal', 'Reddy', 'MS26PHY001', 'PHY', 'ms26phy001@stu.manit.ac.in', '9800633520', 1, 'M.Sc'),
  (1, 2200081, 'Amit Joshi', 'Amit', NULL, 'Joshi', 'MS25PHY001', 'PHY', 'ms25phy001@stu.manit.ac.in', '9800641439', 3, 'M.Sc'),
  (1, 2200082, 'Diya Pandey', 'Diya', NULL, 'Pandey', 'MS26CHEM001', 'CHEM', 'ms26chem001@stu.manit.ac.in', '9800649358', 1, 'M.Sc'),
  (1, 2200083, 'Mohit Rani Verma', 'Mohit', 'Rani', 'Verma', 'MS25CHEM001', 'CHEM', 'ms25chem001@stu.manit.ac.in', '9800657277', 3, 'M.Sc'),
  (1, 2200084, 'Naveen Rani Shukla', 'Naveen', 'Rani', 'Shukla', 'MC26001', 'MATH', 'mc26001@stu.manit.ac.in', '9800665196', 1, 'MCA'),
  (1, 2200085, 'Ravi Chandra Saxena', 'Ravi', 'Chandra', 'Saxena', 'MC25001', 'MATH', 'mc25001@stu.manit.ac.in', '9800673115', 3, 'MCA');

INSERT INTO academic.empmas (institute_id, emp_code, empname, empdesg, empdept, empmob, inst_email) VALUES
  (1, '10001', 'Ananya Mohan Mehta', 'Professor', 'CSE', '9803959500', 'ananya.mehta@manit.ac.in'),
  (1, '10002', 'Ananya Kulkarni', 'ASSISTANT PROFESSOR', 'CSE', '9803967419', 'ananya.kulkarni@manit.ac.in'),
  (1, '10003', 'Ankit Pandey', 'Associate Professor', 'CSE', '9803975338', 'Rahul.Verma@manit.ac.in '),
  (1, '10004', 'Neha Gupta', 'Professor', 'ECE', '9803983257', 'neha.gupta@manit.ac.in'),
  (1, '10005', 'Gaurav Kumar Iyer', 'Associate Professor ', 'ECE', '9803991176', 'gaurav.iyer@manit.ac.in'),
  (1, '10006', 'Rajesh Kumar Raghuwanshi', 'Contract Faculty', 'ECE', '9803999095', 'rajesh.raghuwanshi@manit.ac.in'),
  (1, '10007', 'Abhishek Rani Dubey', 'Professor', 'EE', NULL, 'abhishek.dubey@manit.ac.in'),
  (1, '10008', 'Pankaj Devi Kulkarni', 'Assistant Professor', 'EE', '0755-4051234', 'pankaj.kulkarni@manit.ac.in'),
  (1, '10009', 'Mukesh Bhargava', 'Assistant Professor', 'EE', '9804022852', 'mukesh.bhargava@manit.ac.in'),
  (1, '10010', 'Rishabh Lal Nair', 'Professor', 'ME', '9804030771', 'rishabh.nair@manit.ac.in'),
  (1, '10011', 'Vikram Kumar Kushwaha', 'Assistant Professor', 'ME', '9804038690', 'vikram.kushwaha@manit.ac.in'),
  (1, '10012', 'Anurag Kumar Shukla', 'Assistant Professor', 'ME', '9804046609', 'anurag.shukla@manit.ac.in'),
  (1, '10013', 'Shruti Chandra Thakur', 'Assistant Professor', 'CE', '9804054528', 'shruti.thakur@manit.ac.in'),
  (1, '10014', 'Vivaan Agrawal', 'Contract Faculty', 'CE', '9804062447', 'vivaan.agrawal@manit.ac.in'),
  (1, '10015', 'Sumit Bansal', 'Assistant Professor', 'CE', '9804070366', 'sumit.bansal@manit.ac.in'),
  (1, '10016', 'Karan Rani Dubey', 'Professor', 'CHE', '9804078285', 'karan.dubey@manit.ac.in'),
  (1, '10017', 'Mohit Gupta', 'Assistant Professor', 'CHE', '9804086204', 'mohit.gupta@manit.ac.in'),
  (1, '10018', 'Tanvi Deshmukh', 'Assistant Professor', 'CHE', '9804094123', 'tanvi.deshmukh@manit.ac.in'),
  (1, '10019', 'Varun Jain', 'Assistant Professor', 'MSM', '9804102042', 'varun.jain@manit.ac.in'),
  (1, '10020', 'Pranav Devi Soni', 'Contract Faculty', 'MSM', '9804109961', 'pranav.soni@manit.ac.in'),
  (1, '10021', 'Komal Chandra Verma', 'Professor', 'MSM', '9804117880', 'komal.verma@manit.ac.in'),
  (1, '10022', 'Manish Prasad Verma', 'Assistant Professor', 'MATH', '9804125799', 'manish.verma@manit.ac.in'),
  (1, '10023', 'Aarav Prasad Ansari', 'Professor', 'MATH', '9804133718', 'aarav.ansari@manit.ac.in'),
  (1, '10024', 'Ritu Dubey', 'Assistant Professor', 'MATH', '9804141637', 'ritu.dubey@manit.ac.in'),
  (1, '10025', 'Kavya Iyer', 'Professor', 'PHY', '9804149556', 'kavya.iyer@manit.ac.in'),
  (1, '10026', 'Rohan Mohan Bansal', 'Contract Faculty', 'PHY', '9804157475', 'rohan.bansal@manit.ac.in'),
  (1, '10027', 'Kavya Khan', 'Professor', 'PHY', '9804165394', 'kavya.khan@manit.ac.in'),
  (1, '10028', 'Komal Devi Rathore', 'Associate Professor', 'CHEM', '9804173313', 'komal.rathore@manit.ac.in'),
  (1, '10029', 'Naveen Prasad Yadav', 'Associate Professor', 'CHEM', '9804181232', 'naveen.yadav@manit.ac.in'),
  (1, '10030', 'Mohit Thakur', 'Contract Faculty', 'CHEM', '9804189151', 'mohit.thakur@manit.ac.in'),
  (1, '10031', 'Pankaj Devi Joshi', 'Associate Professor', 'CES', '9804197070', 'pankaj.joshi@manit.ac.in'),
  (1, '10032', 'Mayank Devi Reddy', 'Accountant', 'ACC', '9804204989', 'mayank.reddy@manit.ac.in'),
  (1, '10033', 'Siddharth Devi Mehta', 'Accounts Assistant', 'ACC', '9804212908', 'siddharth.mehta@manit.ac.in'),
  (1, '10034', 'Radhika Lal Bhargava', 'Superintendent', 'EST', '9804220827', 'radhika.bhargava@manit.ac.in'),
  (1, '10035', 'Naveen Rani Khan', 'Clerk', 'EST', '9804228746', 'naveen.khan@manit.ac.in'),
  (1, '10036', 'Rishabh Lal Bansal', 'Librarian', 'LIB', '9804236665', 'rishabh.bansal@manit.ac.in'),
  (1, '10037', 'Madhuri Rani Pandey', 'Library Assistant', 'LIB', '9804244584', 'madhuri.pandey@manit.ac.in'),
  (1, '10038', 'Sakshi Devi Tiwari', 'Assistant Registrar', 'ACS', '9804252503', 'sakshi.tiwari@manit.ac.in'),
  (1, '10039', 'Trisha Mohan Malviya', 'Clerk', 'ACS', '9804260422', 'trisha.malviya@manit.ac.in'),
  (1, '10040', 'Bhavna Lal Kulkarni', 'Junior Engineer', 'WRK', '9804268341', 'bhavna.kulkarni@manit.ac.in'),
  (1, '10041', 'Meera Mohan Tiwari', 'Hostel Superintendent', 'SWO', '9804276260', 'meera.tiwari@manit.ac.in'),
  (1, '10042', 'Swati Rani Mehta', 'Lab Technician', 'CSE', '9804284179', 'swati.mehta@manit.ac.in'),
  (1, '10043', 'Kiran Reddy', 'Lab Technician', 'ECE', '9804292098', 'kiran.reddy@manit.ac.in'),
  (1, '19999', 'Tarun Rani Bhargava', 'Project Assistant', 'CSE', '9800087109', 'project.assistant.tarun@manit.ac.in');

-- Read-only account for the portal (same account name is used in the hostel database).
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'demo_ro') THEN
    CREATE ROLE demo_ro LOGIN PASSWORD 'demo_ro_dev';
  END IF;
END $$;
DO $$ BEGIN EXECUTE format('GRANT CONNECT ON DATABASE %I TO demo_ro', current_database()); END $$;
GRANT USAGE ON SCHEMA academic TO demo_ro;
GRANT SELECT ON academic.deptmas, academic.studentmas, academic.empmas TO demo_ro;


-- =====================================================================================
--  DEMO ADJUSTMENTS
-- =====================================================================================
ALTER TABLE academic.studentmas DROP CONSTRAINT IF EXISTS studentmas_curr_dept_fkey;
ALTER TABLE academic.empmas     DROP CONSTRAINT IF EXISTS empmas_empdept_fkey;
ALTER TABLE academic.studentmas ALTER COLUMN curr_dept TYPE varchar(150);
ALTER TABLE academic.empmas     ALTER COLUMN empdept   TYPE varchar(150);

-- 1. Department = full name (as in the real ERP)
UPDATE academic.studentmas s SET curr_dept = d.dept_name FROM academic.deptmas d WHERE d.dept_code = s.curr_dept;
UPDATE academic.empmas     e SET empdept   = d.dept_name FROM academic.deptmas d WHERE d.dept_code = e.empdept;

-- 2. Tidy job titles, e-mails and mobile numbers
UPDATE academic.empmas SET inst_email = lower(btrim(inst_email)),
                           empdesg    = btrim(regexp_replace(empdesg, '\s+', ' ', 'g'));
UPDATE academic.empmas SET empdesg = 'Assistant Professor' WHERE lower(empdesg) = 'assistant professor';
UPDATE academic.empmas SET empmob = '98' || lpad((abs(hashtext(emp_code)) % 100000000)::text, 8, '0')
 WHERE empmob IS NULL OR empmob !~ '^[0-9]{10}$';
UPDATE academic.studentmas SET mobile_no = '97' || lpad((abs(hashtext(roll_no)) % 100000000)::text, 8, '0')
 WHERE mobile_no IS NULL OR mobile_no !~ '^[0-9]{10}$';

-- 3. The same person as student and as staff is removed (keeps the demo easy to explain)
DELETE FROM academic.empmas WHERE emp_code = '19999';

-- 4. Flagged case: two employees with the same e-mail (ERP data error)
UPDATE academic.empmas SET inst_email = (SELECT inst_email FROM academic.empmas WHERE emp_code = '10039')
 WHERE emp_code = '10040';
