CREATE DATABASE IF NOT EXISTS enecom_platform
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE enecom_platform;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(40) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  description TEXT,
  internal_price DECIMAL(12,2) DEFAULT NULL,
  price_type ENUM('fixed','from','quote') NOT NULL DEFAULT 'quote',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(30) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED DEFAULT NULL,
  subject VARCHAR(180) NOT NULL,
  description TEXT,
  status ENUM('new','in_progress','waiting_customer','completed','cancelled') NOT NULL DEFAULT 'new',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_customer FOREIGN KEY (customer_id) REFERENCES users(id),
  CONSTRAINT fk_request_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_request_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_request_status (status),
  INDEX idx_request_customer (customer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  channel ENUM('web_chat','whatsapp','sms','phone') NOT NULL,
  external_contact VARCHAR(100) DEFAULT NULL,
  subject VARCHAR(180) DEFAULT NULL,
  status ENUM('open','assigned','waiting','closed') NOT NULL DEFAULT 'open',
  assigned_to BIGINT UNSIGNED DEFAULT NULL,
  last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_conversation_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_conversation_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_conversation_activity (last_activity_at),
  INDEX idx_conversation_status (status),
  INDEX idx_conversation_channel_contact (channel, external_contact)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED DEFAULT NULL,
  direction ENUM('inbound','outbound','internal') NOT NULL,
  message_type ENUM('text','image','file','system') NOT NULL DEFAULT 'text',
  body TEXT,
  provider_message_id VARCHAR(190) DEFAULT NULL,
  delivery_status ENUM('queued','sent','delivered','read','failed','received') DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_message_conversation_time (conversation_id, created_at),
  INDEX idx_message_provider_id (provider_message_id),
  INDEX idx_message_delivery_status (delivery_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  activity_type VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  related_type VARCHAR(60) DEFAULT NULL,
  related_id BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_time (created_at)
) ENGINE=InnoDB;



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

INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'IT Support', 'IT Support', 'Computer troubleshooting, maintenance and technical support.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='IT Support');
INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'Software Development', 'Software Development', 'Custom business systems, websites and applications.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='Software Development');
INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'Networking', 'Networking', 'Network setup, configuration and troubleshooting.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='Networking');
INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'Training', 'Training', 'Practical IT, programming and AI training.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='Training');
INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'Repairs & Maintenance', 'Repairs & Maintenance', 'Computer repairs, upgrades and preventive maintenance.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='Repairs & Maintenance');
INSERT INTO services (name, category, description, internal_price, price_type)
SELECT 'Web & Digital Services', 'Web & Digital Services', 'Website design, hosting support and digital solutions.', NULL, 'quote'
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name='Web & Digital Services');
