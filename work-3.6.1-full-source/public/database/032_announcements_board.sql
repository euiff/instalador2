SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS church_announcements (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  audience_type VARCHAR(30) NOT NULL DEFAULT 'all',
  target_id CHAR(36) NULL,
  link_url VARCHAR(1000) NULL,
  attachment_url VARCHAR(1000) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_church_announcements_active (church_id,active,starts_at,ends_at),
  KEY idx_church_announcements_target (church_id,audience_type,target_id),
  CONSTRAINT fk_church_announcements_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS church_announcement_reads (
  church_id CHAR(36) NOT NULL,
  announcement_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(announcement_id,user_id),
  KEY idx_announcement_reads_user (church_id,user_id,read_at),
  CONSTRAINT fk_announcement_reads_announcement FOREIGN KEY(announcement_id) REFERENCES church_announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_reads_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
