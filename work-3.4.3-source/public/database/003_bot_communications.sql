SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS prayer_requests (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  member_id CHAR(36) NULL,
  phone VARCHAR(40) NOT NULL,
  name VARCHAR(255) NULL,
  request TEXT NOT NULL,
  ai_response LONGTEXT NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_prayers_church_status (church_id,status),
  KEY idx_prayers_member (member_id),
  CONSTRAINT fk_prayers_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_prayers_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL,
  CONSTRAINT fk_prayers_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_messages (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  direction ENUM('inbound','outbound') NOT NULL,
  message LONGTEXT NOT NULL,
  message_type VARCHAR(80) NOT NULL DEFAULT 'text',
  status VARCHAR(80) NOT NULL DEFAULT 'sent',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bot_messages_church_phone (church_id,phone),
  KEY idx_bot_messages_created (created_at),
  CONSTRAINT fk_bot_messages_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_conversation_states (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  state VARCHAR(120) NOT NULL,
  data JSON NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bot_state_church_phone (church_id,phone),
  CONSTRAINT fk_bot_state_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_settings (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  menu_text LONGTEXT NULL,
  schedule_text LONGTEXT NULL,
  donation_amounts JSON NULL,
  ai_prompt LONGTEXT NULL,
  thank_you_message LONGTEXT NULL,
  event_registration_enabled TINYINT(1) NOT NULL DEFAULT 0,
  event_registration_event_id CHAR(36) NULL,
  event_registration_menu_text TEXT NULL,
  registration_fields JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bot_settings_church (church_id),
  CONSTRAINT fk_bot_settings_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_bot_settings_event FOREIGN KEY (event_registration_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_logs (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NULL,
  source VARCHAR(120) NOT NULL,
  event_type VARCHAR(120) NULL,
  payload JSON NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'received',
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_webhook_logs_church (church_id),
  KEY idx_webhook_logs_created (created_at),
  CONSTRAINT fk_webhook_logs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_moderation_settings (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_moderation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  blocked_words JSON NULL,
  moderate_adult_content TINYINT(1) NOT NULL DEFAULT 1,
  moderate_politics TINYINT(1) NOT NULL DEFAULT 0,
  moderate_profanity TINYINT(1) NOT NULL DEFAULT 1,
  moderate_spam TINYINT(1) NOT NULL DEFAULT 1,
  mention_user_in_warning TINYINT(1) NOT NULL DEFAULT 1,
  warning_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_moderation_settings_church (church_id),
  CONSTRAINT fk_moderation_settings_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_logs (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  group_id VARCHAR(190) NOT NULL,
  sender_phone VARCHAR(40) NOT NULL,
  sender_name VARCHAR(255) NULL,
  original_message LONGTEXT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  ai_analysis LONGTEXT NULL,
  action_taken VARCHAR(120) NULL,
  notified_user TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_moderation_logs_church_created (church_id,created_at),
  CONSTRAINT fk_moderation_logs_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_audio_preferences (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  audio_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audio_pref (church_id,phone),
  CONSTRAINT fk_audio_pref_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
