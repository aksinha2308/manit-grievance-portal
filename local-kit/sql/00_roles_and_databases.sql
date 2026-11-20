-- Run as the PostgreSQL superuser (postgres). Creates the two local databases and three accounts.
-- Passwords are for a LOCAL, throw-away machine only.
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'grievance_app') THEN CREATE ROLE grievance_app LOGIN PASSWORD 'local_app_pw'; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_ro')        THEN CREATE ROLE erp_ro        LOGIN PASSWORD 'local_erp_ro_pw'; END IF;
END $$;
DROP DATABASE IF EXISTS grievance_local WITH (FORCE);
DROP DATABASE IF EXISTS grievance_hostel_local WITH (FORCE);
CREATE DATABASE grievance_local        OWNER grievance_app ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;
CREATE DATABASE grievance_hostel_local OWNER grievance_app ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;
