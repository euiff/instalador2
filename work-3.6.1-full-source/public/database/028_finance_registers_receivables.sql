SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS church_registers (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL,
 kind VARCHAR(40) NOT NULL, name VARCHAR(190) NOT NULL,
 details JSON NULL, active TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_register_name(church_id,kind,name),
 KEY idx_register_active(church_id,kind,active),
 FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE members ADD COLUMN IF NOT EXISTS category_id CHAR(36) NULL;
ALTER TABLE members ADD COLUMN IF NOT EXISTS family_id CHAR(36) NULL;
ALTER TABLE members ADD COLUMN IF NOT EXISTS extra_fields JSON NULL;
ALTER TABLE finance_entries ADD COLUMN IF NOT EXISTS cost_center_id CHAR(36) NULL;
ALTER TABLE finance_entries ADD COLUMN IF NOT EXISTS contact_id CHAR(36) NULL;
ALTER TABLE finance_entries ADD COLUMN IF NOT EXISTS receivable_id CHAR(36) NULL;
ALTER TABLE finance_entries ADD KEY idx_receivable_ledger(church_id,receivable_id,status);
ALTER TABLE finance_entries ADD KEY idx_cost_center_ledger(church_id,cost_center_id,transaction_date);

CREATE TABLE IF NOT EXISTS finance_receivables (
 id CHAR(36) PRIMARY KEY, church_id CHAR(36) NOT NULL,
 contact_id CHAR(36) NULL, member_id CHAR(36) NULL,
 account_id CHAR(36) NULL, cost_center_id CHAR(36) NULL,
 payer_name VARCHAR(190) NOT NULL, category VARCHAR(160) NOT NULL,
 description VARCHAR(255) NOT NULL, amount DECIMAL(14,2) NOT NULL,
 due_date DATE NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active',
 batch_id CHAR(36) NOT NULL, installment_number INT NOT NULL DEFAULT 1,
 installment_count INT NOT NULL DEFAULT 1,
 created_by_user_id CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_receivable_due(church_id,status,due_date),
 FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
