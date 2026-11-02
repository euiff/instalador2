SET NAMES utf8mb4;

ALTER TABLE communication_campaigns
  ADD COLUMN media_item_id CHAR(36) NULL AFTER target_group_ids,
  ADD COLUMN media_path VARCHAR(500) NULL AFTER media_item_id,
  ADD COLUMN media_type VARCHAR(30) NULL AFTER media_path,
  ADD KEY idx_campaign_media_item (media_item_id),
  ADD CONSTRAINT fk_campaign_media_item FOREIGN KEY (media_item_id) REFERENCES media_library_items(id) ON DELETE SET NULL;
