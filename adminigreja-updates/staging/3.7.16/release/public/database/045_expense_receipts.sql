CREATE TABLE IF NOT EXISTS expense_receipts (
 id CHAR(36) NOT NULL PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 entry_id CHAR(36) NOT NULL,
 recipient_name VARCHAR(190) NOT NULL,
 recipient_document VARCHAR(32) NULL,
 status VARCHAR(28) NOT NULL DEFAULT 'pending_signature',
 signature_method VARCHAR(20) NULL,
 finance_document_id CHAR(36) NULL,
 generated_by_user_id CHAR(36) NOT NULL,
 generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 signed_at DATETIME NULL,
 signed_by_user_id CHAR(36) NULL,
 UNIQUE KEY uq_expense_receipt_entry(church_id,entry_id),
 KEY idx_expense_receipt_scope(church_id,status,generated_at),
 KEY idx_expense_receipt_finance_doc(church_id,finance_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
