SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS small_groups (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  name VARCHAR(190) NOT NULL,
  group_type VARCHAR(80) NOT NULL DEFAULT 'cell',
  photo_url VARCHAR(500) NULL,
  meeting_day VARCHAR(30) NULL,
  meeting_time TIME NULL,
  location_name VARCHAR(190) NULL,
  address VARCHAR(500) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_small_groups_church (church_id,active,name),
  KEY idx_small_groups_congregation (church_id,congregation_id),
  CONSTRAINT fk_small_groups_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_groups_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS small_group_leaders (
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  leader_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id,member_id),
  UNIQUE KEY uq_small_group_leader_order (group_id,leader_order),
  KEY idx_small_group_leader_member (church_id,member_id),
  CONSTRAINT fk_small_group_leaders_group FOREIGN KEY (group_id) REFERENCES small_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_leaders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_leaders_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS small_group_members (
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  joined_on DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id,member_id),
  KEY idx_small_group_members_member (church_id,member_id),
  CONSTRAINT fk_small_group_members_group FOREIGN KEY (group_id) REFERENCES small_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_members_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_members_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS small_group_meetings (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NOT NULL,
  meeting_date DATE NOT NULL,
  theme VARCHAR(255) NULL,
  visitor_count INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  selfie_url VARCHAR(500) NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_small_group_meetings (church_id,group_id,meeting_date),
  CONSTRAINT fk_small_group_meetings_group FOREIGN KEY (group_id) REFERENCES small_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_meetings_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS small_group_attendance (
  church_id CHAR(36) NOT NULL,
  meeting_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  present TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (meeting_id,member_id),
  KEY idx_small_group_attendance_member (church_id,member_id),
  CONSTRAINT fk_small_group_attendance_meeting FOREIGN KEY (meeting_id) REFERENCES small_group_meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_attendance_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_small_group_attendance_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
