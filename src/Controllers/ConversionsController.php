<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\AuthMiddleware;

class ConversionsController extends Controller {
    public function __construct() {
        AuthMiddleware::handle();
    }

    public function index(): void {
        // Get filter parameters
        $status = $_GET['status'] ?? 'all';
        $partnerId = intval($_GET['partner_id'] ?? 0);
        $programId = intval($_GET['program_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';

        // Build query conditions
        $conditions = [];
        $params = [];

        if ($status !== 'all') {
            $conditions[] = "c.status = ?";
            $params[] = $status;
        }

        if ($partnerId > 0) {
            $conditions[] = "p.id = ?";
            $params[] = $partnerId;
        }

        if ($programId > 0) {
            $conditions[] = "prog.id = ?";
            $params[] = $programId;
        }

        if ($startDate) {
            $conditions[] = "DATE(c.created_at) >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $conditions[] = "DATE(c.created_at) <= ?";
            $params[] = $endDate;
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Get conversions with joins
        $conversions = Database::query(
            "SELECT c.*, 
                    p.company_name as partner_name,
                    p.stripe_customer_id,
                    p.email as partner_email,
                    prog.name as program_name,
                    prog.tremendous_campaign_id as program_tremendous_campaign_id,
                    pp.tracking_code,
                    po.payout_method,
                    po.status as payout_status,
                    po.stripe_customer_balance_transaction_id,
                    po.tremendous_order_id
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p ON pp.partner_id = p.id
             JOIN programs prog ON pp.program_id = prog.id
             LEFT JOIN payouts po ON c.payout_id = po.id
             {$whereClause}
             ORDER BY c.created_at DESC
             LIMIT 100",
            $params
        )->fetchAll();

        // Get all partners for filter
        $partners = Database::query(
            "SELECT id, company_name FROM partners WHERE status = 'active' ORDER BY company_name"
        )->fetchAll();

        // Get all programs for filter
        $programs = Database::query(
            "SELECT id, name FROM programs WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        // Calculate totals
        $totals = [
            'count' => count($conversions),
            'amount' => array_sum(array_column($conversions, 'amount')),
            'commission' => array_sum(array_column($conversions, 'commission_amount'))
        ];

        $failedPayoutAlerts = Database::query(
            "SELECT
                po.id,
                po.partner_id,
                p.company_name AS partner_name,
                po.amount,
                po.failure_reason,
                po.created_at
             FROM payouts po
             JOIN partners p ON po.partner_id = p.id
             WHERE po.status = 'failed'
               AND po.payout_method = 'tremendous'
             ORDER BY po.created_at DESC
             LIMIT 10"
        )->fetchAll();

        $pendingPayoutAlerts = Database::query(
            "SELECT
                po.id,
                po.partner_id,
                p.company_name AS partner_name,
                po.amount,
                po.tremendous_order_id,
                po.created_at
             FROM payouts po
             JOIN partners p ON po.partner_id = p.id
             WHERE po.status IN ('processing', 'pending_approval')
               AND po.payout_method = 'tremendous'
             ORDER BY po.created_at DESC
             LIMIT 10"
        )->fetchAll();

        $internalPendingPayoutAlerts = Database::query(
            "SELECT
                po.id,
                po.partner_id,
                p.company_name AS partner_name,
                po.amount,
                po.tremendous_order_id,
                po.created_at
             FROM payouts po
             JOIN partners p ON po.partner_id = p.id
             WHERE po.status = 'pending_internal_payment_approval'
               AND po.payout_method = 'tremendous'
             ORDER BY po.created_at DESC
             LIMIT 10"
        )->fetchAll();

        $failedInsufficientSummary = Database::query(
            "SELECT
                COUNT(*) AS failed_count,
                COALESCE(SUM(po.amount), 0) AS failed_amount
             FROM payouts po
             WHERE po.status = 'failed'
               AND po.payout_method = 'tremendous'
               AND (
                    LOWER(COALESCE(po.failure_reason, '')) LIKE '%not enough funds%'
                    OR LOWER(COALESCE(po.failure_reason, '')) LIKE '%insufficient funds%'
                    OR LOWER(COALESCE(po.failure_reason, '')) LIKE '%insufficient balance%'
               )"
        )->fetch() ?: ['failed_count' => 0, 'failed_amount' => 0];

        $pendingSummary = Database::query(
            "SELECT
                COUNT(*) AS pending_count,
                COALESCE(SUM(po.amount), 0) AS pending_amount
             FROM payouts po
             WHERE po.status IN ('processing', 'pending_approval')
               AND po.payout_method = 'tremendous'
             "
        )->fetch() ?: ['pending_count' => 0, 'pending_amount' => 0];

        $internalPendingSummary = Database::query(
            "SELECT
                COUNT(*) AS pending_count,
                COALESCE(SUM(po.amount), 0) AS pending_amount
             FROM payouts po
             WHERE po.status = 'pending_internal_payment_approval'
               AND po.payout_method = 'tremendous'
             "
        )->fetch() ?: ['pending_count' => 0, 'pending_amount' => 0];

        $settings = $this->getSettings();
        $this->view('conversions/index', [
            'title' => 'Conversions - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'conversions' => $conversions,
            'partners' => $partners,
            'programs' => $programs,
            'totals' => $totals,
            'pending_payout_alerts' => $pendingPayoutAlerts,
            'pending_summary' => [
                'pending_count' => (int) ($pendingSummary['pending_count'] ?? 0),
                'pending_amount' => (float) ($pendingSummary['pending_amount'] ?? 0)
            ],
            'internal_pending_payout_alerts' => $internalPendingPayoutAlerts,
            'internal_pending_summary' => [
                'pending_count' => (int) ($internalPendingSummary['pending_count'] ?? 0),
                'pending_amount' => (float) ($internalPendingSummary['pending_amount'] ?? 0)
            ],
            'failed_payout_alerts' => $failedPayoutAlerts,
            'failed_insufficient_summary' => [
                'failed_count' => (int) ($failedInsufficientSummary['failed_count'] ?? 0),
                'failed_amount' => (float) ($failedInsufficientSummary['failed_amount'] ?? 0)
            ],
            'filters' => [
                'status' => $status,
                'partner_id' => $partnerId,
                'program_id' => $programId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
    }

    public function updateStatus(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/conversions');
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $payoutSource = $_POST['payout_source'] ?? null;

        if (!$id || !in_array($status, ['pending', 'payable', 'rejected', 'paid'])) {
            $_SESSION['error'] = 'Invalid request parameters';
            header('Location: /admin/conversions');
            exit;
        }

        try {
            $partnerId = $this->getPartnerIdForConversion($id);
            $statusMessage = null;
            $this->withNamedLock('partner_payout_' . $partnerId, function () use ($id, $status, $payoutSource, &$statusMessage): void {
                if ($status === 'paid') {
                    $statusMessage = $this->executePaidStatusUpdate($id, $payoutSource);
                    return;
                }

                $existing = Database::query(
                    "SELECT id, payout_id FROM conversions WHERE id = ? LIMIT 1",
                    [$id]
                )->fetch();
                if (!$existing) {
                    throw new \RuntimeException('Conversion not found.');
                }
                if (!empty($existing['payout_id'])) {
                    throw new \RuntimeException('Conversion status cannot be changed while a payout is in progress.');
                }
                Database::update(
                    'conversions',
                    ['status' => $status],
                    'id = ?',
                    [$id]
                );
            });

            $_SESSION['success'] = $statusMessage ?: 'Conversion status updated successfully.';
        } catch (\Exception $e) {
            error_log('Failed to update conversion status: ' . $e->getMessage());
            if ($this->isTremendousInsufficientBalanceError($e)) {
                $_SESSION['warning'] = 'A payout failed due to insufficient balance. Please top up your Tremendous balance.';
            } else {
                $_SESSION['error'] = 'Failed to update conversion status.';
            }
        }

        header('Location: /admin/conversions');
        exit;
    }

    public function export(): void {
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="conversions.csv"');

        // Create output handle
        $output = fopen('php://output', 'w');

        // Add CSV headers
        fputcsv($output, [
            'Date',
            'Partner',
            'Program',
            'Tracking Code',
            'Customer Email',
            'Amount',
            'Commission',
            'Status'
        ]);

        // Get all conversions
        $conversions = Database::query(
            "SELECT 
                c.created_at,
                p.company_name as partner_name,
                prog.name as program_name,
                pp.tracking_code,
                c.customer_email,
                c.amount,
                c.commission_amount,
                c.status
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p ON pp.partner_id = p.id
             JOIN programs prog ON pp.program_id = prog.id
             ORDER BY c.created_at DESC"
        )->fetchAll();

        // Add data rows
        foreach ($conversions as $conversion) {
            fputcsv($output, [
                date('Y-m-d H:i:s', strtotime($conversion['created_at'])),
                $conversion['partner_name'],
                $conversion['program_name'],
                $conversion['tracking_code'],
                $conversion['customer_email'],
                number_format($conversion['amount'], 2),
                number_format($conversion['commission_amount'], 2),
                ucfirst($conversion['status'])
            ]);
        }

        fclose($output);
        exit;
    }

    private function executePaidStatusUpdate(int $conversionId, ?string $payoutSource = null): ?string {
        $conversion = Database::query(
            "SELECT
                c.id,
                c.status,
                c.payout_id,
                c.commission_amount,
                c.last_payout_failed_at,
                pp.partner_id,
                p.stripe_customer_id,
                p.email as partner_email,
                p.contact_name as partner_contact_name,
                p.company_name as partner_company_name,
                prog.tremendous_campaign_id
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p ON pp.partner_id = p.id
             JOIN programs prog ON pp.program_id = prog.id
             WHERE c.id = ?
             LIMIT 1",
            [$conversionId]
        )->fetch();

        if (!$conversion) {
            throw new \RuntimeException('Conversion not found.');
        }

        if ($conversion['status'] !== 'payable') {
            throw new \RuntimeException('Only payable conversions can be marked as paid.');
        }
        if (!empty($conversion['payout_id'])) {
            throw new \RuntimeException('A payout is already in progress for this conversion.');
        }

        $commissionAmount = (float) $conversion['commission_amount'];
        if ($commissionAmount <= 0) {
            throw new \RuntimeException('Conversion commission must be greater than zero.');
        }
        $stripeCustomerId = trim((string) ($conversion['stripe_customer_id'] ?? ''));

        $allowedSources = $this->getEnabledPayoutMethods();
        $requestedSource = is_string($payoutSource) ? trim($payoutSource) : '';
        if ($requestedSource !== '' && !in_array($requestedSource, $allowedSources, true)) {
            throw new \RuntimeException('Invalid payout source selected.');
        }

        $resolvedSource = $requestedSource !== ''
            ? $requestedSource
            : 'manual';

        if ($resolvedSource === 'manual') {
            Database::transaction(function () use ($conversion, $commissionAmount, $conversionId) {
                $payoutId = $this->createSuccessfulPayout(
                    (int) $conversion['partner_id'],
                    'manual',
                    $commissionAmount,
                    null,
                    null
                );

                Database::update(
                    'conversions',
                    [
                        'status' => 'paid',
                        'payout_id' => $payoutId,
                        'last_payout_failure_reason' => null,
                        'last_payout_failed_at' => null
                    ],
                    'id = ?',
                    [$conversionId]
                );
            });
            return null;
        }

        if ($resolvedSource === 'stripe_customer_balance') {
            if (empty($stripeCustomerId)) {
                throw new \RuntimeException('Partner has no linked Stripe customer account for Stripe balance payout.');
            }

            $payoutId = $this->reserveConversionPayout(
                (int) $conversion['partner_id'],
                $conversionId,
                'stripe_customer_balance',
                $commissionAmount
            );

            try {
                $balanceTransactionId = $this->createStripeCustomerBalanceTransaction(
                    $stripeCustomerId,
                    $commissionAmount,
                    $conversionId
                );
            } catch (\Exception $e) {
                $this->failReservedConversionPayout(
                    $payoutId,
                    $conversionId,
                    'failed',
                    $e->getMessage()
                );
                throw $e;
            }

            Database::transaction(function () use ($payoutId, $conversionId, $balanceTransactionId): void {
                Database::update(
                    'payouts',
                    [
                        'status' => 'paid',
                        'stripe_customer_balance_transaction_id' => $balanceTransactionId,
                        'failure_reason' => null
                    ],
                    'id = ?',
                    [$payoutId]
                );

                Database::query(
                    "UPDATE conversions
                     SET status = 'paid',
                         payout_id = ?,
                         last_payout_failure_reason = NULL,
                         last_payout_failed_at = NULL
                     WHERE id = ?
                       AND payout_id = ?",
                    [$payoutId, $conversionId, $payoutId]
                );
            });
            return null;
        }

        if ($resolvedSource === 'tremendous') {
            $recipientEmail = trim((string) ($conversion['partner_email'] ?? ''));
            if ($recipientEmail === '') {
                throw new \RuntimeException('Partner email is missing for Tremendous payout.');
            }
            $recipientName = trim((string) ($conversion['partner_contact_name'] ?? ''));
            if ($recipientName === '') {
                $recipientName = trim((string) ($conversion['partner_company_name'] ?? 'Partner'));
            }

            $payoutId = $this->reserveConversionPayout(
                (int) $conversion['partner_id'],
                $conversionId,
                'tremendous',
                $commissionAmount
            );

            try {
                $retryToken = '0';
                $terminalPayout = Database::query(
                    "SELECT MAX(id) AS max_id
                     FROM payouts
                     WHERE partner_id = ?
                       AND payout_method = 'tremendous'
                       AND status IN ('failed', 'canceled')",
                    [(int) $conversion['partner_id']]
                )->fetch();
                if (!empty($terminalPayout['max_id'])) {
                    $retryToken = (string) ((int) $terminalPayout['max_id']);
                } elseif (!empty($conversion['last_payout_failed_at'])) {
                    $retryToken = (string) strtotime((string) $conversion['last_payout_failed_at']);
                }
                $externalId = $this->buildTremendousExternalId('numok-conversion-payout-' . $conversionId, $retryToken);
                $tremendousOrder = $this->createTremendousOrder(
                    $recipientName,
                    $recipientEmail,
                    $commissionAmount,
                    (string) ($conversion['tremendous_campaign_id'] ?? ''),
                    $externalId,
                    'Affiliate payout for conversion #' . $conversionId
                );
            } catch (\Exception $e) {
                $this->failReservedConversionPayout(
                    $payoutId,
                    $conversionId,
                    'failed',
                    $e->getMessage()
                );
                throw $e;
            }

            $tremendousStatus = (string) ($tremendousOrder['order_status'] ?? '');
            if ($this->isTremendousOrderFailureStatus($tremendousStatus)) {
                $normalizedStatus = strtoupper(trim($tremendousStatus));
                $isCanceled = $normalizedStatus === 'CANCELED';
                $failureReason = 'Tremendous order failed with status: ' . $normalizedStatus;
                $this->failReservedConversionPayout(
                    $payoutId,
                    $conversionId,
                    $isCanceled ? 'canceled' : 'failed',
                    $failureReason,
                    (string) ($tremendousOrder['order_id'] ?? '')
                );
                if ($isCanceled) {
                    throw new \RuntimeException('Tremendous payout was canceled.');
                }
                throw new \RuntimeException('Tremendous payout failed: order status is ' . $normalizedStatus . '.');
            }

            if ($this->isTremendousOrderPendingStatus($tremendousStatus)) {
                $pendingPayoutStatus = $this->mapTremendousPendingPayoutStatus($tremendousStatus);
                Database::transaction(function () use ($payoutId, $conversionId, $tremendousOrder, $pendingPayoutStatus): void {
                    Database::update(
                        'payouts',
                        [
                            'status' => $pendingPayoutStatus,
                            'tremendous_order_id' => (string) $tremendousOrder['order_id'],
                            'failure_reason' => null
                        ],
                        'id = ?',
                        [$payoutId]
                    );

                    Database::query(
                        "UPDATE conversions
                         SET payout_id = ?,
                             last_payout_failure_reason = NULL,
                             last_payout_failed_at = NULL
                         WHERE id = ?
                           AND payout_id = ?",
                        [$payoutId, $conversionId, $payoutId]
                    );
                });

                return 'Payout request created and is waiting for Tremendous approval.';
            }

            Database::transaction(function () use ($payoutId, $conversionId, $tremendousOrder): void {
                Database::update(
                    'payouts',
                    [
                        'status' => 'paid',
                        'tremendous_order_id' => (string) $tremendousOrder['order_id'],
                        'failure_reason' => null
                    ],
                    'id = ?',
                    [$payoutId]
                );

                Database::query(
                    "UPDATE conversions
                     SET status = 'paid',
                         payout_id = ?,
                         last_payout_failure_reason = NULL,
                         last_payout_failed_at = NULL
                     WHERE id = ?
                       AND payout_id = ?",
                    [$payoutId, $conversionId, $payoutId]
                );
            });
            return null;
        }

        throw new \RuntimeException('Unsupported payout source selected.');
    }

    private function reserveConversionPayout(int $partnerId, int $conversionId, string $payoutMethod, float $amount): int {
        return Database::transaction(function () use ($partnerId, $conversionId, $payoutMethod, $amount): int {
            $payoutId = Database::insert('payouts', [
                'partner_id' => $partnerId,
                'stripe_customer_balance_transaction_id' => null,
                'tremendous_order_id' => null,
                'payout_method' => $payoutMethod,
                'amount' => round($amount, 2),
                'status' => 'processing',
                'failure_reason' => null
            ]);

            $stmt = Database::query(
                "UPDATE conversions
                 SET payout_id = ?,
                     last_payout_failure_reason = NULL,
                     last_payout_failed_at = NULL
                 WHERE id = ?
                   AND status = 'payable'
                   AND payout_id IS NULL",
                [$payoutId, $conversionId]
            );
            if ((int) $stmt->rowCount() !== 1) {
                throw new \RuntimeException('Conversion changed during payout processing.');
            }

            return $payoutId;
        });
    }

    private function failReservedConversionPayout(
        int $payoutId,
        int $conversionId,
        string $payoutStatus,
        string $reason,
        ?string $tremendousOrderId = null
    ): void {
        if (!in_array($payoutStatus, ['failed', 'canceled'], true)) {
            $payoutStatus = 'failed';
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Unknown payout failure.';
        }
        $reason = mb_substr($reason, 0, 255);

        Database::transaction(function () use ($payoutId, $conversionId, $payoutStatus, $reason, $tremendousOrderId): void {
            Database::update(
                'payouts',
                [
                    'status' => $payoutStatus,
                    'failure_reason' => $reason,
                    'tremendous_order_id' => $tremendousOrderId !== null && $tremendousOrderId !== '' ? $tremendousOrderId : null
                ],
                'id = ?',
                [$payoutId]
            );

            if ($payoutStatus === 'failed') {
                Database::update(
                    'conversions',
                    [
                        'payout_id' => null,
                        'last_payout_failure_reason' => $reason,
                        'last_payout_failed_at' => date('Y-m-d H:i:s')
                    ],
                    'id = ? AND payout_id = ?',
                    [$conversionId, $payoutId]
                );
                return;
            }

            Database::update(
                'conversions',
                [
                    'payout_id' => null,
                    'last_payout_failure_reason' => null,
                    'last_payout_failed_at' => date('Y-m-d H:i:s')
                ],
                'id = ? AND payout_id = ?',
                [$conversionId, $payoutId]
            );
        });
    }

    private function createStripeCustomerBalanceTransaction(string $stripeCustomerId, float $commissionAmount, int $conversionId): string {
        $stripeApiKey = $this->getStripeApiKey();
        if ($stripeApiKey === '') {
            throw new \RuntimeException('Stripe secret key is missing.');
        }

        $amountInCents = (int) round($commissionAmount * 100);
        if ($amountInCents <= 0) {
            throw new \RuntimeException('Invalid payout amount.');
        }

        $endpoint = 'https://api.stripe.com/v1/customers/' . rawurlencode($stripeCustomerId) . '/balance_transactions';
        $payload = http_build_query([
            'amount' => -$amountInCents,
            'currency' => 'usd',
            'description' => 'Affiliate payout for conversion #' . $conversionId
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $stripeApiKey,
                'Stripe-Version: 2023-10-16',
                'Idempotency-Key: numok-conversion-payout-' . $conversionId,
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Stripe payout request failed: ' . $curlError);
        }

        $responseData = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($responseData) || empty($responseData['id'])) {
            $stripeError = $responseData['error']['message'] ?? 'Unknown Stripe API error.';
            throw new \RuntimeException('Stripe payout failed: ' . $stripeError);
        }

        return (string) $responseData['id'];
    }

    private function getStripeApiKey(): string {
        $settings = $this->getSettings();
        return $settings['stripe_secret_key'] ?? '';
    }

    private function getPartnerIdForConversion(int $conversionId): int {
        $lookup = Database::query(
            "SELECT pp.partner_id
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE c.id = ?
             LIMIT 1",
            [$conversionId]
        )->fetch();

        if (!$lookup || empty($lookup['partner_id'])) {
            throw new \RuntimeException('Conversion not found.');
        }

        return (int) $lookup['partner_id'];
    }

    private function isTremendousInsufficientBalanceError(\Exception $e): bool {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'tremendous')) {
            return false;
        }

        return str_contains($message, 'not enough funds')
            || str_contains($message, 'insufficient funds')
            || str_contains($message, 'insufficient balance');
    }

    private function isTremendousOrderPendingStatus(string $status): bool {
        $status = strtoupper(trim($status));
        return in_array($status, ['CART', 'PENDING APPROVAL', 'PENDING INTERNAL PAYMENT APPROVAL'], true);
    }

    private function isTremendousOrderFailureStatus(string $status): bool {
        $status = strtoupper(trim($status));
        return in_array($status, ['FAILED', 'CANCELED'], true);
    }

    private function mapTremendousPendingPayoutStatus(string $tremendousOrderStatus): string {
        $status = strtoupper(trim($tremendousOrderStatus));
        if ($status === 'PENDING INTERNAL PAYMENT APPROVAL') {
            return 'pending_internal_payment_approval';
        }

        return 'pending_approval';
    }

    private function createSuccessfulPayout(
        int $partnerId,
        string $payoutMethod,
        float $amount,
        ?string $stripeCustomerBalanceTransactionId,
        ?string $tremendousOrderId
    ): int {
        return Database::insert('payouts', [
            'partner_id' => $partnerId,
            'stripe_customer_balance_transaction_id' => $stripeCustomerBalanceTransactionId,
            'tremendous_order_id' => $tremendousOrderId,
            'payout_method' => $payoutMethod,
            'amount' => round($amount, 2),
            'status' => 'paid',
            'failure_reason' => null
        ]);
    }
}
