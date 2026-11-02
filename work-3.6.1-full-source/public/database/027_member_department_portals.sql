SET NAMES utf8mb4;

ALTER TABLE church_members ADD COLUMN IF NOT EXISTS person_id CHAR(36) NULL;
ALTER TABLE church_members ADD UNIQUE KEY uq_portal_person (church_id,person_id);
ALTER TABLE finance_accounts ADD COLUMN IF NOT EXISTS department_id CHAR(36) NULL;
ALTER TABLE finance_accounts ADD UNIQUE KEY uq_department_account (church_id,department_id);
ALTER TABLE finance_entries ADD COLUMN IF NOT EXISTS department_id CHAR(36) NULL;
ALTER TABLE finance_entries ADD COLUMN IF NOT EXISTS request_key CHAR(64) NULL;
ALTER TABLE finance_entries ADD UNIQUE KEY uq_finance_request (church_id,request_key);
ALTER TABLE finance_entries ADD KEY idx_department_ledger (church_id,department_id,transaction_date);

CREATE TABLE IF NOT EXISTS department_access (
 church_id CHAR(36) NOT NULL,
 user_id CHAR(36) NOT NULL,
 department_id CHAR(36) NOT NULL,
 PRIMARY KEY(church_id,user_id,department_id),
 FOREIGN KEY(church_id,user_id) REFERENCES church_members(church_id,user_id) ON DELETE CASCADE,
 FOREIGN KEY(department_id) REFERENCES ministry_departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
