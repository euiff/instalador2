SET NAMES utf8mb4;
-- Igreja Master 3.7.9: additive accounting handoff. No existing accounting rows altered.
CREATE TABLE IF NOT EXISTS accounting_documents (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 finance_entry_id CHAR(36) NULL,
 finance_document_id CHAR(36) NULL,
 document_kind VARCHAR(32) NOT NULL DEFAULT 'fiscal_coupon',
 reference_period CHAR(7) NOT NULL,
 supplier_name VARCHAR(190) NULL,
 supplier_cnpj CHAR(14) NULL,
 document_number VARCHAR(60) NULL,
 access_key CHAR(44) NULL,
 issued_on DATE NULL,
 total DECIMAL(14,2) NULL,
 suggested_category VARCHAR(160) NULL,
 confirmed_category VARCHAR(160) NULL,
 item_details_json LONGTEXT NULL,
 ocr_excerpt TEXT NULL,
 original_name VARCHAR(250) NOT NULL,
 storage_path VARCHAR(480) NOT NULL,
 mime_type VARCHAR(90) NOT NULL,
 file_size BIGINT NOT NULL,
 sha256 CHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'draft',
 reviewed_by_user_id CHAR(36) NULL,
 reviewed_at DATETIME NULL,
 review_note TEXT NULL,
 created_by_user_id CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_accounting_doc_hash(church_id,sha256),
 KEY idx_accounting_doc_period (church_id,reference_period,status),
 KEY idx_accounting_doc_entry (church_id,finance_entry_id),
 CONSTRAINT fk_accounting_doc_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_requests (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 reference_period CHAR(7) NOT NULL,
 title VARCHAR(190) NOT NULL,
 details TEXT NULL,
 requested_by_user_id CHAR(36) NOT NULL,
 responded_document_id CHAR(36) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 KEY idx_accounting_requests (church_id,reference_period,status),
 CONSTRAINT fk_accounting_req_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_periods (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 reference_period CHAR(7) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open',
 submitted_by_user_id CHAR(36) NULL,
 submitted_at DATETIME NULL,
 reviewed_by_user_id CHAR(36) NULL,
 reviewed_at DATETIME NULL,
 notes TEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_accounting_period (church_id,reference_period),
 CONSTRAINT fk_accounting_period_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_audit (
 id CHAR(36) PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 actor_user_id CHAR(36) NOT NULL,
 document_id CHAR(36) NULL,
 reference_period CHAR(7) NULL,
 action VARCHAR(50) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_accounting_audit (church_id,created_at),
 CONSTRAINT fk_accounting_audit_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
