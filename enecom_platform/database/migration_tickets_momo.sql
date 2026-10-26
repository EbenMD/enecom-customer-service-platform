-- Enecom ticket replies and MoMo payment notices migration.
-- Run once in phpMyAdmin after selecting the enecom_platform database.
CREATE TABLE IF NOT EXISTS service_request_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_srm_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_srm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_srm_request_time (request_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  transaction_reference VARCHAR(120) NOT NULL,
  payment_purpose VARCHAR(180) DEFAULT NULL,
  status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  staff_note VARCHAR(500) DEFAULT NULL,
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_payment_reference (transaction_reference),
  INDEX idx_payment_status (status),
  INDEX idx_payment_customer_time (customer_id, created_at)
) ENGINE=InnoDB;
