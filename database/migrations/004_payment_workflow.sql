USE permitflow;

ALTER TABLE payments
  ADD COLUMN payment_method ENUM('GCash', 'Maya', 'Bank Transfer', 'City Treasurer Counter') NULL AFTER amount,
  ADD COLUMN payer_name VARCHAR(120) NULL AFTER payment_method,
  ADD COLUMN proof_original_name VARCHAR(255) NULL AFTER status,
  ADD COLUMN proof_stored_name VARCHAR(120) NULL AFTER proof_original_name,
  ADD COLUMN proof_mime_type VARCHAR(100) NULL AFTER proof_stored_name,
  ADD COLUMN receipt_number VARCHAR(40) NULL AFTER proof_mime_type,
  ADD COLUMN submitted_at TIMESTAMP NULL AFTER receipt_number,
  ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER paid_at,
  ADD COLUMN verified_at TIMESTAMP NULL AFTER verified_by,
  ADD COLUMN admin_notes TEXT NULL AFTER verified_at,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD CONSTRAINT fk_payment_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_payment_application (application_id),
  ADD UNIQUE KEY uq_payment_receipt (receipt_number);
