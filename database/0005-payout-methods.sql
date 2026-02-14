-- Add payout metadata needed for Stripe customer-balance payouts
ALTER TABLE payouts
  ADD COLUMN stripe_customer_balance_transaction_id VARCHAR(100) DEFAULT NULL
    AFTER stripe_transfer_id,
  ADD COLUMN payout_method ENUM('manual','stripe_transfer','stripe_customer_balance')
    NOT NULL DEFAULT 'manual'
    AFTER stripe_customer_balance_transaction_id;

-- Seed payout settings defaults (no-op if already set)
INSERT INTO settings (name, value)
VALUES
  ('enabled_payout_methods', 'stripe_customer_balance'),
  ('min_payout_amount_stripe_customer_balance', '0.00')
ON DUPLICATE KEY UPDATE value = value;

-- Align default reward delay for newly created programs
ALTER TABLE programs
  ALTER COLUMN reward_days SET DEFAULT 7;
