<?php

declare(strict_types=1);

use Numok\Database\Database;

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/config/config.php';

function syncLog(string $message): void
{
    fwrite(STDOUT, '[sync-tremendous-payouts] ' . $message . PHP_EOL);
}

class SkipSyncException extends RuntimeException {}

/**
 * Syncs Tremendous payouts that are currently in pending statuses.
 * - APPROVED / EXECUTED => payout paid + linked conversions paid
 * - FAILED => payout failed + linked conversions released back to payable
 * - CANCELED => payout canceled + linked conversions released back to payable
 */

function getTremendousApiConfig(): array
{
    $settingsRows = Database::query(
        "SELECT name, value FROM settings WHERE name IN ('tremendous_api_key')"
    )->fetchAll();

    $settings = [];
    foreach ($settingsRows as $row) {
        $settings[(string) $row['name']] = (string) $row['value'];
    }

    $apiKey = trim((string) ($settings['tremendous_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new SkipSyncException('Tremendous API key not configured. Skipping sync.');
    }

    if (str_starts_with($apiKey, 'TEST_')) {
        $baseUrl = 'https://testflight.tremendous.com/api/v2';
    } elseif (str_starts_with($apiKey, 'PROD_')) {
        $baseUrl = 'https://api.tremendous.com/api/v2';
    } else {
        throw new RuntimeException('Invalid Tremendous API key format. Expected TEST_ or PROD_ prefix.');
    }

    return [
        'api_key' => $apiKey,
        'base_url' => $baseUrl,
    ];
}

function getExpectedTremendousWebhookUrl(): string
{
    global $config;
    $baseUrl = trim((string) ($config['app']['url'] ?? ''));
    if ($baseUrl === '') {
        throw new RuntimeException('APP_URL is missing.');
    }

    return rtrim($baseUrl, '/') . '/webhook/tremendous';
}

function upsertSetting(string $name, string $value): void
{
    Database::query(
        "INSERT INTO settings (name, value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$name, $value]
    );
}

function requestTremendous(
    string $method,
    string $url,
    string $apiKey,
    ?array $jsonBody = null,
    array $expectedStatusCodes = [200]
): array {
    $method = strtoupper(trim($method));
    $maxAttempts = 5;
    $attempt = 0;
    $backoffSeconds = 1;

    while (true) {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Tremendous API request failed: ' . $curlError);
        }

        $data = [];
        if ($response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (in_array($httpCode, $expectedStatusCodes, true)) {
            return ['status' => $httpCode, 'data' => $data];
        }

        if ($httpCode === 429 && $attempt < $maxAttempts) {
            $jitterMs = random_int(0, 250);
            usleep(($backoffSeconds * 1000000) + ($jitterMs * 1000));
            $attempt++;
            $backoffSeconds = min($backoffSeconds * 2, 16);
            continue;
        }

        $error = $data['errors']['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Tremendous API request failed: ' . $error);
    }
}

function ensureTremendousWebhookConfigured(string $baseUrl, string $apiKey, string $expectedUrl): void
{
    syncLog('Checking Tremendous webhook configuration for: ' . $expectedUrl);
    $listResponse = requestTremendous('GET', $baseUrl . '/webhooks', $apiKey, null, [200]);
    $webhooks = is_array($listResponse['data']['webhooks'] ?? null) ? $listResponse['data']['webhooks'] : [];
    $current = $webhooks[0] ?? null;
    if (!is_array($current)) {
        $current = null;
    }

    $currentId = trim((string) (($current['id'] ?? '')));
    $currentUrl = rtrim(trim((string) (($current['url'] ?? ''))), '/');
    $normalizedExpected = rtrim($expectedUrl, '/');

    if ($current !== null) {
        syncLog('Found existing Tremendous webhook: id=' . ($currentId !== '' ? $currentId : 'unknown') . ', url=' . ($currentUrl !== '' ? $currentUrl : '(empty)'));
    } else {
        syncLog('No existing Tremendous webhook found.');
    }

    if ($current !== null && $currentUrl === $normalizedExpected) {
        $currentPrivateKey = trim((string) (($current['private_key'] ?? '')));
        syncLog('Webhook URL is already valid.');
        if ($currentPrivateKey !== '') {
            upsertSetting('tremendous_webhook_private_key', $currentPrivateKey);
            syncLog('Stored existing webhook private key in settings.');
            return;
        }
        syncLog('Existing webhook response did not include private_key. Recreating webhook to refresh key.');
    }

    if ($currentId !== '') {
        syncLog('Deleting old webhook: id=' . $currentId);
        requestTremendous('DELETE', $baseUrl . '/webhooks/' . rawurlencode($currentId), $apiKey, null, [204, 404]);
        syncLog('Old webhook delete request completed.');
    }

    syncLog('Creating webhook with expected URL: ' . $normalizedExpected);
    $createResponse = requestTremendous(
        'POST',
        $baseUrl . '/webhooks',
        $apiKey,
        ['url' => $normalizedExpected],
        [200]
    );
    $webhook = is_array($createResponse['data']['webhook'] ?? null) ? $createResponse['data']['webhook'] : [];
    $newWebhookId = trim((string) ($webhook['id'] ?? ''));
    if ($newWebhookId !== '') {
        syncLog('Created/updated webhook: id=' . $newWebhookId);
    } else {
        syncLog('Created/updated webhook (id not returned).');
    }
    $privateKey = trim((string) ($webhook['private_key'] ?? ''));
    if ($privateKey === '') {
        throw new RuntimeException('Tremendous webhook creation failed: missing private_key in response.');
    }
    upsertSetting('tremendous_webhook_private_key', $privateKey);
    syncLog('Stored new webhook private key in settings.');
}

function requestTremendousOrdersPage(string $baseUrl, string $apiKey, int $limit, int $offset): array
{
    $url = $baseUrl . '/orders?' . http_build_query([
        'limit' => $limit,
        'offset' => $offset,
    ]);

    $maxAttempts = 5;
    $attempt = 0;
    $backoffSeconds = 1;

    while (true) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Tremendous list orders request failed: ' . $curlError);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Tremendous list orders failed: invalid JSON response.');
        }

        if ($httpCode === 200) {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            $totalCount = (int) ($data['total_count'] ?? 0);

            return [
                'orders' => $orders,
                'total_count' => $totalCount,
            ];
        }

        if ($httpCode === 429 && $attempt < $maxAttempts) {
            $jitterMs = random_int(0, 250);
            usleep(($backoffSeconds * 1000000) + ($jitterMs * 1000));
            $attempt++;
            $backoffSeconds = min($backoffSeconds * 2, 16);
            continue;
        }

        $error = $data['errors']['message'] ?? 'Unknown Tremendous API error.';
        throw new RuntimeException('Tremendous list orders failed: ' . $error);
    }
}

