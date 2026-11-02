SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE finance_entries
  ADD COLUMN pix_payment_id CHAR(36) NULL AFTER legacy_id;

ALTER TABLE finance_entries
  ADD UNIQUE KEY uq_finance_entry_pix (pix_payment_id);

ALTER TABLE finance_entries
  ADD CONSTRAINT fk_finance_entry_pix FOREIGN KEY (pix_payment_id) REFERENCES pix_payments(id) ON DELETE SET NULL;

INSERT IGNORE INTO finance_entries(
  id,church_id,member_id,direction,income_kind,category,description,amount,transaction_date,status,source,pix_payment_id,created_at
)
SELECT UUID(),p.church_id,p.member_id,'income',p.payment_type,
       CASE p.payment_type
         WHEN 'dizimo' THEN 'Dízimos'
         WHEN 'oferta' THEN 'Ofertas'
         WHEN 'campanha' THEN 'Campanhas'
         WHEN 'evento' THEN 'Eventos'
         ELSE 'Outras receitas'
       END,
       CONCAT('PIX - ',COALESCE(NULLIF(p.name,''),p.phone)),
       p.amount,DATE(COALESCE(p.paid_at,p.created_at)),'posted','pix',p.id,p.created_at
FROM pix_payments p
WHERE p.status='confirmed';

SET FOREIGN_KEY_CHECKS=1;
