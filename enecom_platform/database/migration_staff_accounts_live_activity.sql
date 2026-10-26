-- Enecom staff account generation and live activity monitoring.
-- BACK UP FIRST. Select the existing enecom_platform database and run this migration ONCE.
ALTER TABLE users
  ADD COLUMN username VARCHAR(100) NULL AFTER email,
  ADD COLUMN department VARCHAR(100) NULL AFTER role,
  ADD COLUMN staff_code VARCHAR(100) NULL AFTER department,
  ADD COLUMN last_seen_at DATETIME NULL AFTER updated_at;

CREATE UNIQUE INDEX uq_users_username ON users (username);
CREATE UNIQUE INDEX uq_users_staff_code ON users (staff_code);
CREATE INDEX idx_users_role_last_seen ON users (role, is_active, last_seen_at);
