-- Igreja Master 3.7.8: additive, tenant-scoped pastoral care
-- Run only after backup and schema compatibility review.
CREATE TABLE IF NOT EXISTS pastoral_cases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  church_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NULL,
  visitor_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  subject VARCHAR(180) NOT NULL,
  status ENUM('open','scheduled','in_progress','closed','cancelled') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  next_contact_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  INDEX ix_pastoral_church_status (church_id,status,deleted_at),
  INDEX ix_pastoral_followup (church_id,next_contact_at,status),
  INDEX ix_pastoral_assignee (church_id,assigned_user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pastoral_case_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  church_id BIGINT UNSIGNED NOT NULL,
  case_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('note','visit','call','message','status_change') NOT NULL,
  happened_at DATETIME NOT NULL,
  summary TEXT NULL,
  is_confidential TINYINT(1) NOT NULL DEFAULT 1,
  recorded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pastoral_event_case (church_id,case_id,happened_at),
  INDEX ix_pastoral_event_actor (church_id,recorded_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pastoral_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  church_id BIGINT UNSIGNED NOT NULL,
  case_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pastoral_audit_church (church_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
