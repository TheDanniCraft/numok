-- Remove payment_email column from partners table
ALTER TABLE partners DROP COLUMN payment_email;

-- Add stripe_customer_id column to partners table
ALTER TABLE partners
ADD COLUMN stripe_customer_id VARCHAR(100) NULL
AFTER contact_name;

-- Add Stripe Connect payout fields to partners table
ALTER TABLE partners
  ADD COLUMN stripe_account_id VARCHAR(100) DEFAULT NULL
    AFTER stripe_customer_id,
  ADD COLUMN stripe_payout_status ENUM('not_connected','pending','enabled','disabled')
    NOT NULL DEFAULT 'not_connected'
    AFTER stripe_account_id,
  ADD COLUMN stripe_payout_disabled_reason VARCHAR(255) DEFAULT NULL
    AFTER stripe_payout_status;

-- Create payouts table (for affiliate payouts via Stripe)
CREATE TABLE IF NOT EXISTS payouts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT UNSIGNED NOT NULL,
  stripe_transfer_id VARCHAR(100) DEFAULT NULL,

  amount DECIMAL(10,2) NOT NULL,
  fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  net_amount DECIMAL(10,2) NOT NULL,

  status ENUM('pending','processing','paid','failed','canceled')
    NOT NULL DEFAULT 'pending',
  failure_reason VARCHAR(255) DEFAULT NULL,

  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY partner_id (partner_id),
  KEY idx_stripe_transfer_id (stripe_transfer_id),
  CONSTRAINT payouts_ibfk_1 FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link conversions to payouts
ALTER TABLE conversions
  ADD COLUMN payout_id INT UNSIGNED DEFAULT NULL AFTER status,
  ADD KEY idx_payout_id (payout_id),
  ADD CONSTRAINT conversions_ibfk_2 
    FOREIGN KEY (payout_id) REFERENCES payouts (id) ON DELETE SET NULL;