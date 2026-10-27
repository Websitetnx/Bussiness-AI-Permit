USE permitflow;

CREATE TABLE IF NOT EXISTS document_ai_scans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL UNIQUE,
  application_id BIGINT UNSIGNED NOT NULL,
  scan_status ENUM('Completed', 'Failed') NOT NULL,
  detected_document_type VARCHAR(190) NULL,
  matches_expected_type TINYINT(1) NULL,
  quality_score TINYINT UNSIGNED NULL,
  confidence_score TINYINT UNSIGNED NULL,
  extracted_fields JSON NULL,
  issues JSON NULL,
  summary TEXT NULL,
  requires_human_review TINYINT(1) NOT NULL DEFAULT 1,
  model VARCHAR(80) NULL,
  error_message TEXT NULL,
  scanned_by BIGINT UNSIGNED NULL,
  scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_scan_document FOREIGN KEY (document_id) REFERENCES application_documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_scan_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_scan_user FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ai_scan_application (application_id),
  INDEX idx_ai_scan_status (scan_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_analytics_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  metrics JSON NOT NULL,
  insights JSON NOT NULL,
  model VARCHAR(80) NOT NULL,
  generated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_report_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ai_report_created (created_at)
) ENGINE=InnoDB;
