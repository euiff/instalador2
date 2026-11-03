CREATE TABLE IF NOT EXISTS master_congregation_moves (
 id CHAR(36) PRIMARY KEY,batch_id CHAR(36) NOT NULL,church_id CHAR(36) NOT NULL,
 member_id CHAR(36) NOT NULL,old_congregation_id CHAR(36) NULL,new_congregation_id CHAR(36) NOT NULL,
 created_by_user_id CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reverted_at DATETIME NULL,reverted_by_user_id CHAR(36) NULL,
 KEY idx_bulk_congregation(church_id,batch_id),KEY idx_bulk_member(church_id,member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
