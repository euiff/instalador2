SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS issued_documents (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  document_type VARCHAR(80) NOT NULL,
  verification_code VARCHAR(190) NOT NULL,
  issued_by CHAR(36) NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  metadata JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_issued_document_code (verification_code),
  KEY idx_issued_documents_member (member_id,document_type),
  KEY idx_issued_documents_church (church_id,issued_at),
  CONSTRAINT fk_issued_documents_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_issued_documents_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_issued_documents_user FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
