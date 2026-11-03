SET NAMES utf8mb4;
-- Igreja Master 3.7.12: additive, tenant-scoped migration metadata only.
-- Uploaded spreadsheets stay in temporary private storage, never committed to Git.
CREATE TABLE IF NOT EXISTS enuves_import_batches (
 id CHAR(36) NOT NULL PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 source_kind VARCHAR(32) NOT NULL DEFAULT 'enuves_people',
 file_sha256 CHAR(64) NOT NULL,
 rows_seen INT NOT NULL DEFAULT 0,
 members_created INT NOT NULL DEFAULT 0,
 linked_existing INT NOT NULL DEFAULT 0,
 skipped INT NOT NULL DEFAULT 0,
 departments_created INT NOT NULL DEFAULT 0,
 interests_created INT NOT NULL DEFAULT 0,
 status VARCHAR(24) NOT NULL DEFAULT 'imported',
 imported_by_user_id CHAR(36) NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_enuves_batch_church_checksum(church_id,file_sha256),
 KEY idx_enuves_batch_church(church_id,imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enuves_import_people (
 church_id CHAR(36) NOT NULL,
 source_id VARCHAR(60) NOT NULL,
 member_id CHAR(36) NOT NULL,
 batch_id CHAR(36) NOT NULL,
 source_row INT NOT NULL,
 source_sha256 CHAR(64) NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,source_id),
 KEY idx_enuves_import_person(church_id,member_id),
 KEY idx_enuves_import_batch(church_id,batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enuves_people_details (
 church_id CHAR(36) NOT NULL,
 member_id CHAR(36) NOT NULL,
 source_id VARCHAR(60) NOT NULL,
 detail_json LONGTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,member_id),
 KEY idx_enuves_details_source(church_id,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity preferences are prospective interests, not confirmed participation or leadership.
CREATE TABLE IF NOT EXISTS enuves_department_interests (
 church_id CHAR(36) NOT NULL,
 member_id CHAR(36) NOT NULL,
 department_id CHAR(36) NOT NULL,
 interest_label VARCHAR(190) NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,member_id,department_id),
 KEY idx_enuves_interest_department(church_id,department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit designations by Master: never infer a leader from a voluntary interest field.
CREATE TABLE IF NOT EXISTS enuves_department_leaders (
 church_id CHAR(36) NOT NULL,
 department_id CHAR(36) NOT NULL,
 member_id CHAR(36) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending_login',
 assigned_by_user_id CHAR(36) NOT NULL,
 assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,department_id,member_id),
 KEY idx_enuves_leader_member(church_id,member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
