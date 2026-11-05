CREATE TABLE IF NOT EXISTS finance_entry_items (
 id CHAR(36) NOT NULL PRIMARY KEY,
 church_id CHAR(36) NOT NULL,
 entry_id CHAR(36) NOT NULL,
 finance_document_id CHAR(36) NOT NULL,
 description VARCHAR(160) NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 category VARCHAR(160) NOT NULL,
 created_by_user_id CHAR(36) DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_finance_items_church_period (church_id,entry_id),
 INDEX idx_finance_items_doc (church_id,finance_document_id),
 CONSTRAINT fk_finance_entry_items_entry FOREIGN KEY (entry_id)
   REFERENCES finance_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
