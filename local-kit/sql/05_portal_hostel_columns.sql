-- Run as grievance_app (or postgres) in grievance_local, AFTER "php spark migrate".
-- INFERRED, NOT FROM LIVE: the live code stores hostel and room as text, but none of the portal's migrations create
-- those columns. This file adds what the code needs. Replace it with the vendor's real SQL when you receive it.
SET search_path = grievance;

ALTER TABLE master_people ADD COLUMN IF NOT EXISTS hostel varchar(150);
ALTER TABLE master_people ADD COLUMN IF NOT EXISTS room   varchar(150);
ALTER TABLE grievances    ADD COLUMN IF NOT EXISTS hostel varchar(150);
ALTER TABLE grievances    ADD COLUMN IF NOT EXISTS room   varchar(150);
ALTER TABLE post_holders  ADD COLUMN IF NOT EXISTS hostel varchar(150);

-- One active holder per post and per scope. A Warden's scope is now the hostel text, so the hostel is part of the key.
DROP INDEX IF EXISTS post_holders_post_uq;
CREATE UNIQUE INDEX post_holders_post_uq ON post_holders
    (post_id, COALESCE(scope_id, 0), COALESCE(lower(btrim(hostel)), '')) WHERE active;
