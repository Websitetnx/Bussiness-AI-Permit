USE permitflow;

ALTER TABLE businesses
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN location_accuracy_m DECIMAL(10,2) NULL AFTER longitude,
  ADD COLUMN location_captured_at TIMESTAMP NULL AFTER location_accuracy_m;
