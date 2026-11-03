-- Igreja Master 3.7.13 — Vínculos de ministérios com membros, sem destruir dados anteriores.
CREATE TABLE IF NOT EXISTS member_department_roles (
 church_id CHAR(36) NOT NULL,
 member_id CHAR(36) NOT NULL,
 department_id CHAR(36) NOT NULL,
 role_name VARCHAR(25) NOT NULL DEFAULT 'participant',
 assigned_by_user_id CHAR(36) NOT NULL,
 assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(church_id,member_id,department_id),
 KEY idx_member_department_scope(church_id,department_id,role_name),
 KEY idx_member_department_person(church_id,member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
