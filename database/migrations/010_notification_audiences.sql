-- Also safe to apply on migration 008 deployments without installment tables.
-- Preserve existing audiences while enabling applicant-only expiration email.
ALTER TABLE notification_events
  MODIFY COLUMN audience ENUM('all','staff','applicant','finance','finance_staff') NOT NULL DEFAULT 'all';
