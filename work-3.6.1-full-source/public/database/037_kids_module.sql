SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS kids_classes (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  min_age_months INT UNSIGNED NULL,
  max_age_months INT UNSIGNED NULL,
  description VARCHAR(1000) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_classes_church (church_id,active,name),
  CONSTRAINT fk_kids_classes_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_rooms (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  location_name VARCHAR(190) NULL,
  capacity INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_rooms_class (church_id,class_id,active),
  CONSTRAINT fk_kids_rooms_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_rooms_class FOREIGN KEY(class_id) REFERENCES kids_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_children (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  member_id CHAR(36) NULL,
  full_name VARCHAR(190) NOT NULL,
  birth_date DATE NULL,
  gender VARCHAR(20) NULL,
  allergies TEXT NULL,
  special_needs TEXT NULL,
  image_authorized TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_children_church (church_id,active,full_name),
  KEY idx_kids_children_member (church_id,member_id),
  CONSTRAINT fk_kids_children_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_children_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_guardians (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  child_id CHAR(36) NOT NULL,
  member_id CHAR(36) NULL,
  full_name VARCHAR(190) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  relationship_name VARCHAR(80) NULL,
  primary_contact TINYINT(1) NOT NULL DEFAULT 0,
  authorized_pickup TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_guardians_child (church_id,child_id),
  KEY idx_kids_guardians_phone (church_id,phone),
  CONSTRAINT fk_kids_guardians_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_guardians_child FOREIGN KEY(child_id) REFERENCES kids_children(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_guardians_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_team (
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  access_level VARCHAR(30) NOT NULL DEFAULT 'worker',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(class_id,member_id),
  KEY idx_kids_team_member (church_id,member_id,active),
  CONSTRAINT fk_kids_team_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_team_class FOREIGN KEY(class_id) REFERENCES kids_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_team_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_programs (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  room_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  program_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  public_token CHAR(32) NOT NULL,
  notes TEXT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_programs_date (church_id,program_date,status),
  KEY idx_kids_programs_room (church_id,room_id,program_date),
  UNIQUE KEY uq_kids_program_token (church_id,public_token),
  CONSTRAINT fk_kids_programs_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_programs_class FOREIGN KEY(class_id) REFERENCES kids_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_programs_room FOREIGN KEY(room_id) REFERENCES kids_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_checkins (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  program_id CHAR(36) NOT NULL,
  child_id CHAR(36) NOT NULL,
  room_id CHAR(36) NOT NULL,
  checkin_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checked_in_by_user_id CHAR(36) NULL,
  checkout_at DATETIME NULL,
  checked_out_by_user_id CHAR(36) NULL,
  pickup_guardian_id CHAR(36) NULL,
  security_code VARCHAR(12) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'checked_in',
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_kids_active_program_child (program_id,child_id),
  KEY idx_kids_checkins_status (church_id,program_id,status),
  KEY idx_kids_checkins_child (church_id,child_id,checkin_at),
  CONSTRAINT fk_kids_checkins_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_checkins_program FOREIGN KEY(program_id) REFERENCES kids_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_checkins_child FOREIGN KEY(child_id) REFERENCES kids_children(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_checkins_room FOREIGN KEY(room_id) REFERENCES kids_rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_checkins_guardian FOREIGN KEY(pickup_guardian_id) REFERENCES kids_guardians(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kids_records (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  program_id CHAR(36) NOT NULL,
  child_id CHAR(36) NULL,
  record_type VARCHAR(30) NOT NULL DEFAULT 'general',
  content TEXT NOT NULL,
  media_item_id CHAR(36) NULL,
  visible_to_guardian TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_kids_records_program (church_id,program_id,created_at),
  KEY idx_kids_records_child (church_id,child_id,created_at),
  CONSTRAINT fk_kids_records_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_records_program FOREIGN KEY(program_id) REFERENCES kids_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_records_child FOREIGN KEY(child_id) REFERENCES kids_children(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_records_media FOREIGN KEY(media_item_id) REFERENCES media_library_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS kids_checkin_requests (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  program_id CHAR(36) NOT NULL,
  child_id CHAR(36) NULL,
  child_name VARCHAR(190) NOT NULL,
  birth_date DATE NULL,
  guardian_name VARCHAR(190) NOT NULL,
  guardian_phone VARCHAR(60) NOT NULL,
  request_type VARCHAR(30) NOT NULL DEFAULT 'existing',
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  notes VARCHAR(1000) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by_user_id CHAR(36) NULL,
  PRIMARY KEY(id),
  KEY idx_kids_checkin_requests (church_id,program_id,status,requested_at),
  CONSTRAINT fk_kids_requests_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_requests_program FOREIGN KEY(program_id) REFERENCES kids_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_kids_requests_child FOREIGN KEY(child_id) REFERENCES kids_children(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
