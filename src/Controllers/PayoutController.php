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

        $partnerId = (int) ($_SESSION['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            $this->redirectToEarnings('earnings_error', 'Invalid partner session.');
        }

        try {
            $payoutSource = trim((string) ($_POST['payout_source'] ?? ''));
            $result = $this->withNamedLock('partner_payout_' . $partnerId, function () use ($partnerId, $payoutSource): array {
                $supportedSources = array_values(array_intersect(
                    ['stripe_customer_balance', 'tremendous'],
                    $this->getEnabledPayoutMethods()
                ));
                $resolvedPayoutSource = $payoutSource !== '' ? $payoutSource : ($supportedSources[0] ?? '');
                if (!in_array($resolvedPayoutSource, $supportedSources, true)) {
                    throw new \InvalidArgumentException('Unsupported payout source selected.');
                }

                $partner = Database::query(
                    "SELECT stripe_customer_id, email, contact_name, company_name FROM partners WHERE id = ? LIMIT 1",
                    [$partnerId]
                )->fetch();

                if (!$partner) {
                    throw new \InvalidArgumentException('Partner not found.');
                }

                $payableConversions = Database::query(
                    "SELECT c.id, c.commission_amount, c.last_payout_failed_at, p.tremendous_campaign_id
                     FROM conversions c
                     JOIN partner_programs pp ON c.partner_program_id = pp.id
                     JOIN programs p ON pp.program_id = p.id
                     WHERE pp.partner_id = ?
                     AND c.status = 'payable'
                     AND c.payout_id IS NULL
                     ORDER BY c.id ASC",
                    [$partnerId]
                )->fetchAll();

                if (empty($payableConversions)) {
                    throw new \InvalidArgumentException('No payable balance available.');
                }

                $conversionIds = array_map(static fn(array $row): int => (int) $row['id'], $payableConversions);
                $totalAmount = 0.0;
                foreach ($payableConversions as $row) {
                    $totalAmount += (float) $row['commission_amount'];
                }
                $totalAmount = round($totalAmount, 2);

                if ($totalAmount <= 0) {
                    throw new \InvalidArgumentException('No payable balance available.');
                }

                $minPayoutAmount = $this->getMinimumPayoutAmountForSource($resolvedPayoutSource);
                if ($totalAmount < $minPayoutAmount) {
                    throw new \InvalidArgumentException(
                        'Minimum payout amount for ' . $this->getPayoutSourceLabel($resolvedPayoutSource) . ' is $' . number_format($minPayoutAmount, 2) . '.'
                    );
                }

                $idempotencyKey = 'numok-partner-payout-' . $partnerId . '-' . sha1(implode(',', $conversionIds));
                $retryToken = '0';
                $terminalPayout = Database::query(
                    "SELECT MAX(id) AS max_id
                     FROM payouts
                     WHERE partner_id = ?
                       AND payout_method = 'tremendous'
                       AND status IN ('failed', 'canceled')",
                    [$partnerId]
                )->fetch();
                if (!empty($terminalPayout['max_id'])) {
                    $retryToken = (string) ((int) $terminalPayout['max_id']);
                }
                $tremendousExternalId = $this->buildTremendousExternalId($idempotencyKey, $retryToken);
                if (count($conversionIds) === 1) {
                    $description = 'Affiliate payout for conversion #' . $conversionIds[0];
                } else {
                    $conversionIdLabels = array_map(static fn(int $id): string => '#' . $id, $conversionIds);
                    $description = 'Affiliate payout for ' . count($conversionIds) . ' conversions (' . implode(', ', $conversionIdLabels) . ')';
                }
                if (strlen($description) > 240) {
                    $description = 'Affiliate payout for ' . count($conversionIds) . ' conversions';
                }

                $balanceTransactionId = null;
                $tremendousOrderId = null;
                $tremendousOrderStatus = null;
                if ($resolvedPayoutSource === 'stripe_customer_balance') {
                    if (empty($partner['stripe_customer_id'])) {
                        throw new \InvalidArgumentException('Please link your Stripe customer account first.');
                    }
                    $balanceTransactionId = $this->createStripeCustomerBalanceTransaction(
                        (string) $partner['stripe_customer_id'],
                        $totalAmount,
                        $description,
                        $idempotencyKey
                    );
                } elseif ($resolvedPayoutSource === 'tremendous') {
                    $recipientEmail = trim((string) ($partner['email'] ?? ''));
                    if ($recipientEmail === '') {
                        throw new \InvalidArgumentException('Partner email is missing for Tremendous payout.');
                    }
                    $recipientName = trim((string) ($partner['contact_name'] ?? ''));
                    if ($recipientName === '') {
                        $recipientName = trim((string) ($partner['company_name'] ?? 'Partner'));
                    }

                    $campaignIds = array_values(array_unique(array_filter(array_map(
                        static fn(array $row): string => trim((string) ($row['tremendous_campaign_id'] ?? '')),
                        $payableConversions
                    ))));
                    if (count($campaignIds) !== 1) {
                        throw new \InvalidArgumentException('Tremendous payout requires one configured campaign ID across all payable conversions.');
                    }

                    try {
                        $tremendousOrder = $this->createTremendousOrder(
                            $recipientName,
                            $recipientEmail,
                            $totalAmount,
                            $campaignIds[0],
                            $tremendousExternalId,
                            $description
                        );
                    } catch (\Exception $e) {
                        $this->recordFailedPayoutAttempt(
                            $partnerId,
                            $totalAmount,
                            'tremendous',
                            $e->getMessage(),
                            $conversionIds
                        );
                        throw $e;
                    }
                    $tremendousOrderId = $tremendousOrder['order_id'];
                    $tremendousOrderStatus = (string) ($tremendousOrder['order_status'] ?? '');

                    if ($this->isTremendousOrderFailureStatus($tremendousOrderStatus)) {
                        $normalizedStatus = strtoupper(trim($tremendousOrderStatus));
                        $isCanceled = $normalizedStatus === 'CANCELED';
                        $this->recordFailedPayoutAttempt(
                            $partnerId,
                            $totalAmount,
                            'tremendous',
                            'Tremendous order failed with status: ' . $normalizedStatus,
                            $conversionIds,
                            $isCanceled ? 'canceled' : 'failed'
                        );
                        if ($isCanceled) {
                            throw new \RuntimeException('Tremendous payout was canceled.');
                        }
                        throw new \RuntimeException('Tremendous payout failed: order status is ' . $normalizedStatus . '.');
                    }
                }

                $isPendingApproval = $resolvedPayoutSource === 'tremendous'
                    && $this->isTremendousOrderPendingStatus((string) $tremendousOrderStatus);

                if ($isPendingApproval) {
                    $pendingPayoutStatus = $this->mapTremendousPendingPayoutStatus((string) $tremendousOrderStatus);
                    Database::transaction(function () use ($partnerId, $conversionIds, $totalAmount, $tremendousOrderId, $pendingPayoutStatus): void {
                        $payoutId = Database::insert('payouts', [
                            'partner_id' => $partnerId,
                            'stripe_customer_balance_transaction_id' => null,
                            'tremendous_order_id' => $tremendousOrderId,
                            'payout_method' => 'tremendous',
                            'amount' => $totalAmount,
                            'status' => $pendingPayoutStatus,
                            'failure_reason' => null
                        ]);

                        $placeholders = implode(',', array_fill(0, count($conversionIds), '?'));
                        $stmt = Database::query(
                            "UPDATE conversions
                             SET payout_id = ?, last_payout_failure_reason = NULL, last_payout_failed_at = NULL
                             WHERE status = 'payable'
                               AND payout_id IS NULL
                               AND id IN ({$placeholders})",
                            array_merge([$payoutId], $conversionIds)
                        );

                        if ((int) $stmt->rowCount() !== count($conversionIds)) {
                            throw new \RuntimeException('Payable conversions changed during payout processing.');
                        }
                    });
                } else {
                    Database::transaction(function () use ($partnerId, $conversionIds, $totalAmount, $balanceTransactionId, $tremendousOrderId, $resolvedPayoutSource): void {
                        $payoutId = $this->createSuccessfulPayout(
                            $partnerId,
                            $resolvedPayoutSource,
                            $totalAmount,
                            $balanceTransactionId,
                            $tremendousOrderId
                        );

                        $placeholders = implode(',', array_fill(0, count($conversionIds), '?'));
                        $stmt = Database::query(
                            "UPDATE conversions
                             SET status = 'paid', payout_id = ?, last_payout_failure_reason = NULL, last_payout_failed_at = NULL
                             WHERE status = 'payable'
                               AND payout_id IS NULL
                               AND id IN ({$placeholders})",
                            array_merge([$payoutId], $conversionIds)
                        );

                        if ((int) $stmt->rowCount() !== count($conversionIds)) {
                            throw new \RuntimeException('Payable conversions changed during payout processing.');
                        }
                    });
                }

                return [
                    'total_amount' => $totalAmount,
                    'source_key' => $resolvedPayoutSource,
                    'source_label' => $this->getPayoutSourceLabel($resolvedPayoutSource),
                    'tremendous_order_id' => $tremendousOrderId,
                    'tremendous_order_status' => $tremendousOrderStatus,
                    'is_pending_approval' => $isPendingApproval
                ];
            });

            if (($result['source_key'] ?? '') === 'tremendous') {
                $_SESSION['earnings_tremendous_notice'] = '1';
                if (!empty($result['tremendous_order_id'])) {
                    $_SESSION['earnings_tremendous_order_id'] = (string) $result['tremendous_order_id'];
                }
                $_SESSION['earnings_tremendous_order_status'] = (string) ($result['tremendous_order_status'] ?? '');
            }

            if (!empty($result['is_pending_approval'])) {
                $pendingStatus = strtoupper(trim((string) ($result['tremendous_order_status'] ?? '')));
                $pendingMessage = 'Payout request submitted. Tremendous order is pending approval.';
                if ($pendingStatus === 'PENDING APPROVAL') {
                    $pendingMessage = 'Payout request submitted. Tremendous order is waiting for approval from our team.';
                } elseif ($pendingStatus === 'PENDING INTERNAL PAYMENT APPROVAL') {
                    $pendingMessage = 'Payout request submitted. Tremendous order is under payment review by the Tremendous team.';
                } elseif ($pendingStatus === 'CART') {
                    $pendingMessage = 'Payout request submitted. Tremendous order has been created and is still being processed.';
                }
                $this->redirectToEarnings(
                    'earnings_success',
                    $pendingMessage
                );
            }

            $this->redirectToEarnings(
                'earnings_success',
                'Payout successful. $' . number_format((float) $result['total_amount'], 2) . ' has been sent via ' . $result['source_label'] . '.'
            );
        } catch (\InvalidArgumentException $e) {
            $this->redirectToEarnings('earnings_error', $e->getMessage());
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
            'tremendous' => 'Tremendous',
            'manual' => 'Manual'
        ];

        return $labels[$payoutSource] ?? 'selected payout source';
    }

    private function isTremendousOrderPendingStatus(string $status): bool
    {
        $status = strtoupper(trim($status));
        return in_array($status, ['CART', 'PENDING APPROVAL', 'PENDING INTERNAL PAYMENT APPROVAL'], true);
    }

    private function isTremendousOrderFailureStatus(string $status): bool
    {
        $status = strtoupper(trim($status));
        return in_array($status, ['FAILED', 'CANCELED'], true);
    }

    private function mapTremendousPendingPayoutStatus(string $tremendousOrderStatus): string
    {
        $status = strtoupper(trim($tremendousOrderStatus));
        if ($status === 'PENDING INTERNAL PAYMENT APPROVAL') {
            return 'pending_internal_payment_approval';
        }

        return 'pending_approval';
    }

    private function recordFailedPayoutAttempt(
        int $partnerId,
        float $amount,
        string $payoutMethod,
        string $reason,
        array $conversionIds = [],
        string $payoutStatus = 'failed'
    ): void
    {
        try {
            $reason = trim($reason);
            if ($reason === '') {
                $reason = 'Unknown payout failure.';
            }
            $reason = mb_substr($reason, 0, 255);
            if (!in_array($payoutStatus, ['failed', 'canceled'], true)) {
                $payoutStatus = 'failed';
            }
            Database::insert('payouts', [
                'partner_id' => $partnerId,
                'stripe_customer_balance_transaction_id' => null,
                'tremendous_order_id' => null,
                'payout_method' => $payoutMethod,
                'amount' => round($amount, 2),
                'status' => $payoutStatus,
                'failure_reason' => $reason
            ]);

            if (!empty($conversionIds)) {
                $placeholders = implode(',', array_fill(0, count($conversionIds), '?'));
                if ($payoutStatus === 'failed') {
                    Database::query(
                        "UPDATE conversions
                         SET last_payout_failure_reason = ?, last_payout_failed_at = NOW()
                         WHERE id IN ({$placeholders})",
                        array_merge([$reason], array_map(static fn($id): int => (int) $id, $conversionIds))
                    );
                } else {
                    Database::query(
                        "UPDATE conversions
                         SET last_payout_failure_reason = NULL, last_payout_failed_at = NOW()
                         WHERE id IN ({$placeholders})",
                        array_map(static fn($id): int => (int) $id, $conversionIds)
                    );
                }
            }
        } catch (\Exception $logError) {
            error_log('Failed to record failed payout attempt: ' . $logError->getMessage());
        }
    }

    private function createSuccessfulPayout(
        int $partnerId,
        string $payoutMethod,
        float $amount,
        ?string $stripeCustomerBalanceTransactionId,
        ?string $tremendousOrderId
    ): int
    {
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
