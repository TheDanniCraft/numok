<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\PartnerMiddleware;


class PayoutController extends Controller
{
    public function __construct()
    {
        PartnerMiddleware::handle();
    }

    public function linkCustomerAccount(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectToSettings();
        }

        $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));

        if (!$customerEmail || !$invoiceNumber) {
            $this->redirectToSettings('settings_error', 'Customer email and invoice number are required.');
        }

        $stripeApiKey = $this->getStripeApiKey();
        if (empty($stripeApiKey)) {
            $this->redirectToSettings('settings_error', 'Missing Stripe setup. Contact platform administrator.');
        }

        $query = "number:'" . $invoiceNumber . "'";

        $url = 'https://api.stripe.com/v1/invoices/search?' . http_build_query(['query' => $query]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $stripeApiKey,
                'Stripe-Version: 2023-10-16'
            ],
            CURLOPT_HTTPGET => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log('Stripe API request failed: ' . $curlError);
            $this->redirectToSettings('settings_error', 'An error occurred while processing your request. Please try again later.');
        }

        if ($httpCode === 200) {
            $invoiceData = json_decode($response, true);
            if (!is_array($invoiceData)) {
                $this->redirectToSettings('settings_error', 'An error occurred while processing your request. Please try again later.');
            }

            if (!empty($invoiceData['data'])) {
                $invoice = $invoiceData['data'][0];
                $invoiceCustomerEmail = strtolower((string) ($invoice['customer_email'] ?? ''));
                $inputCustomerEmail = strtolower($customerEmail);
                $stripeCustomerId = $invoice['customer'] ?? null;

                if ($invoiceCustomerEmail === $inputCustomerEmail && !empty($stripeCustomerId)) {
                    Database::query(
                        "UPDATE partners SET stripe_customer_id = ? WHERE id = ?",
                        [$stripeCustomerId, $_SESSION['partner_id']]
                    );

                    $this->redirectToSettings('settings_success', 'Customer account linked successfully.');
                }

                $this->redirectToSettings('settings_error', 'No customer account found for this invoice and email combination.');
            }

            $this->redirectToSettings('settings_error', 'No invoice found with the provided invoice number.');
        }

        if ($httpCode === 401) {
            error_log('Stripe API authentication failed. Check your API key.');
            $this->redirectToSettings('settings_error', 'An error occurred while processing your request. Please try again later.');
        }

        error_log('Stripe API error: ' . $response);
        $this->redirectToSettings('settings_error', 'An error occurred while processing your request. Please try again later.');
    }

    public function unlinkCustomerAccount(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectToSettings();
        }

        Database::query(
            "UPDATE partners SET stripe_customer_id = NULL WHERE id = ?",
            [$_SESSION['partner_id']]
        );

        $this->redirectToSettings('settings_success', 'Customer account unlinked successfully.');
    }

    public function payoutAvailableBalance(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectToEarnings();
        }

        $this->promoteMaturePendingConversions();

        try {
            $partnerId = (int) ($_SESSION['partner_id'] ?? 0);
            if ($partnerId <= 0) {
                $this->redirectToEarnings('earnings_error', 'Invalid partner session.');
            }

            $payoutSource = (string) ($_POST['payout_source'] ?? 'stripe_customer_balance');
            $supportedSources = array_values(array_intersect(
                ['stripe_customer_balance'],
                $this->getEnabledPayoutMethods()
            ));
            if (!in_array($payoutSource, $supportedSources, true)) {
                $this->redirectToEarnings('earnings_error', 'Unsupported payout source selected.');
            }

            $partner = Database::query(
                "SELECT stripe_customer_id FROM partners WHERE id = ? LIMIT 1",
                [$partnerId]
            )->fetch();

            if (!$partner || empty($partner['stripe_customer_id'])) {
                $this->redirectToEarnings('earnings_error', 'Please link your Stripe customer account first.');
            }

            $payableConversions = Database::query(
                "SELECT c.id, c.commission_amount
                 FROM conversions c
                 JOIN partner_programs pp ON c.partner_program_id = pp.id
                 WHERE pp.partner_id = ?
                 AND c.status = 'payable'
                 ORDER BY c.id ASC",
                [$partnerId]
            )->fetchAll();

            if (empty($payableConversions)) {
                $this->redirectToEarnings('earnings_error', 'No payable balance available.');
            }

            $conversionIds = array_map(static fn(array $row): int => (int) $row['id'], $payableConversions);
            $totalAmount = 0.0;
            foreach ($payableConversions as $row) {
                $totalAmount += (float) $row['commission_amount'];
            }
            $totalAmount = round($totalAmount, 2);

            if ($totalAmount <= 0) {
                $this->redirectToEarnings('earnings_error', 'No payable balance available.');
            }

            $minPayoutAmount = $this->getMinimumPayoutAmountForSource($payoutSource);
            if ($totalAmount < $minPayoutAmount) {
                $this->redirectToEarnings(
                    'earnings_error',
                    'Minimum payout amount for ' . $this->getPayoutSourceLabel($payoutSource) . ' is $' . number_format($minPayoutAmount, 2) . '.'
                );
            }

            $idempotencyKey = 'numok-partner-payout-' . $partnerId . '-' . sha1(implode(',', $conversionIds));
            if (count($conversionIds) === 1) {
                $description = 'Affiliate payout for conversion #' . $conversionIds[0];
            } else {
                $conversionIdLabels = array_map(static fn(int $id): string => '#' . $id, $conversionIds);
                $description = 'Affiliate payout for ' . count($conversionIds) . ' conversions (' . implode(', ', $conversionIdLabels) . ')';
            }
            if (strlen($description) > 240) {
                $description = 'Affiliate payout for ' . count($conversionIds) . ' conversions';
            }
            $balanceTransactionId = $this->createStripeCustomerBalanceTransaction(
                (string) $partner['stripe_customer_id'],
                $totalAmount,
                $description,
                $idempotencyKey
            );

            Database::transaction(function () use ($partnerId, $conversionIds, $totalAmount, $balanceTransactionId) {
                $payoutId = Database::insert('payouts', [
                    'partner_id' => $partnerId,
                    'stripe_transfer_id' => null,
                    'stripe_customer_balance_transaction_id' => $balanceTransactionId,
                    'payout_method' => 'stripe_customer_balance',
                    'amount' => $totalAmount,
                    'fee_amount' => 0.00,
                    'net_amount' => $totalAmount,
                    'status' => 'paid',
                    'failure_reason' => null
                ]);

                $placeholders = implode(',', array_fill(0, count($conversionIds), '?'));
                Database::query(
                    "UPDATE conversions
                     SET status = 'paid', payout_id = ?
                     WHERE status = 'payable'
                     AND id IN ({$placeholders})",
                    array_merge([$payoutId], $conversionIds)
                );
            });

            $this->redirectToEarnings(
                'earnings_success',
                'Payout successful. $' . number_format($totalAmount, 2) . ' has been credited to your Stripe customer balance.'
            );
        } catch (\Exception $e) {
            error_log('Partner payout failed: ' . $e->getMessage());
            $this->redirectToEarnings('earnings_error', 'Payout failed. Please try again later or contact support.');
        }
    }

    private function getStripeApiKey(): string
    {
        $settings = $this->getSettings();
        return $settings['stripe_secret_key'] ?? '';
    }

    private function createStripeCustomerBalanceTransaction(
        string $stripeCustomerId,
        float $amount,
        string $description,
        string $idempotencyKey
    ): string {
        $stripeApiKey = $this->getStripeApiKey();
        if ($stripeApiKey === '') {
            throw new \RuntimeException('Stripe secret key is missing.');
        }

        $amountInCents = (int) round($amount * 100);
        if ($amountInCents <= 0) {
            throw new \RuntimeException('Invalid payout amount.');
        }

        $endpoint = 'https://api.stripe.com/v1/customers/' . rawurlencode($stripeCustomerId) . '/balance_transactions';
        $payload = http_build_query([
            'amount' => -$amountInCents,
            'currency' => 'usd',
            'description' => $description
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $stripeApiKey,
                'Stripe-Version: 2023-10-16',
                'Idempotency-Key: ' . $idempotencyKey,
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

    private function redirectToSettings(?string $flashType = null, ?string $message = null): void
    {
        if ($flashType !== null && $message !== null) {
            $_SESSION[$flashType] = $message;
        }

        header('Location: /settings');
        exit;
    }

    private function redirectToEarnings(?string $flashType = null, ?string $message = null): void
    {
        if ($flashType !== null && $message !== null) {
            $_SESSION[$flashType] = $message;
        }

        header('Location: /earnings');
        exit;
    }

    private function getMinimumPayoutAmountForSource(string $payoutSource): float
    {
        $settings = $this->getSettings();
        $sourceKey = 'min_payout_amount_' . $payoutSource;
        if (isset($settings[$sourceKey]) && is_numeric($settings[$sourceKey])) {
            return max(0.0, (float) $settings[$sourceKey]);
        }

        if (isset($settings['min_payout_amount']) && is_numeric($settings['min_payout_amount'])) {
            return max(0.0, (float) $settings['min_payout_amount']);
        }

        return 0.0;
    }

    private function getPayoutSourceLabel(string $payoutSource): string
    {
        $labels = [
            'stripe_customer_balance' => 'Stripe Customer Balance',
            'manual' => 'Manual'
        ];

        return $labels[$payoutSource] ?? 'selected payout source';
    }
}
