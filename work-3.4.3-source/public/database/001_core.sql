SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  full_name VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  avatar_url TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_profiles_user (user_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role ENUM('admin','super_admin') NOT NULL DEFAULT 'admin',
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_role (user_id,role),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS churches (
  id CHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  logo_url TEXT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  pastor_name VARCHAR(255) NULL,
  pastor_signature_url TEXT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  welcome_message TEXT NULL,
  service_times JSON NULL,
  pix_key VARCHAR(255) NULL,
  pagbank_token TEXT NULL,
  whatsapp_token TEXT NULL,
  whatsapp_group_id VARCHAR(190) NULL,
  notification_groups JSON NULL,
  evolution_api_url TEXT NULL,
  evolution_api_key TEXT NULL,
  evolution_instance_name VARCHAR(190) NULL,
  gemini_api_key TEXT NULL,
  tts_enabled TINYINT(1) NOT NULL DEFAULT 0,
  tts_api_key TEXT NULL,
  tts_voice_id VARCHAR(190) NULL,
  daily_devotional_time TIME NULL,
  ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
  plan VARCHAR(50) NOT NULL DEFAULT 'free',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_churches_slug (slug),
  KEY idx_churches_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS church_members (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_church_member (church_id,user_id),
  KEY idx_church_members_user (user_id),
  CONSTRAINT fk_church_members_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_church_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS congregations (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  address TEXT NULL,
  phone VARCHAR(40) NULL,
  pastor_name VARCHAR(255) NULL,
  pastor_signature_url TEXT NULL,
  pix_key VARCHAR(255) NULL,
  schedule_text TEXT NULL,
  whatsapp_group_id VARCHAR(190) NULL,
  notification_groups JSON NULL,
  daily_devotional_time TIME NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_congregations_church (church_id),
  CONSTRAINT fk_congregations_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(190) NULL,
  birth_date DATE NULL,
  cpf VARCHAR(30) NULL,
  rg VARCHAR(30) NULL,
  cep VARCHAR(20) NULL,
  address_street VARCHAR(255) NULL,
  address_number VARCHAR(50) NULL,
  address_neighborhood VARCHAR(255) NULL,
  address_city VARCHAR(255) NULL,
  address_state VARCHAR(80) NULL,
  father_name VARCHAR(255) NULL,
  mother_name VARCHAR(255) NULL,
  spouse_name VARCHAR(255) NULL,
  marital_status VARCHAR(80) NULL,
  nationality VARCHAR(120) NULL,
  naturalness VARCHAR(120) NULL,
  role VARCHAR(120) NULL,
  department VARCHAR(190) NULL,
  photo_url TEXT NULL,
  membership_date DATE NULL,
  church_time VARCHAR(120) NULL,
  is_baptized TINYINT(1) NOT NULL DEFAULT 0,
  baptism_date DATE NULL,
  baptism_church VARCHAR(255) NULL,
  baptism_pastor VARCHAR(255) NULL,
  registration_status VARCHAR(80) NULL,
  registration_step VARCHAR(80) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_members_church (church_id),
  KEY idx_members_congregation (congregation_id),
  KEY idx_members_phone (phone),
  KEY idx_members_name (name),
  CONSTRAINT fk_members_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_congregation FOREIGN KEY (congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
