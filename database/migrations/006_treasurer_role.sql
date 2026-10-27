-- Add the payment-only City Treasurer role to databases created before this role existed.
ALTER TABLE users
  MODIFY COLUMN role ENUM('applicant', 'admin', 'treasurer') NOT NULL DEFAULT 'applicant';
