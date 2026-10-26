-- Enecom automatic worker/customer notifications. Select enecom_platform before importing. Safe to run more than once.
CREATE TABLE IF NOT EXISTS user_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  target_url VARCHAR(500) NOT NULL,
  notification_type VARCHAR(40) NOT NULL DEFAULT 'general',
  related_request_id BIGINT UNSIGNED DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_user_notifications_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_notifications_request FOREIGN KEY (related_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  INDEX idx_notifications_recipient_read_time (recipient_user_id, is_read, created_at),
  INDEX idx_notifications_request (related_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
