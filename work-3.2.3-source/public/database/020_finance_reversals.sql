SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE finance_entries
  ADD COLUMN reversed_at DATETIME NULL,
  ADD COLUMN reversal_reason VARCHAR(255) NULL,
  ADD COLUMN reversed_by_user_id CHAR(36) NULL;

ALTER TABLE finance_entries
  ADD CONSTRAINT fk_finance_entries_reversed_user
  FOREIGN KEY (reversed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS=1;
