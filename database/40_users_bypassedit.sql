-- HO database: users.bypassedit is no longer used (Purchase Edit right alone allows editing sold purchases).
-- Drops the column where an earlier version of this script added it.

ALTER TABLE users
    DROP COLUMN IF EXISTS bypassedit;
