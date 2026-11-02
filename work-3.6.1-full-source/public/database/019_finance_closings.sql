SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS finance_closings (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  account_id CHAR(36) NULL,
  period_type ENUM('daily','monthly') NOT NULL DEFAULT 'daily',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  income_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  expense_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  closing_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  closed_by_user_id CHAR(36) NULL,
  closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_finance_closing_period (church_id,account_id,period_type,period_start,period_end),
  KEY idx_finance_closings_church (church_id,period_end),
  KEY idx_finance_closings_account (account_id),
  CONSTRAINT fk_finance_closings_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_closings_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_closings_user FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
