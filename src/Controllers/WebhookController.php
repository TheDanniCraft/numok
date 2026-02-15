<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class WebhookController extends Controller {
    public function stripeWebhook(): void {

        // Log the raw payload
        $payload = @file_get_contents('php://input');
        $this->logEvent('webhook_received', [
            'payload' => $payload,
            'signature' => $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null
        ]);

        // Get webhook secret from settings
        $webhookSecret = Database::query(
            "SELECT value FROM settings WHERE name = 'stripe_webhook_secret' LIMIT 1"
        )->fetch()['value'];

        // Get payload and signature
        $payload = @file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        
        try {
            // Verify signature
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sigHeader, $webhookSecret
            );
            
            // Process different event types
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event->data->object);
                    break;
                    
                case 'payment_intent.succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;

                case 'invoice.paid':
                    $this->handleInvoicePaid($event->data->object);
                    break;
            }

            http_response_code(200);
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            error_log('Stripe Webhook Error: ' . $e->getMessage());
            http_response_code(400);
            exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            error_log('Stripe Signature Error: ' . $e->getMessage());
            http_response_code(400);
            exit();
        } catch (\Exception $e) {
            $this->logEvent('webhook_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleCheckoutCompleted($session): void {
        $metadata = $session->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;

        if (!$trackingCode) {
            $this->logEvent('checkout_completed_no_tracking', $session);
            return;
        }

        // Get partner program
        $partnerProgram = Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value 
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.tracking_code = ? 
             AND pp.status = 'active'
             AND p.status = 'active'
             LIMIT 1",
            [$trackingCode]
        )->fetch();

        if (!$partnerProgram) {
            $this->logEvent('checkout_completed_invalid_tracking', $session);
            return;
        }

        // Calculate commission status
        $status = $partnerProgram['reward_days'] > 0 ? 'pending' : 'payable';

        // Store conversion
        try {
            // Check session mode - for subscription mode, use subscription ID instead of payment_intent
            $paymentId = $session->payment_intent;
            if ($session->mode === 'subscription' && !empty($session->subscription)) {
                $paymentId = $session->subscription;
            }
            
            if (!$paymentId) {
                $this->logEvent('checkout_completed_missing_payment_id', [
                    'session_id' => $session->id,
                    'mode' => $session->mode
                ]);
                $paymentId = $session->id; // Fallback to session ID if nothing else is available
            }

            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $paymentId,
                'amount' => $session->amount_total / 100, // Convert from cents
                'commission_amount' => $this->calculateCommission($session->amount_total / 100, $partnerProgram),
                'status' => $status,
                'customer_email' => $session->customer_details->email ?? null,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null
                ])
            ]);

            // If it's a subscription, update its metadata to include the tracking code for future payments
            if ($session->mode === 'subscription') {
                $stripeSecretKey = Database::query("SELECT value FROM settings WHERE name = 'stripe_secret_key' LIMIT 1")->fetch()['value'];
                \Stripe\Stripe::setApiKey($stripeSecretKey);
                
                \Stripe\Subscription::update($session->subscription, [
                    'metadata' => ['numok_tracking_code' => $trackingCode]
                ]);
                $this->logEvent('subscription_metadata_updated', ['subscription_id' => $session->subscription, 'tracking_code' => $trackingCode]);
            }

            $this->logEvent('conversion_created', [
                'payment_id' => $paymentId,
                'tracking_code' => $trackingCode,
                'amount' => $session->amount_total / 100
            ]);
        } catch (\Exception $e) {
            $this->logEvent('conversion_creation_failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId ?? null,
                'session_id' => $session->id
            ]);
            throw $e;
        }
    }

    private function handlePaymentSucceeded($paymentIntent): void {
        // Add detailed logging
        $this->logEvent('payment_intent_processing', [
            'payment_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'metadata' => $paymentIntent->metadata
        ]);
    
        // Get tracking code from metadata
        $metadata = $paymentIntent->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;
    
        if (!$trackingCode) {
            $this->logEvent('payment_succeeded_no_tracking', $paymentIntent);
            return;
        }
    
        // Get partner program with detailed logging
        $partnerProgram = Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.tracking_code = ? 
             AND pp.status = 'active'
             AND p.status = 'active'
             LIMIT 1",
            [$trackingCode]
        )->fetch();
    
        if (!$partnerProgram) {
            $this->logEvent('payment_succeeded_invalid_tracking', [
                'payment_id' => $paymentIntent->id,
                'tracking_code' => $trackingCode
            ]);
            return;
        }
    
        // Log found partner program
        $this->logEvent('partner_program_found', [
            'payment_id' => $paymentIntent->id,
            'partner_program' => $partnerProgram
        ]);
    
        // Calculate amount in dollars
        $amount = $paymentIntent->amount / 100;
    
        // Calculate commission
        $commission = $this->calculateCommission($amount, $partnerProgram);
    
        // Determine status
        $status = $partnerProgram['reward_days'] > 0 ? 'pending' : 'payable';
    
        // Store conversion
        try {
            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $paymentIntent->id,
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => $status,
                'customer_email' => $paymentIntent->receipt_email ?? null,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null
                ])
            ]);
    
            $this->logEvent('conversion_created', [
                'payment_id' => $paymentIntent->id,
                'tracking_code' => $trackingCode,
                'amount' => $amount,
                'commission' => $commission
            ]);
        } catch (\Exception $e) {
            $this->logEvent('conversion_creation_failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentIntent->id,
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleInvoicePaid($invoice): void {
        // Add initial logging
        $this->logEvent('invoice_paid_processing', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
            'amount' => $invoice->amount_paid,
            'subscription_metadata' => $invoice->subscription_details->metadata ?? null
        ]);

        // For trials, the first invoice.paid should update the original conversion
        // The original conversion was stored with the subscription_id
        $existingConversion = Database::query(
            "SELECT * FROM conversions WHERE stripe_payment_id = ? LIMIT 1",
            [$invoice->subscription]
        )->fetch();

        $amount = $invoice->amount_paid / 100;

        if ($existingConversion && (float)$existingConversion['amount'] === 0.00) {
            // This is the first payment after a trial. Update the existing conversion.
            $this->updateTrialConversion($existingConversion, $invoice, $amount);
        } else {
            // This is a regular recurring payment, not the first payment after a trial.
            $this->createRecurringConversion($invoice, $amount);
        }
    }

    private function updateTrialConversion(array $conversion, $invoice, float $amount): void {
        $partnerProgram = $this->getPartnerProgramById($conversion['partner_program_id']);
        if (!$partnerProgram) {
            $this->logEvent('update_trial_conversion_no_program', ['conversion_id' => $conversion['id']]);
            return;
        }

        $commission = $this->calculateCommission($amount, $partnerProgram);
        $status = $partnerProgram['reward_days'] > 0 ? 'pending' : 'payable';

        try {
            $data = [
                'stripe_payment_id' => $invoice->payment_intent, // Update to the payment_intent for uniqueness
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => $status,
                'updated_at' => gmdate('Y-m-d H:i:s')
            ];
            Database::update('conversions', $data, 'id = ?', [$conversion['id']]);

            $this->logEvent('trial_conversion_updated', [
                'conversion_id' => $conversion['id'],
                'invoice_id' => $invoice->id,
                'new_amount' => $amount
            ]);
        } catch (\Exception $e) {
            $this->logEvent('trial_conversion_update_failed', [
                'error' => $e->getMessage(),
                'conversion_id' => $conversion['id']
            ]);
            throw $e;
        }
    }

    private function createRecurringConversion($invoice, float $amount): void {
        $metadata = $invoice->subscription_details->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;

        if (!$trackingCode) {
            $this->logEvent('invoice_paid_no_tracking_recurring', ['invoice_id' => $invoice->id]);
            return;
        }

        $partnerProgram = $this->getPartnerProgramByTrackingCode($trackingCode);
        if (!$partnerProgram || !$partnerProgram['is_recurring']) {
            $this->logEvent('invoice_paid_no_recurring_program', [
                'invoice_id' => $invoice->id,
                'tracking_code' => $trackingCode
            ]);
            return;
        }

        $commission = $this->calculateCommission($amount, $partnerProgram);
        $status = $partnerProgram['reward_days'] > 0 ? 'pending' : 'payable';

        try {
            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $invoice->payment_intent,
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => $status,
                'customer_email' => $invoice->customer_email ?? null,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null,
                    'subscription_id' => $invoice->subscription
                ])
            ]);

            $this->logEvent('recurring_conversion_created', [
                'invoice_id' => $invoice->id,
                'tracking_code' => $trackingCode,
                'amount' => $amount,
                'commission' => $commission,
                'subscription_id' => $invoice->subscription
            ]);
        } catch (\Exception $e) {
            $this->logEvent('recurring_conversion_failed', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoice->id,
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function getPartnerProgramById(int $id): ?array {
        return Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.id = ? AND pp.status = 'active' AND p.status = 'active'
             LIMIT 1",
            [$id]
        )->fetch() ?: null;
    }

    private function getPartnerProgramByTrackingCode(string $trackingCode): ?array {
        return Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.tracking_code = ? AND pp.status = 'active' AND p.status = 'active'
             LIMIT 1",
            [$trackingCode]
        )->fetch() ?: null;
    }


    private function calculateCommission(float $amount, array $partnerProgram): float {
        if (!isset($partnerProgram['commission_type']) || !isset($partnerProgram['commission_value'])) {
            // Log the missing data for debugging
            $this->logEvent('commission_calculation_error', [
                'error' => 'Missing commission data in partner program',
                'partner_program' => $partnerProgram
            ]);
            return 0.0; // Default to zero commission if data is missing
        }
        
        if ($partnerProgram['commission_type'] === 'percentage') {
            return round($amount * ($partnerProgram['commission_value'] / 100), 2);
        }
        return (float)$partnerProgram['commission_value'];
    }

    private function logEvent(string $type, $data): void {
        Database::insert('logs', [
            'type' => $type,
            'message' => is_string($data) ? $data : json_encode($data),
            'context' => is_string($data) ? null : json_encode($data)
        ]);
    }

    public function tremendousWebhook(): void {
        $payload = @file_get_contents('php://input');
        if (!is_string($payload)) {
            http_response_code(400);
            echo 'invalid payload';
            return;
        }

        $signature = $this->getTremendousSignatureHeader();
        $secret = trim((string) $this->getSettingValue('tremendous_webhook_private_key'));
        if ($secret === '') {
            $this->logEvent('tremendous_webhook_missing_secret', ['signature' => $signature]);
            // Acknowledge to avoid endless retries when local config is missing.
            http_response_code(200);
            echo 'ok';
            return;
        }

        if (!$this->isValidTremendousSignature($payload, $signature, $secret)) {
            $this->logEvent('tremendous_webhook_invalid_signature', ['signature' => $signature]);
            http_response_code(401);
            echo 'invalid signature';
            return;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            $this->logEvent('tremendous_webhook_invalid_json', [
                'payload_preview' => mb_substr($payload, 0, 512),
                'payload_length' => strlen($payload),
            ]);
            http_response_code(400);
            echo 'invalid json';
            return;
        }

        $eventName = strtoupper(trim((string) ($event['event'] ?? $event['type'] ?? '')));
        if ($eventName === '') {
            $this->logEvent('tremendous_webhook_missing_event', [
                'payload_keys' => array_keys($event),
            ]);
            http_response_code(400);
            echo 'missing event';
            return;
        }

        $orderId = $this->extractTremendousOrderIdFromEvent($event);
        if ($orderId === '') {
            $this->logEvent('tremendous_webhook_missing_order_id', ['event' => $eventName, 'payload' => $event]);
            http_response_code(200);
            echo 'ok';
            return;
        }

        try {
            switch ($eventName) {
                case 'ORDERS.APPROVED':
                case 'ORDERS.EXECUTED':
                    $this->applyTremendousWebhookPaid($orderId, $eventName);
                    break;
                case 'ORDERS.CANCELED':
                    $this->applyTremendousWebhookTerminal($orderId, 'canceled', 'Tremendous order failed with status: CANCELED');
                    break;
                case 'ORDERS.FAILED':
                    $this->applyTremendousWebhookTerminal($orderId, 'failed', 'Tremendous order failed with status: FAILED');
                    break;
                default:
                    // Acknowledge unrelated events.
                    break;
            }
        } catch (\Throwable $e) {
            $this->logEvent('tremendous_webhook_processing_failed', [
                'event' => $eventName,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo 'processing failed';
            return;
        }

        http_response_code(200);
        echo 'ok';
    }

    private function getSettingValue(string $name): ?string {
        $row = Database::query(
            "SELECT value FROM settings WHERE name = ? LIMIT 1",
            [$name]
        )->fetch();

        if (!$row) {
            return null;
        }

        return (string) ($row['value'] ?? '');
    }

    private function getTremendousSignatureHeader(): string {
        $candidates = [
            $_SERVER['HTTP_TREMENDOUS_WEBHOOK_SIGNATURE'] ?? '',
            $_SERVER['HTTP_X_TREMENDOUS_WEBHOOK_SIGNATURE'] ?? '',
            $_SERVER['HTTP_TREMENDOUS_SIGNATURE'] ?? '',
        ];
        foreach ($candidates as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function isValidTremendousSignature(string $payload, string $providedSignature, string $secret): bool {
        $providedSignature = trim($providedSignature);
        if ($providedSignature === '') {
            return false;
        }

        // Accept only a single signature value: "<hex>" or "sha256=<hex>".
        if (str_contains($providedSignature, ',')) {
            return false;
        }

        $candidate = strtolower($providedSignature);
        if (str_starts_with($candidate, 'sha256=')) {
            $candidate = substr($candidate, 7);
        }
        $candidate = trim($candidate);
        if (!ctype_xdigit($candidate) || strlen($candidate) !== 64) {
            return false;
        }

        $expectedHex = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedHex, $candidate);
    }

    private function extractTremendousOrderIdFromEvent(array $event): string {
        $candidates = [
            $event['payload']['resource']['id'] ?? null,
            $event['payload']['order']['id'] ?? null,
            $event['order']['id'] ?? null,
            $event['data']['order']['id'] ?? null,
            $event['data']['object']['id'] ?? null,
            $event['order_id'] ?? null,
            $event['payload']['order_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '' && preg_match('/^[A-Z0-9]{4,20}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    private function applyTremendousWebhookPaid(string $orderId, string $eventName): void {
        Database::transaction(function () use ($orderId, $eventName): void {
            $payouts = Database::query(
                "SELECT id
                 FROM payouts
                 WHERE payout_method = 'tremendous'
                   AND tremendous_order_id = ?",
                [$orderId]
            )->fetchAll();

            foreach ($payouts as $row) {
                $payoutId = (int) ($row['id'] ?? 0);
                if ($payoutId <= 0) {
                    continue;
                }

                $updated = Database::query(
                    "UPDATE payouts
                     SET status = 'paid',
                         failure_reason = NULL
                     WHERE id = ?
                       AND status IN ('processing', 'pending_approval', 'pending_internal_payment_approval')",
                    [$payoutId]
                );
                if ((int) $updated->rowCount() === 0) {
                    continue;
                }

                Database::query(
                    "UPDATE conversions
                     SET status = 'paid',
                         last_payout_failure_reason = NULL,
                         last_payout_failed_at = NULL
                     WHERE payout_id = ?
                       AND status = 'payable'",
                    [$payoutId]
                );
            }
        });

        $this->logEvent('tremendous_webhook_processed', [
            'event' => $eventName,
            'order_id' => $orderId,
        ]);
    }

    private function applyTremendousWebhookTerminal(string $orderId, string $terminalStatus, string $failureReason): void {
        $isCanceled = $terminalStatus === 'canceled';
        Database::transaction(function () use ($orderId, $terminalStatus, $failureReason, $isCanceled): void {
            $payouts = Database::query(
                "SELECT id
                 FROM payouts
                 WHERE payout_method = 'tremendous'
                   AND tremendous_order_id = ?",
                [$orderId]
            )->fetchAll();

            foreach ($payouts as $row) {
                $payoutId = (int) ($row['id'] ?? 0);
                if ($payoutId <= 0) {
                    continue;
                }

                $updated = Database::query(
                    "UPDATE payouts
                     SET status = ?,
                         failure_reason = ?
                     WHERE id = ?
                       AND status IN ('processing', 'pending_approval', 'pending_internal_payment_approval')",
                    [$terminalStatus, $failureReason, $payoutId]
                );
                if ((int) $updated->rowCount() === 0) {
                    continue;
                }

                if ($isCanceled) {
                    Database::query(
                        "UPDATE conversions
                         SET payout_id = NULL,
                             last_payout_failure_reason = NULL,
                             last_payout_failed_at = NOW()
                         WHERE payout_id = ?
                           AND status = 'payable'",
                        [$payoutId]
                    );
                } else {
                    Database::query(
                        "UPDATE conversions
                         SET payout_id = NULL,
                             last_payout_failure_reason = ?,
                             last_payout_failed_at = NOW()
                         WHERE payout_id = ?
                           AND status = 'payable'",
                        [$failureReason, $payoutId]
                    );
                }
            }
        });

        $this->logEvent('tremendous_webhook_processed', [
            'event' => strtoupper($terminalStatus),
            'order_id' => $orderId,
        ]);
    }
}
