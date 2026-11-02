SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS media_library_folders (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_media_folders_church (church_id,active,sort_order,name),
  CONSTRAINT fk_media_folders_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_library_items (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  folder_id CHAR(36) NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  category VARCHAR(120) NULL,
  media_type VARCHAR(30) NOT NULL DEFAULT 'document',
  storage_path VARCHAR(500) NULL,
  external_url VARCHAR(1000) NULL,
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  audience_type VARCHAR(30) NOT NULL DEFAULT 'all',
  target_id CHAR(36) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_media_items_church (church_id,active,media_type,created_at),
  KEY idx_media_items_folder (church_id,folder_id),
  KEY idx_media_items_target (church_id,audience_type,target_id),
  CONSTRAINT fk_media_items_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_media_items_folder FOREIGN KEY(folder_id) REFERENCES media_library_folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