function normalizeOrderStatus(string $status): string
{
    return strtoupper(trim($status));
}

function isPendingStatus(string $status): bool
{
    return in_array($status, ['CART', 'PENDING APPROVAL', 'PENDING INTERNAL PAYMENT APPROVAL'], true);
}

function isFailureStatus(string $status): bool
{
    return in_array($status, ['FAILED', 'CANCELED'], true);
}

try {
    $cfg = getTremendousApiConfig();
    $apiKey = $cfg['api_key'];
    $baseUrl = $cfg['base_url'];
    try {
        $expectedWebhookUrl = getExpectedTremendousWebhookUrl();
        ensureTremendousWebhookConfigured($baseUrl, $apiKey, $expectedWebhookUrl);
    } catch (Throwable $webhookError) {
        syncLog('Warning: webhook ensure failed, continuing payout reconciliation. Error: ' . $webhookError->getMessage());
    }

    // Recover abandoned processing payouts where provider call never produced an order id.
    $staleProcessing = Database::query(
        "SELECT id
         FROM payouts
         WHERE payout_method = 'tremendous'
           AND status = 'processing'
           AND (tremendous_order_id IS NULL OR tremendous_order_id = '')
           AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY updated_at ASC
         LIMIT 250"
    )->fetchAll();

    foreach ($staleProcessing as $staleRow) {
        $stalePayoutId = (int) ($staleRow['id'] ?? 0);
        if ($stalePayoutId <= 0) {
            continue;
        }

        $failureReason = 'Tremendous payout failed: no order ID was recorded.';
        Database::transaction(function () use ($stalePayoutId, $failureReason): void {
            Database::update(
                'payouts',
                [
                    'status' => 'failed',
                    'failure_reason' => $failureReason,
                ],
                'id = ?',
                [$stalePayoutId]
            );

            Database::query(
                "UPDATE conversions
                 SET payout_id = NULL,
                     last_payout_failure_reason = ?,
                     last_payout_failed_at = NOW()
                 WHERE payout_id = ?
                   AND status = 'payable'",
                [$failureReason, $stalePayoutId]
            );
        });
    }

    if (!empty($staleProcessing)) {
        syncLog('Released stale processing payouts without order id: ' . count($staleProcessing));
    }

    $processingPayouts = Database::query(
        "SELECT id, partner_id, tremendous_order_id
         FROM payouts
         WHERE payout_method = 'tremendous'
           AND status IN ('processing', 'pending_approval', 'pending_internal_payment_approval')
           AND tremendous_order_id IS NOT NULL
           AND tremendous_order_id <> ''
         ORDER BY updated_at ASC
         LIMIT 250"
    )->fetchAll();

    if (empty($processingPayouts)) {
        syncLog('No processing payouts.');
        exit(0);
    }

    $neededOrderIds = [];
    foreach ($processingPayouts as $row) {
        $orderId = trim((string) ($row['tremendous_order_id'] ?? ''));
        if ($orderId !== '') {
            $neededOrderIds[$orderId] = true;
        }
    }

    $orderStatusById = [];
    $limit = 200;
    $offset = 0;
    while (!empty($neededOrderIds)) {
        $page = requestTremendousOrdersPage($baseUrl, $apiKey, $limit, $offset);
        $orders = $page['orders'];
        if (empty($orders)) {
            break;
        }

        foreach ($orders as $order) {
            $id = trim((string) ($order['id'] ?? ''));
            if ($id === '' || !isset($neededOrderIds[$id])) {
                continue;
            }

            $orderStatusById[$id] = normalizeOrderStatus((string) ($order['status'] ?? ''));
            unset($neededOrderIds[$id]);
        }

        $offset += $limit;
    }

    $updatedPaid = 0;
    $updatedFailed = 0;
    $stillPending = 0;
    $notFound = 0;

    foreach ($processingPayouts as $payout) {
        $payoutId = (int) $payout['id'];
        $orderId = trim((string) ($payout['tremendous_order_id'] ?? ''));
        if ($orderId === '' || !isset($orderStatusById[$orderId])) {
            $notFound++;
            continue;
        }

        $status = $orderStatusById[$orderId];
        if (in_array($status, ['APPROVED', 'EXECUTED'], true)) {
            Database::transaction(function () use ($payoutId): void {
                Database::update(
                    'payouts',
                    [
                        'status' => 'paid',
                        'failure_reason' => null,
                    ],
                    'id = ?',
                    [$payoutId]
                );

                Database::query(
                    "UPDATE conversions
                     SET status = 'paid',
                         last_payout_failure_reason = NULL,
                         last_payout_failed_at = NULL
                     WHERE payout_id = ?
                       AND status = 'payable'",
                    [$payoutId]
                );
            });
            $updatedPaid++;
            continue;
        }

        if (isFailureStatus($status)) {
            $isCanceled = $status === 'CANCELED';
            $terminalStatus = $isCanceled ? 'canceled' : 'failed';
            $failureReason = 'Tremendous order failed with status: ' . $status;
            Database::transaction(function () use ($payoutId, $failureReason, $terminalStatus, $isCanceled): void {
                Database::update(
                    'payouts',
                    [
                        'status' => $terminalStatus,
                        'failure_reason' => $failureReason,
                    ],
                    'id = ?',
                    [$payoutId]
                );

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
            });
            $updatedFailed++;
            continue;
        }

        if (isPendingStatus($status)) {
            $pendingStatus = $status === 'PENDING INTERNAL PAYMENT APPROVAL'
                ? 'pending_internal_payment_approval'
                : 'pending_approval';
            Database::update(
                'payouts',
                [
                    'status' => $pendingStatus,
                    'failure_reason' => null,
                ],
                "id = ? AND status IN ('processing', 'pending_approval', 'pending_internal_payment_approval')",
                [$payoutId]
            );
            $stillPending++;
            continue;
        }
    }

    syncLog(
        'paid=' . $updatedPaid
        . ' failed=' . $updatedFailed
        . ' pending=' . $stillPending
        . ' unresolved=' . $notFound
    );
    exit(0);
} catch (SkipSyncException $e) {
    syncLog($e->getMessage());
    exit(0);
} catch (Throwable $e) {
    error_log('Failed to sync Tremendous payouts: ' . $e->getMessage());
    fwrite(STDERR, '[sync-tremendous-payouts] Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
