SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE churches
  ADD COLUMN IF NOT EXISTS monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER plan,
  ADD COLUMN IF NOT EXISTS legacy_due_date DATE NULL AFTER monthly_fee;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS attendance_count INT NOT NULL DEFAULT 0 AFTER capacity;

CREATE TABLE IF NOT EXISTS legacy_entity_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(80) NOT NULL,
  legacy_id VARCHAR(190) NOT NULL,
  new_id CHAR(36) NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_entity (entity_type,legacy_id),
  KEY idx_legacy_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(120) NOT NULL,
  status VARCHAR(40) NOT NULL,
  details JSON NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_legacy_import_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
