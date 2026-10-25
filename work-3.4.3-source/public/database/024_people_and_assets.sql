CREATE TABLE IF NOT EXISTS church_visitors (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL,
 phone VARCHAR(40) NULL, first_visit DATE NOT NULL, next_contact DATE NULL,
 responsible VARCHAR(190) NULL, status VARCHAR(30) NOT NULL DEFAULT 'new',
 notes TEXT NULL, member_id CHAR(36) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_visitors_due(church_id,status,next_contact),
 FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pastoral_care (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL, member_id CHAR(36) NOT NULL,
 kind VARCHAR(30) NOT NULL, occurred_on DATE NOT NULL, next_contact DATE NULL,
 responsible VARCHAR(190) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'open',
 notes TEXT NULL, created_by CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_care_due(church_id,status,next_contact),
 FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ministry_departments (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL,
 leader VARCHAR(190) NULL, meeting_info VARCHAR(255) NULL, active TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ministry_name(church_id,name),
 FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ministry_members (
 department_id CHAR(36) NOT NULL, member_id CHAR(36) NOT NULL, church_id CHAR(36) NOT NULL,
 joined_on DATE NOT NULL, PRIMARY KEY(department_id,member_id),
 KEY idx_ministry_church(church_id),
 FOREIGN KEY (department_id) REFERENCES ministry_departments(id) ON DELETE CASCADE,
 FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS church_assets (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL, code VARCHAR(80) NOT NULL,
 name VARCHAR(255) NOT NULL, category VARCHAR(120) NULL, location VARCHAR(190) NULL,
 responsible VARCHAR(190) NULL, acquired_on DATE NULL, acquisition_value DECIMAL(12,2) NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'good', notes TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_asset_code(church_id,code),
 FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_maintenance (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL, asset_id CHAR(36) NOT NULL,
 performed_on DATE NOT NULL, next_due DATE NULL, description TEXT NOT NULL,
 cost DECIMAL(12,2) NOT NULL DEFAULT 0, responsible VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_maintenance_due(church_id,next_due),
 FOREIGN KEY (asset_id) REFERENCES church_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
