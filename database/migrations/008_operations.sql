-- Import once after migrations 002 through 007. No existing records are removed.
CREATE TABLE IF NOT EXISTS notification_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key CHAR(64) NOT NULL UNIQUE,
  application_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  audience ENUM('all','staff') NOT NULL DEFAULT 'all',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(250) NULL,
  UNIQUE KEY uq_event_recipient (event_id,user_id),
  INDEX idx_outbox_due (status,available_at),
  FOREIGN KEY (event_id) REFERENCES notification_events(id) ON DELETE RESTRICT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_delivery_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outbox_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  outcome ENUM('sent','failed','uncertain','cancelled') NOT NULL,
  error_message VARCHAR(250) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (outbox_id) REFERENCES email_outbox(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS document_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(120) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_document_file (document_id,stored_name),
  FOREIGN KEY (document_id) REFERENCES application_documents(id) ON DELETE RESTRICT,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS document_scan_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  snapshot_key CHAR(64) NOT NULL UNIQUE,
  result_json JSON NOT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by BIGINT UNSIGNED NULL,
  FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT,
  FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS document_review_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS application_assignments (
  application_id BIGINT UNSIGNED PRIMARY KEY,
  reviewer_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_assignment_due (due_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS assignment_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  due_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS permit_verification (
  application_id BIGINT UNSIGNED PRIMARY KEY,
  token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  issued_at DATETIME NOT NULL,
  valid_until DATETIME NOT NULL,
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- Only currently available uploads and scans can be backfilled.
INSERT INTO document_versions (document_id,original_name,stored_name,mime_type,file_size,uploaded_at,uploaded_by)
SELECT d.id,d.original_name,d.stored_name,d.mime_type,d.file_size,d.uploaded_at,a.user_id
FROM application_documents d JOIN applications a ON a.id=d.application_id
ON DUPLICATE KEY UPDATE document_id=VALUES(document_id);
