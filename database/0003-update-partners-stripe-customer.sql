-- Remove payment_email column from partners table
ALTER TABLE partners DROP COLUMN payment_email;

-- Add stripe_customer_id column to partners table
ALTER TABLE partners
ADD COLUMN stripe_customer_id VARCHAR(100) NULL
AFTER contact_name;