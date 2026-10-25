SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS automation_deliveries (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  automation_type VARCHAR(80) NOT NULL,
  target_key VARCHAR(255) NOT NULL,
  delivery_date DATE NOT NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_automation_delivery (church_id,automation_type,target_key,delivery_date),
  KEY idx_automation_delivery_date (delivery_date),
  CONSTRAINT fk_automation_delivery_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_automation_delivery_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
