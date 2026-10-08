SET NAMES utf8mb4;

ALTER TABLE churches
  ADD COLUMN evolution_webhook_token VARCHAR(64) NULL AFTER evolution_instance_name;
