SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS polls (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  question TEXT NOT NULL,
  options JSON NOT NULL,
  results JSON NULL,
  target_groups JSON NOT NULL,
  template_name VARCHAR(190) NULL,
  validity VARCHAR(80) NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  sent_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_polls_church_status (church_id,status),
  CONSTRAINT fk_polls_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_clocks (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  title VARCHAR(255) NOT NULL,
  event_date DATE NOT NULL,
  end_date DATE NULL,
  start_hour INT NOT NULL,
  end_hour INT NOT NULL,
  slot_duration_minutes INT NOT NULL DEFAULT 60,
  max_per_slot INT NOT NULL DEFAULT 1,
  reminder_minutes_before INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prayer_clocks_church_date (church_id,event_date),
  CONSTRAINT fk_prayer_clocks_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_prayer_clocks_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_clock_registrations (
  id CHAR(36) NOT NULL,
  prayer_clock_id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  slot_time TIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clock_reg_clock_slot (prayer_clock_id,slot_time),
  CONSTRAINT fk_clock_reg_clock FOREIGN KEY (prayer_clock_id) REFERENCES prayer_clocks(id) ON DELETE CASCADE,
  CONSTRAINT fk_clock_reg_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_clock_reminders_sent (
  id CHAR(36) NOT NULL,
  prayer_clock_id CHAR(36) NOT NULL,
  registration_id CHAR(36) NOT NULL,
  slot_date DATE NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clock_reminder (registration_id,slot_date),
  CONSTRAINT fk_clock_reminder_clock FOREIGN KEY (prayer_clock_id) REFERENCES prayer_clocks(id) ON DELETE CASCADE,
  CONSTRAINT fk_clock_reminder_registration FOREIGN KEY (registration_id) REFERENCES prayer_clock_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raffles (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  prize_description TEXT NULL,
  image_url TEXT NULL,
  total_numbers INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  pix_key VARCHAR(255) NULL,
  draw_date DATETIME NULL,
  drawn_at DATETIME NULL,
  winner_number INT NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_raffles_church_status (church_id,status),
  CONSTRAINT fk_raffles_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffles_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raffle_numbers (
  id CHAR(36) NOT NULL,
  raffle_id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  number INT NOT NULL,
  buyer_name VARCHAR(255) NOT NULL,
  buyer_phone VARCHAR(40) NOT NULL,
  paid TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_raffle_number (raffle_id,number),
  CONSTRAINT fk_raffle_numbers_raffle FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffle_numbers_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS birthday_messages (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  year INT NOT NULL,
  message_type VARCHAR(80) NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_birthday_message (member_id,year,message_type),
  CONSTRAINT fk_birthday_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_birthday_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bible_reading_plans (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  name VARCHAR(255) NULL,
  plan_type VARCHAR(80) NOT NULL,
  current_book VARCHAR(120) NOT NULL,
  current_chapter INT NOT NULL DEFAULT 1,
  include_audio TINYINT(1) NOT NULL DEFAULT 0,
  delivery_time TIME NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sent_at DATETIME NULL,
  completed_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bible_plan_church_phone (church_id,phone),
  CONSTRAINT fk_bible_plan_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
