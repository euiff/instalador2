SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE church_members
  ADD COLUMN IF NOT EXISTS church_role VARCHAR(50) NOT NULL DEFAULT 'admin' AFTER is_owner,
  ADD COLUMN IF NOT EXISTS permissions JSON NULL AFTER church_role;

CREATE TABLE IF NOT EXISTS finance_accounts (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  account_type VARCHAR(40) NOT NULL DEFAULT 'cash',
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  legacy_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_account_legacy (church_id,legacy_id),
  KEY idx_finance_accounts_church (church_id,active),
  CONSTRAINT fk_finance_accounts_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_entries (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  account_id CHAR(36) NULL,
  member_id CHAR(36) NULL,
  direction ENUM('income','expense') NOT NULL,
  income_kind VARCHAR(80) NULL,
  category VARCHAR(160) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  transaction_date DATE NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'posted',
  receipt_path VARCHAR(255) NULL,
  receipt_name VARCHAR(255) NULL,
  receipt_mime VARCHAR(120) NULL,
  receipt_size INT UNSIGNED NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'manual',
  legacy_id BIGINT UNSIGNED NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_entry_legacy (church_id,legacy_id),
  KEY idx_finance_entries_church_date (church_id,transaction_date),
  KEY idx_finance_entries_direction (church_id,direction,status),
  KEY idx_finance_entries_account (account_id),
  CONSTRAINT fk_finance_entries_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_entries_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_entries_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_entries_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_payables (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  supplier VARCHAR(190) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'open',
  paid_at DATE NULL,
  legacy_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_payable_legacy (church_id,legacy_id),
  KEY idx_finance_payables_church_due (church_id,due_date,status),
  CONSTRAINT fk_finance_payables_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_budgets (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  year INT NOT NULL,
  month TINYINT NULL,
  category VARCHAR(160) NOT NULL,
  planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_budget (church_id,year,month,category),
  CONSTRAINT fk_finance_budgets_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_reconciliations (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  reference_date DATE NOT NULL,
  statement_balance DECIMAL(14,2) NOT NULL,
  system_balance DECIMAL(14,2) NOT NULL,
  difference_amount DECIMAL(14,2) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_reconciliation (account_id,reference_date),
  CONSTRAINT fk_finance_reconciliation_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_reconciliation_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
