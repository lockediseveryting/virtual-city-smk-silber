-- 013_totp_auth.sql: 2FA TOTP columns for users
ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0;
