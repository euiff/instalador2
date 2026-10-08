SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE churches
  ADD COLUMN transparency_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER master_notes;

CREATE TABLE IF NOT EXISTS church_needs (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
  estimated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  priority VARCHAR(30) NOT NULL DEFAULT 'normal',
  status VARCHAR(30) NOT NULL DEFAULT 'requested',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_church_needs_status (church_id,status,priority),
  CONSTRAINT fk_church_needs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
