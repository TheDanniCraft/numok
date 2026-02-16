-- Migration 0005: update schema from 0004 to payout-methods/Tremendous support.

-- Add partner payout identity fields needed by current payout flows.
ALTER TABLE partners
  ADD COLUMN stripe_customer_id VARCHAR(100) DEFAULT NULL AFTER contact_name;

-- Create payouts table with fields currently used by the app.
CREATE TABLE payouts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT UNSIGNED NOT NULL,
  stripe_customer_balance_transaction_id VARCHAR(100) DEFAULT NULL,
  tremendous_order_id VARCHAR(100) DEFAULT NULL,
  payout_method ENUM('manual','stripe_customer_balance','tremendous')
    NOT NULL DEFAULT 'manual',
  amount DECIMAL(10,2) NOT NULL,
  status ENUM(
    'pending',
    'processing',
    'pending_approval',
    'pending_internal_payment_approval',
    'paid',
    'failed',
    'canceled'
  )
    NOT NULL DEFAULT 'pending',
  failure_reason VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY partner_id (partner_id),
  KEY idx_payouts_status_method_updated_at (status, payout_method, updated_at),
  KEY idx_payouts_tremendous_order_id (tremendous_order_id),
  KEY idx_payouts_method_status (payout_method, status),
  CONSTRAINT payouts_ibfk_1 FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link conversions to payouts.
ALTER TABLE conversions
  ADD COLUMN payout_id INT UNSIGNED DEFAULT NULL AFTER status,
  ADD COLUMN last_payout_failure_reason VARCHAR(255) DEFAULT NULL AFTER payout_id,
  ADD COLUMN last_payout_failed_at TIMESTAMP NULL DEFAULT NULL AFTER last_payout_failure_reason,
  ADD KEY idx_payout_id (payout_id),
  ADD KEY idx_status_payout_id (status, payout_id),
  ADD CONSTRAINT conversions_ibfk_2
    FOREIGN KEY (payout_id) REFERENCES payouts (id) ON DELETE SET NULL;

-- Program-level Tremendous campaign selection.
ALTER TABLE programs
  ADD COLUMN tremendous_campaign_id VARCHAR(100) DEFAULT NULL AFTER reward_days;

-- Seed payout settings defaults.
INSERT INTO settings (name, value)
VALUES
  ('enabled_payout_methods', 'stripe_customer_balance'),
  ('min_payout_amount_stripe_customer_balance', '0.00'),
  ('min_payout_amount_tremendous', '0.00')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Align default reward delay for newly created programs.
ALTER TABLE programs
  ALTER reward_days SET DEFAULT 7;
