-- Open registration: drop per-competitor invite tokens.
-- Run once on existing DBs. Fresh installs use schema.sql.
-- Deletes unused invite placeholder rows (status=invited) before dropping columns.

DELETE FROM competitors WHERE status = 'invited';

ALTER TABLE competitors
    DROP INDEX uq_invite_token,
    DROP COLUMN invite_token,
    DROP COLUMN expires_at,
    DROP COLUMN revoked_at;
