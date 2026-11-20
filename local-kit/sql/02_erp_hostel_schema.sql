-- Run as postgres, connected to grievance_hostel_local. Stands in for the ERP's hostel database.
CREATE SCHEMA IF NOT EXISTS manit_hostel_management;

CREATE TABLE manit_hostel_management.hostal_master (     -- (sic) the live code uses this spelling
    id_hostal_master   integer PRIMARY KEY,
    hostal_abber       varchar(20),                       -- abbreviation. NOTE: the live query keeps only its first character
    hostal_hostal_name varchar(150) NOT NULL
);
CREATE TABLE manit_hostel_management.hostel_rooms (
    room_no   varchar(20) PRIMARY KEY,                    -- the live join is on room number alone, so it must be unique
    hostel_id integer NOT NULL REFERENCES manit_hostel_management.hostal_master(id_hostal_master)
);
CREATE TABLE manit_hostel_management.hostel_allot (
    id                 serial PRIMARY KEY,
    allot_student_id   varchar(20) NOT NULL,              -- studentuid. NOTE: the live query keeps only its first character
    allot_room_no      varchar(20) NOT NULL,
    allot_status       varchar(5)  NOT NULL DEFAULT '1',
    allot_final_status varchar(5)  NOT NULL DEFAULT '1',
    delete_status      varchar(5)  NOT NULL DEFAULT '0'
);
GRANT USAGE ON SCHEMA manit_hostel_management TO erp_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA manit_hostel_management TO erp_ro;
