USE permitflow;

ALTER TABLE applications
  ADD COLUMN declared_capital DECIMAL(14,2) NULL AFTER stage,
  ADD COLUMN requires_building_inspection TINYINT(1) NOT NULL DEFAULT 0 AFTER gross_sales,
  ADD COLUMN requires_electrical_inspection TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_building_inspection,
  ADD COLUMN requires_plumbing_inspection TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_electrical_inspection;

CREATE TABLE IF NOT EXISTS permit_fee_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  lgu_name VARCHAR(190) NOT NULL DEFAULT 'Local Government Unit',
  sanitary_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  zoning_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  general_inspection_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  building_inspection_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  electrical_inspection_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  plumbing_inspection_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  barangay_clearance_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  community_tax_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bfp_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  bfp_minimum_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_configured TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fee_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO permit_fee_settings (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS permit_business_type_rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_type VARCHAR(100) NOT NULL UNIQUE,
  new_lbt_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  renewal_lbt_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  mayors_permit_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO permit_business_type_rates (business_type) VALUES
  ('Retail'), ('Food and Beverage'), ('Professional Services'), ('Manufacturing'), ('Other')
ON DUPLICATE KEY UPDATE business_type = VALUES(business_type);

ALTER TABLE payments
  ADD COLUMN assessment_breakdown JSON NULL AFTER amount,
  ADD COLUMN assessed_by BIGINT UNSIGNED NULL AFTER assessment_breakdown,
  ADD COLUMN assessed_at TIMESTAMP NULL AFTER assessed_by,
  ADD CONSTRAINT fk_payment_assessor FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL;
