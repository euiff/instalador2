CREATE TABLE IF NOT EXISTS poll_votes (
  id CHAR(36) NOT NULL,
  poll_id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  group_id CHAR(36) NULL,
  voter_phone VARCHAR(80) NOT NULL,
  voter_name VARCHAR(190) NULL,
  option_index INT NOT NULL,
  option_text VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_poll_voter (poll_id,voter_phone),
  KEY idx_poll_votes_poll (poll_id,option_index),
  KEY idx_poll_votes_church (church_id,created_at),
  CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_group FOREIGN KEY (group_id) REFERENCES church_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
