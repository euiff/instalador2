SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS finance_period_locks (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL,
 period_start DATE NOT NULL, period_end DATE NOT NULL,
 reason VARCHAR(1000) NOT NULL, active TINYINT NOT NULL DEFAULT 1,
 created_by_user_id CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reopened_by_user_id CHAR(36) NULL, reopened_at DATETIME NULL,
 KEY idx_period_lock(church_id,active,period_start,period_end),
 FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
