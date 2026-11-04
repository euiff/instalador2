CREATE TABLE IF NOT EXISTS system_course_progress (
 church_id CHAR(36) NOT NULL,
 user_id CHAR(36) NOT NULL,
 lesson_slug VARCHAR(100) NOT NULL,
 completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,user_id,lesson_slug),
 KEY idx_system_course_user(user_id,church_id),
 CONSTRAINT fk_system_course_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
 CONSTRAINT fk_system_course_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
