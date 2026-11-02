SET NAMES utf8mb4;

ALTER TABLE media_library_folders
  ADD COLUMN folder_type VARCHAR(30) NOT NULL DEFAULT 'folder' AFTER name,
  ADD KEY idx_media_folder_type (church_id,folder_type,active);

CREATE TABLE IF NOT EXISTS study_categories (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_study_categories_church (church_id,active,sort_order,name),
  CONSTRAINT fk_study_categories_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS church_studies (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  category_id CHAR(36) NULL,
  title VARCHAR(190) NOT NULL,
  summary VARCHAR(1000) NULL,
  body LONGTEXT NULL,
  cover_media_id CHAR(36) NULL,
  attachment_media_id CHAR(36) NULL,
  external_url VARCHAR(1000) NULL,
  audience_type VARCHAR(30) NOT NULL DEFAULT 'all',
  target_id CHAR(36) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_church_studies_church (church_id,active,published_at),
  KEY idx_church_studies_category (church_id,category_id),
  KEY idx_church_studies_target (church_id,audience_type,target_id),
  CONSTRAINT fk_church_studies_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_church_studies_category FOREIGN KEY(category_id) REFERENCES study_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_church_studies_cover FOREIGN KEY(cover_media_id) REFERENCES media_library_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_church_studies_attachment FOREIGN KEY(attachment_media_id) REFERENCES media_library_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS church_study_reads (
  church_id CHAR(36) NOT NULL,
  study_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(study_id,user_id),
  KEY idx_study_reads_user (church_id,user_id,read_at),
  CONSTRAINT fk_study_reads_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_study_reads_study FOREIGN KEY(study_id) REFERENCES church_studies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
