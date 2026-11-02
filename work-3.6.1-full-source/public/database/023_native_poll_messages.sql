CREATE TABLE IF NOT EXISTS poll_group_messages (
  id CHAR(36) NOT NULL,
  poll_id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NULL,
  target_jid VARCHAR(190) NOT NULL,
  whatsapp_message_id VARCHAR(190) NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_poll_group_target (poll_id,target_jid),
  KEY idx_poll_group_message (church_id,whatsapp_message_id),
  CONSTRAINT fk_poll_group_messages_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_group_messages_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_group_messages_group FOREIGN KEY (group_id) REFERENCES church_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
