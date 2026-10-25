SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE prayer_clocks ADD COLUMN notification_image_path VARCHAR(255) NULL;
ALTER TABLE prayer_clocks ADD COLUMN last_notification_sent_at DATETIME NULL;

ALTER TABLE raffles ADD COLUMN notification_image_path VARCHAR(255) NULL;
ALTER TABLE raffles ADD COLUMN last_notification_sent_at DATETIME NULL;

ALTER TABLE events ADD COLUMN notification_image_path VARCHAR(255) NULL;
ALTER TABLE events ADD COLUMN last_notification_sent_at DATETIME NULL;

ALTER TABLE service_types ADD COLUMN target_group_ids JSON NULL;
ALTER TABLE service_types ADD COLUMN notification_image_path VARCHAR(255) NULL;
ALTER TABLE service_types ADD COLUMN last_notification_sent_at DATETIME NULL;

ALTER TABLE finance_entries ADD COLUMN counterparty VARCHAR(190) NULL;
ALTER TABLE finance_entries ADD COLUMN payment_method VARCHAR(60) NULL;
ALTER TABLE finance_entries ADD COLUMN document_number VARCHAR(120) NULL;
ALTER TABLE finance_entries ADD COLUMN notes TEXT NULL;

ALTER TABLE finance_payables ADD COLUMN document_number VARCHAR(120) NULL;
ALTER TABLE finance_payables ADD COLUMN notes TEXT NULL;

CREATE TABLE IF NOT EXISTS finance_documents (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  entry_id CHAR(36) NULL,
  payable_id CHAR(36) NULL,
  document_type VARCHAR(50) NOT NULL DEFAULT 'proof',
  document_number VARCHAR(120) NULL,
  issued_at DATE NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_finance_documents_church (church_id,created_at),
  KEY idx_finance_documents_entry (entry_id),
  KEY idx_finance_documents_payable (payable_id),
  CONSTRAINT fk_finance_documents_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_documents_entry FOREIGN KEY (entry_id) REFERENCES finance_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_documents_payable FOREIGN KEY (payable_id) REFERENCES finance_payables(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_documents_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_receipts (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  receipt_number INT UNSIGNED NOT NULL,
  finance_entry_id CHAR(36) NULL,
  member_id CHAR(36) NULL,
  payer_name VARCHAR(190) NOT NULL,
  payer_document VARCHAR(60) NULL,
  amount DECIMAL(14,2) NOT NULL,
  description VARCHAR(255) NOT NULL,
  payment_method VARCHAR(60) NULL,
  received_at DATE NOT NULL,
  notes TEXT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_receipt_number (church_id,receipt_number),
  KEY idx_finance_receipts_church_date (church_id,received_at),
  KEY idx_finance_receipts_entry (finance_entry_id),
  CONSTRAINT fk_finance_receipts_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_receipts_entry FOREIGN KEY (finance_entry_id) REFERENCES finance_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_receipts_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_receipts_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
