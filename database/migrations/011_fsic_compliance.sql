-- Migration 011: FSIC compliance tracking and late renewal penalty support.
-- Apply once after 010_notification_audiences.sql.
-- Creates a dedicated FSIC compliance table for penalty tracking and adds a
-- quick-lookup expiry column to applications.

CREATE TABLE IF NOT EXISTS fsic_compliance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  fsic_issue_date DATE NULL,
  fsic_expiry_date DATE NULL,
  fsic_status ENUM('valid','expired','pending_renewal') NOT NULL DEFAULT 'pending_renewal',
  base_fsic_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  penalty_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  days_overdue INT NOT NULL DEFAULT 0,
  penalty_applied_at DATETIME NULL,
  penalty_applied_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fsic_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fsic_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fsic_penalty_by FOREIGN KEY (penalty_applied_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fsic_application (application_id),
  INDEX idx_fsic_business (business_id),
  INDEX idx_fsic_expiry (fsic_expiry_date),
  INDEX idx_fsic_status (fsic_status)
) ENGINE=InnoDB;

-- Quick-lookup column on applications for the renewal flow.
-- (Added separately; safe to re-run with IF NOT EXISTS guard via ALTER IGNORE / column check)
-- The ALTER was already run if fsic_expiry_date exists; MySQL will error if we try to add it again,
-- so only run the INSERT backfill here.

-- Backfill: for Released applications, seed a compliance record using approved_at as issue date
-- and (approved_at + 1 year - 1 day) as the fallback FSIC expiry date.
-- Admin can override these dates via the review page.
INSERT INTO fsic_compliance (application_id, business_id, fsic_issue_date, fsic_expiry_date, fsic_status, created_at)
SELECT a.id, a.business_id,
       DATE(COALESCE(a.approved_at, a.submitted_at)),
       DATE(DATE_ADD(COALESCE(a.approved_at, a.submitted_at), INTERVAL 1 YEAR) - INTERVAL 1 DAY),
       CASE
         WHEN DATE(DATE_ADD(COALESCE(a.approved_at, a.submitted_at), INTERVAL 1 YEAR) - INTERVAL 1 DAY) < CURDATE() THEN 'expired'
         ELSE 'valid'
       END,
       NOW()
FROM applications a
WHERE a.status = 'Released'
  AND COALESCE(a.approved_at, a.submitted_at) IS NOT NULL
ON DUPLICATE KEY UPDATE application_id = VALUES(application_id);

-- Sync the quick-lookup column on applications using the compliance table.
UPDATE applications a
JOIN fsic_compliance f ON f.application_id = a.id
SET a.fsic_expiry_date = f.fsic_expiry_date
WHERE a.fsic_expiry_date IS NULL AND f.fsic_expiry_date IS NOT NULL;

