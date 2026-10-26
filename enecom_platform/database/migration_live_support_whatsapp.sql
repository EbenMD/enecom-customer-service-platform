-- Enecom Live Support + WhatsApp migration (MySQL 5.7+/MariaDB 10.2+)
-- Safe to run more than once. Existing customer, service and request data is preserved.
USE enecom_platform;

SET @db_name = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='conversations' AND index_name='idx_conversation_channel_contact') = 0,
  'CREATE INDEX idx_conversation_channel_contact ON conversations (channel, external_contact)',
  'SELECT "idx_conversation_channel_contact already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='messages' AND index_name='idx_message_provider_id') = 0,
  'CREATE INDEX idx_message_provider_id ON messages (provider_message_id)',
  'SELECT "idx_message_provider_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='messages' AND index_name='idx_message_delivery_status') = 0,
  'CREATE INDEX idx_message_delivery_status ON messages (delivery_status)',
  'SELECT "idx_message_delivery_status already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
