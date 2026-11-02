SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN registration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_type,
  ADD COLUMN registration_fee DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER registration_enabled,
  ADD COLUMN registration_payment_methods JSON NULL AFTER registration_fee,
  ADD COLUMN registration_public_token CHAR(32) NULL AFTER registration_payment_methods,
  ADD COLUMN registration_deadline DATETIME NULL AFTER registration_public_token,
  ADD COLUMN registration_require_email TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_deadline,
  ADD COLUMN registration_require_document TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_require_email,
  ADD COLUMN registration_require_address TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_require_document,
  ADD UNIQUE KEY uq_event_registration_token (church_id,registration_public_token);

ALTER TABLE event_registrations
  ADD COLUMN registration_status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER notes,
  ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid' AFTER registration_status,
  ADD COLUMN payment_method VARCHAR(30) NULL AFTER payment_status,
  ADD COLUMN amount_due DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method,
  ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_due,
  ADD COLUMN payment_reference VARCHAR(190) NULL AFTER amount_paid,
  ADD COLUMN confirmed_at DATETIME NULL AFTER payment_reference,
  ADD KEY idx_event_registration_status (church_id,event_id,registration_status,payment_status);

ALTER TABLE churches
  ADD COLUMN donation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pix_key,
  ADD COLUMN donation_title VARCHAR(190) NULL AFTER donation_enabled,
  ADD COLUMN donation_instructions TEXT NULL AFTER donation_title,
  ADD COLUMN donation_merchant_name VARCHAR(50) NULL AFTER donation_instructions,
  ADD COLUMN donation_merchant_city VARCHAR(30) NULL AFTER donation_merchant_name,
  ADD COLUMN donation_public_token CHAR(32) NULL AFTER donation_merchant_city,
  ADD UNIQUE KEY uq_church_donation_token (donation_public_token);
