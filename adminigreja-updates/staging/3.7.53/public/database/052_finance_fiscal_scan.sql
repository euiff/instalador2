-- Iglesia Master 3.7.52: document identity and duplicate detection per church.
-- Nullable UNIQUE allows historic documents and non-fiscal uploads to coexist.
ALTER TABLE finance_documents ADD COLUMN IF NOT EXISTS file_sha256 CHAR(64) NULL;
ALTER TABLE finance_documents ADD COLUMN IF NOT EXISTS fiscal_access_key CHAR(44) NULL;
ALTER TABLE finance_documents ADD INDEX idx_finance_document_hash (church_id,file_sha256);
ALTER TABLE finance_documents ADD UNIQUE KEY uq_finance_document_fiscal (church_id,fiscal_access_key);
