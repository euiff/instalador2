SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE churches
  ADD COLUMN IF NOT EXISTS subscription_status VARCHAR(40) NOT NULL DEFAULT 'active' AFTER plan,
  ADD COLUMN IF NOT EXISTS trial_ends_at DATETIME NULL AFTER subscription_status,
  ADD COLUMN IF NOT EXISTS subscription_ends_at DATETIME NULL AFTER trial_ends_at,
  ADD COLUMN IF NOT EXISTS max_users INT NULL AFTER subscription_ends_at,
  ADD COLUMN IF NOT EXISTS master_notes TEXT NULL AFTER max_users;

CREATE TABLE IF NOT EXISTS platform_audit_logs (
  id CHAR(36) NOT NULL,
  user_id CHAR(36) NULL,
  church_id CHAR(36) NULL,
  action VARCHAR(120) NOT NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_platform_audit_created (created_at),
  KEY idx_platform_audit_church (church_id),
  CONSTRAINT fk_platform_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_platform_audit_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
