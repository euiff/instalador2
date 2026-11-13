-- Subitens de um lançamento financeiro agrupado: a conta movimenta-se UMA vez pelo total.
CREATE TABLE IF NOT EXISTS finance_entry_items (
  id CHAR(36) NOT NULL PRIMARY KEY,
  church_id CHAR(36) NOT NULL,
  entry_id CHAR(36) NOT NULL,
  item_index INT UNSIGNED NOT NULL,
  category VARCHAR(160) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  contributor VARCHAR(190) NULL,
  member_id CHAR(36) NULL,
  document_number VARCHAR(190) NULL,
  payment_method VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entry_item_position(entry_id,item_index),
  KEY idx_finance_items_church_entry(church_id,entry_id),
  CONSTRAINT fk_finance_item_entry FOREIGN KEY(entry_id) REFERENCES finance_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_item_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_item_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_entries
  ADD COLUMN IF NOT EXISTS is_grouped TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE finance_documents
  ADD COLUMN IF NOT EXISTS entry_item_id CHAR(36) NULL;

ALTER TABLE finance_documents
  ADD INDEX idx_finance_document_item(entry_item_id);
