<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\AuthMiddleware;

class ConversionsController extends Controller {
    public function __construct() {
        AuthMiddleware::handle();
    }

    public function index(): void {
        $this->promoteMaturePendingConversions();

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
                    prog.name as program_name,
                    pp.tracking_code,
                    po.payout_method,
                    po.stripe_customer_balance_transaction_id
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

        $settings = $this->getSettings();
        $this->view('conversions/index', [
            'title' => 'Conversions - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'conversions' => $conversions,
            'partners' => $partners,
            'programs' => $programs,
            'totals' => $totals,
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

        $this->promoteMaturePendingConversions();

        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $payoutSource = $_POST['payout_source'] ?? null;

        if (!$id || !in_array($status, ['pending', 'payable', 'rejected', 'paid'])) {
            $_SESSION['error'] = 'Invalid request parameters';
            header('Location: /admin/conversions');
            exit;
        }

        try {
            if ($status === 'paid') {
                $this->handlePaidStatusUpdate($id, $payoutSource);
            } else {
                Database::update(
                    'conversions',
                    ['status' => $status],
                    'id = ?',
                    [$id]
                );
            }

            $_SESSION['success'] = 'Conversion status updated successfully.';
        } catch (\Exception $e) {
            error_log('Failed to update conversion status: ' . $e->getMessage());
            $_SESSION['error'] = 'Failed to update conversion status: ' . $e->getMessage();
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

    private function handlePaidStatusUpdate(int $conversionId, ?string $payoutSource = null): void {
        $conversion = Database::query(
            "SELECT
                c.id,
                c.status,
                c.commission_amount,
                pp.partner_id,
                p.stripe_customer_id
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p ON pp.partner_id = p.id
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

        $commissionAmount = (float) $conversion['commission_amount'];
        if ($commissionAmount <= 0) {
            throw new \RuntimeException('Conversion commission must be greater than zero.');
        }

        $allowedSources = $this->getEnabledPayoutMethods();
        $requestedSource = is_string($payoutSource) ? trim($payoutSource) : '';
        if ($requestedSource !== '' && !in_array($requestedSource, $allowedSources, true)) {
            throw new \RuntimeException('Invalid payout source selected.');
        }

        $stripeCustomerId = $conversion['stripe_customer_id'] ?? null;
        $resolvedSource = $requestedSource !== ''
            ? $requestedSource
            : (!empty($stripeCustomerId) ? 'stripe_customer_balance' : 'manual');

        if ($resolvedSource === 'manual') {
            Database::transaction(function () use ($conversion, $commissionAmount, $conversionId) {
                $payoutId = Database::insert('payouts', [
                    'partner_id' => (int) $conversion['partner_id'],
                    'stripe_transfer_id' => null,
                    'stripe_customer_balance_transaction_id' => null,
                    'payout_method' => 'manual',
                    'amount' => $commissionAmount,
                    'fee_amount' => 0.00,
                    'net_amount' => $commissionAmount,
                    'status' => 'paid',
                    'failure_reason' => null
                ]);

                Database::update(
                    'conversions',
                    ['status' => 'paid', 'payout_id' => $payoutId],
                    'id = ?',
                    [$conversionId]
                );
            });
            return;
        }

        if (empty($stripeCustomerId)) {
            throw new \RuntimeException('Partner has no linked Stripe customer account for Stripe balance payout.');
        }

        $balanceTransactionId = $this->createStripeCustomerBalanceTransaction(
            $stripeCustomerId,
            $commissionAmount,
            $conversionId
        );

        Database::transaction(function () use ($conversion, $commissionAmount, $balanceTransactionId, $conversionId) {
            $payoutId = Database::insert('payouts', [
                'partner_id' => (int) $conversion['partner_id'],
                'stripe_transfer_id' => null,
                'stripe_customer_balance_transaction_id' => $balanceTransactionId,
                'payout_method' => 'stripe_customer_balance',
                'amount' => $commissionAmount,
                'fee_amount' => 0.00,
                'net_amount' => $commissionAmount,
                'status' => 'paid',
                'failure_reason' => null
            ]);

            Database::update(
                'conversions',
                ['status' => 'paid', 'payout_id' => $payoutId],
                'id = ?',
                [$conversionId]
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
}
