SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE finance_payables
  ADD COLUMN account_id CHAR(36) NULL AFTER church_id;

ALTER TABLE finance_payables
  ADD CONSTRAINT fk_finance_payables_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL;

ALTER TABLE finance_entries
  ADD COLUMN payable_id CHAR(36) NULL AFTER pix_payment_id;

ALTER TABLE finance_entries
  ADD UNIQUE KEY uq_finance_entry_payable (payable_id);

ALTER TABLE finance_entries
  ADD CONSTRAINT fk_finance_entry_payable FOREIGN KEY (payable_id) REFERENCES finance_payables(id) ON DELETE SET NULL;

INSERT IGNORE INTO finance_entries(
  id,church_id,account_id,direction,category,description,amount,transaction_date,status,source,payable_id,created_at
)
SELECT UUID(),p.church_id,p.account_id,'expense','Contas a pagar',
       CONCAT(p.supplier,' - ',p.description),p.amount,
       COALESCE(p.paid_at,p.due_date),'posted','payable',p.id,p.created_at
FROM finance_payables p
WHERE p.status='paid';

SET FOREIGN_KEY_CHECKS=1;
