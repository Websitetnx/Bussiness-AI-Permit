-- Migration 008: Reliable email queue with retry and deduplication
-- Run: mysql -u root permitflow < database/migrations/008_email_queue.sql

CREATE TABLE IF NOT EXISTS email_queue (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idempotency_key VARCHAR(64)    NOT NULL,
  recipient_email VARCHAR(190)   NOT NULL,
  recipient_name  VARCHAR(120)   NOT NULL DEFAULT '',
  subject         VARCHAR(255)   NOT NULL,
  html_body       MEDIUMTEXT     NOT NULL,
  text_body       MEDIUMTEXT     NOT NULL,
  context_type    VARCHAR(60)    NOT NULL DEFAULT '',
  context_id      BIGINT UNSIGNED NULL,
  status          ENUM('pending','sent','failed','permanently_failed') NOT NULL DEFAULT 'pending',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  last_error      TEXT           NULL,
  next_retry_at   DATETIME       NULL,
  sent_at         DATETIME       NULL,
  created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eq_idempotency (idempotency_key),
  INDEX idx_eq_status_retry (status, next_retry_at),
  INDEX idx_eq_context (context_type, context_id),
  INDEX idx_eq_recipient (recipient_email),
  INDEX idx_eq_created (created_at)
) ENGINE=InnoDB;
