-- Apply once after 008_operations.sql, with a verified database/upload backup.
-- Existing payment IDs, receipts, evidence, and document history are retained.
ALTER TABLE applications
  ADD COLUMN permit_effective_date DATE NULL,
  ADD COLUMN permit_expiration_date DATE NULL,
  ADD COLUMN validity_source ENUM('legacy','bplo') NULL,
  ADD COLUMN validity_confirmed_by BIGINT UNSIGNED NULL,
  ADD COLUMN validity_confirmed_at DATETIME NULL,
  ADD COLUMN validity_policy_reference VARCHAR(255) NULL,
  ADD COLUMN requested_effective_date DATE NULL,
  ADD COLUMN payment_frequency ENUM('Annual','Quarterly') NOT NULL DEFAULT 'Annual',
  ADD COLUMN renewal_quote JSON NULL,
  ADD COLUMN renewal_source_id BIGINT UNSIGNED NULL,
  ADD COLUMN submission_key CHAR(64) NULL,
  ADD UNIQUE KEY uq_application_submission (submission_key),
  ADD INDEX idx_permit_expiration (status,permit_expiration_date);

CREATE TABLE permit_payment_policy (
  id TINYINT UNSIGNED PRIMARY KEY,
  policy_reference VARCHAR(255) NOT NULL DEFAULT '',
  validity_mode ENUM('one_year','bplo_dates') NOT NULL DEFAULT 'one_year',
  quarterly_enabled TINYINT(1) NOT NULL DEFAULT 0,
  quarterly_fee_keys JSON NOT NULL,
  schedule_mode ENUM('offsets','calendar') NOT NULL DEFAULT 'offsets',
  installment_schedule JSON NOT NULL,
  annual_due_days INT NOT NULL DEFAULT 0,
  upfront_due_days INT NOT NULL DEFAULT 0,
  allow_installment_release TINYINT(1) NOT NULL DEFAULT 0,
  release_installments TINYINT UNSIGNED NOT NULL DEFAULT 4,
  payment_reminder_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  expiration_reminder_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO permit_payment_policy (id,quarterly_fee_keys,installment_schedule) VALUES (1,'[]','[]');

ALTER TABLE payments
  MODIFY COLUMN status ENUM('Pending','Paid','Failed','Refunded','Partially Paid') NOT NULL DEFAULT 'Pending',
  ADD COLUMN payment_frequency ENUM('Annual','Quarterly') NOT NULL DEFAULT 'Annual',
  ADD COLUMN plan_snapshot JSON NULL;

CREATE TABLE payment_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NOT NULL,
  required_before_release TINYINT(1) NOT NULL DEFAULT 1,
  is_legacy TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  payment_method VARCHAR(40) NULL,
  payer_name VARCHAR(120) NULL,
  payment_reference VARCHAR(100) NULL,
  proof_original_name VARCHAR(255) NULL,
  proof_stored_name VARCHAR(120) NULL,
  proof_mime_type VARCHAR(100) NULL,
  submitted_at DATETIME NULL,
  submission_version INT UNSIGNED NOT NULL DEFAULT 0,
  receipt_number VARCHAR(40) NULL,
  paid_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  admin_notes TEXT NULL,
  CONSTRAINT fk_installment_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_installment_sequence (payment_id,sequence_no),
  UNIQUE KEY uq_installment_receipt (receipt_number),
  INDEX idx_installment_due (status,due_date)
) ENGINE=InnoDB;

CREATE TABLE payment_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  installment_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  submission_token CHAR(64) NOT NULL,
  payment_method VARCHAR(40) NOT NULL,
  payer_name VARCHAR(120) NOT NULL,
  payment_reference VARCHAR(100) NOT NULL,
  proof_original_name VARCHAR(255) NULL,
  proof_stored_name VARCHAR(120) NULL,
  proof_mime_type VARCHAR(100) NULL,
  status ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  submitted_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  admin_notes TEXT NULL,
  CONSTRAINT fk_evidence_installment FOREIGN KEY (installment_id) REFERENCES payment_installments(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_evidence_attempt (installment_id,attempt_number),
  UNIQUE KEY uq_evidence_submission (submission_token)
) ENGINE=InnoDB;

CREATE TABLE payment_reference_claims (
  reference_hash CHAR(64) PRIMARY KEY,
  installment_id BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_reference_installment FOREIGN KEY (installment_id) REFERENCES payment_installments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Preserve the historic calendar-year dates; do not retroactively grant a new term.
UPDATE applications a
LEFT JOIN permit_verification v ON v.application_id=a.id
LEFT JOIN (SELECT application_id,MIN(created_at) released_at FROM application_status_history WHERE status='Released' GROUP BY application_id) h ON h.application_id=a.id
SET a.permit_effective_date=DATE(COALESCE(v.issued_at,h.released_at,a.reviewed_at,a.approved_at,a.submitted_at)),
    a.permit_expiration_date=COALESCE(DATE(v.valid_until),STR_TO_DATE(CONCAT(YEAR(COALESCE(h.released_at,a.reviewed_at,a.approved_at,a.submitted_at)),'-12-31'),'%Y-%m-%d')),
    a.validity_source='legacy',a.validity_policy_reference='Preserved pre-migration permit dates; no automatic extension'
WHERE a.status='Released';

INSERT INTO payment_installments
  (payment_id,sequence_no,label,amount,due_date,is_legacy,status,payment_method,payer_name,payment_reference,proof_original_name,proof_stored_name,proof_mime_type,submitted_at,submission_version,receipt_number,paid_at,verified_by,verified_at,admin_notes)
SELECT id,1,'Annual (legacy)',amount,DATE(COALESCE(assessed_at,created_at)),1,status,payment_method,payer_name,payment_reference,proof_original_name,proof_stored_name,proof_mime_type,submitted_at,IF(submitted_at IS NULL,0,1),receipt_number,paid_at,verified_by,verified_at,admin_notes FROM payments;

INSERT INTO payment_evidence (installment_id,attempt_number,submission_token,payment_method,payer_name,payment_reference,proof_original_name,proof_stored_name,proof_mime_type,status,submitted_at,verified_at,verified_by,admin_notes)
SELECT id,1,SHA2(CONCAT('legacy-payment:',payment_id),256),COALESCE(payment_method,''),COALESCE(payer_name,''),COALESCE(payment_reference,''),proof_original_name,proof_stored_name,proof_mime_type,status,submitted_at,verified_at,verified_by,admin_notes FROM payment_installments WHERE submitted_at IS NOT NULL;

INSERT IGNORE INTO payment_reference_claims (reference_hash,installment_id)
SELECT SHA2(CONCAT(LOWER(TRIM(payment_method)),':',LOWER(TRIM(payment_reference))),256),id FROM payment_installments WHERE payment_reference IS NOT NULL AND TRIM(payment_reference)<>'' ORDER BY id;

ALTER TABLE notification_events MODIFY COLUMN audience ENUM('all','staff','applicant','finance','finance_staff') NOT NULL DEFAULT 'all';
