SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS finance_import_batches (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL,
 file_hash CHAR(64) NOT NULL, row_count INT NOT NULL,
 created_by_user_id CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_import_file(church_id,file_hash),
 FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
