SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS pastoral_cases (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 visitor_id CHAR(36) NULL,
 member_id CHAR(36) NULL,
 assigned_user_id CHAR(36) NULL,
 created_by_user_id CHAR(36) NOT NULL,
 subject VARCHAR(180) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'open',
 priority VARCHAR(16) NOT NULL DEFAULT 'normal',
 next_contact_on DATE NULL,
 closed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL,
 KEY idx_pastoral_cases_church (church_id,status,deleted_at),
 KEY idx_pastoral_cases_due (church_id,next_contact_on,status),
 KEY idx_pastoral_cases_assignee (church_id,assigned_user_id),
 KEY idx_pastoral_cases_visitor (church_id,visitor_id),
 KEY idx_pastoral_cases_member (church_id,member_id),
 CONSTRAINT fk_pastoral_cases_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pastoral_case_events (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 case_id CHAR(36) NOT NULL,
 created_by_user_id CHAR(36) NOT NULL,
 kind VARCHAR(24) NOT NULL,
 happened_on DATE NOT NULL,
 summary TEXT NULL,
 confidential TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_pastoral_event_church_case (church_id,case_id,happened_on),
 CONSTRAINT fk_pastoral_event_case FOREIGN KEY(case_id) REFERENCES pastoral_cases(id) ON DELETE CASCADE,
 CONSTRAINT fk_pastoral_event_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pastoral_case_audit (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 case_id CHAR(36) NOT NULL,
 actor_user_id CHAR(36) NOT NULL,
 action VARCHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_pastoral_case_audit (church_id,case_id,created_at),
 CONSTRAINT fk_pastoral_audit_case FOREIGN KEY(case_id) REFERENCES pastoral_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
