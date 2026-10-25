SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS church_groups (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  name VARCHAR(190) NOT NULL,
  group_type VARCHAR(60) NOT NULL DEFAULT 'whatsapp',
  evolution_group_jid VARCHAR(190) NULL,
  invite_link TEXT NULL,
  responsible_name VARCHAR(190) NULL,
  description TEXT NULL,
  purposes JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_church_group_jid (church_id,evolution_group_jid),
  KEY idx_church_groups_congregation (church_id,congregation_id,active),
  CONSTRAINT fk_church_groups_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_church_groups_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_campaigns (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  message LONGTEXT NOT NULL,
  campaign_type VARCHAR(60) NOT NULL DEFAULT 'announcement',
  target_group_ids JSON NULL,
  scheduled_at DATETIME NULL,
  sent_at DATETIME NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  created_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_campaigns_church_status (church_id,status,scheduled_at),
  CONSTRAINT fk_campaigns_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_deliveries (
  id CHAR(36) NOT NULL,
  campaign_id CHAR(36) NULL,
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NULL,
  target_jid VARCHAR(190) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_campaign (campaign_id,status),
  KEY idx_delivery_church (church_id,created_at),
  CONSTRAINT fk_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES communication_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_group FOREIGN KEY (group_id) REFERENCES church_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO church_groups(
  id,church_id,congregation_id,name,group_type,evolution_group_jid,description,purposes,active
)
SELECT
  UUID(),c.church_id,c.id,CONCAT(c.name,' · Grupo principal'),'whatsapp',
  c.whatsapp_group_id,'Importado da configuração anterior da congregação',
  JSON_ARRAY('events','raffles','prayer_clock','polls','devotional','announcements'),1
FROM congregations c
WHERE c.whatsapp_group_id IS NOT NULL AND TRIM(c.whatsapp_group_id)<>'';

INSERT IGNORE INTO church_groups(
  id,church_id,congregation_id,name,group_type,evolution_group_jid,description,purposes,active
)
SELECT
  UUID(),ch.id,NULL,CONCAT(ch.name,' · Grupo principal'),'whatsapp',
  ch.whatsapp_group_id,'Importado da configuração anterior da igreja',
  JSON_ARRAY('events','raffles','prayer_clock','polls','devotional','announcements'),1
FROM churches ch
WHERE ch.whatsapp_group_id IS NOT NULL AND TRIM(ch.whatsapp_group_id)<>'';

ALTER TABLE events
  ADD COLUMN target_group_ids JSON NULL AFTER reminder_sent;

ALTER TABLE raffles
  ADD COLUMN target_group_ids JSON NULL AFTER status,
  ADD COLUMN announcement_sent_at DATETIME NULL AFTER target_group_ids,
  ADD COLUMN reminder_sent_at DATETIME NULL AFTER announcement_sent_at;

ALTER TABLE prayer_clocks
  ADD COLUMN target_group_ids JSON NULL AFTER active,
  ADD COLUMN announcement_sent_at DATETIME NULL AFTER target_group_ids,
  ADD COLUMN group_reminder_sent_at DATETIME NULL AFTER announcement_sent_at;
