-- Add email OTP challenges for applicant registration and password recovery.
CREATE TABLE IF NOT EXISTS auth_otp_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_token_hash CHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL,
  purpose ENUM('registration', 'password_reset') NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  pending_name VARCHAR(120) NULL,
  pending_password_hash VARCHAR(255) NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  last_sent_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_auth_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_auth_otp_request_token (request_token_hash),
  INDEX idx_auth_otp_email_purpose_sent (email, purpose, last_sent_at),
  INDEX idx_auth_otp_expires (expires_at),
  INDEX idx_auth_otp_user (user_id)
) ENGINE=InnoDB;
