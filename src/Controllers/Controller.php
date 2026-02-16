<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class Controller {
    
    protected function handlePreflightRequest(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
            http_response_code(200);
            exit;
        }
    }

    protected function view(string $template, array $data = []): void {
        // Always include settings in view data
        if (!isset($data['settings'])) {
            $data['settings'] = $this->getSettings();
        }
        
        extract($data);
        
        require ROOT_PATH . "/src/Views/layouts/header.php";
        require ROOT_PATH . "/src/Views/{$template}.php";
        require ROOT_PATH . "/src/Views/layouts/footer.php";
    }

    protected function getSettings(): array {
        try {
            $stmt = Database::query("SELECT name, value FROM settings");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['name']] = $row['value'];
            }

            return $settings;
        } catch (\Exception $e) {
            // Return empty array if database is not available
            return [];
        }
    }

    protected function json(array $data): void {
        // Add CORS headers for all API responses
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    protected function withNamedLock(string $lockName, callable $callback, int $timeoutSeconds = 10): mixed {
        $lock = Database::query('SELECT GET_LOCK(?, ?) AS lock_acquired', [$lockName, $timeoutSeconds])->fetch();
        $result = $lock['lock_acquired'] ?? null;

        if ($result === null) {
            throw new \RuntimeException('Error while acquiring lock.');
        }

        if ((int) $result !== 1) {
            throw new \RuntimeException('Could not acquire lock (timeout).');
        }

        try {
            return $callback();
        } finally {
            try {
                $release = Database::query('SELECT RELEASE_LOCK(?) AS released', [$lockName])->fetch();
                $released = $release['released'] ?? null;
                if ((int) $released !== 1) {
                    error_log('Lock "' . $lockName . '" may not have been released properly.');
                }
            } catch (\Throwable $e) {
                error_log('Failed to release lock "' . $lockName . '": ' . $e->getMessage());
            }
        }
    }

    protected function getEnabledPayoutMethods(): array {
        $allowed = ['stripe_customer_balance', 'tremendous'];
        $settings = $this->getSettings();
        $raw = $settings['enabled_payout_methods'] ?? '';

        $requested = is_string($raw)
            ? array_filter(array_map('trim', explode(',', $raw)))
            : [];
        $enabled = array_values(array_intersect($allowed, $requested));

        if (empty($enabled)) {
            return ['manual'];
        }

        return array_values(array_unique(array_merge(['manual'], $enabled)));
    }

    protected function createTremendousOrder(
        string $recipientName,
        string $recipientEmail,
        float $amount,
        string $campaignId,
        string $externalId,
        string $message
    ): array {
        $recipientName = trim($recipientName);
        $recipientEmail = trim($recipientEmail);
        if ($recipientName === '' || mb_strlen($recipientName) > 255) {
            throw new \InvalidArgumentException('Invalid recipient name for Tremendous payout.');
        }
        if (
            $recipientEmail === ''
            || strlen($recipientEmail) > 320
            || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)
        ) {
            throw new \InvalidArgumentException('Invalid recipient email for Tremendous payout.');
        }

        $settings = $this->getSettings();
        $apiKey = trim((string) ($settings['tremendous_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Tremendous API key is missing.');
        }

        $campaignId = trim($campaignId);
        if ($campaignId === '') {
            throw new \RuntimeException('Tremendous campaign ID is missing for this program.');
        }

        if (str_starts_with($apiKey, 'TEST_')) {
            $baseUrl = 'https://testflight.tremendous.com/api/v2';
        } elseif (str_starts_with($apiKey, 'PROD_')) {
            $baseUrl = 'https://api.tremendous.com/api/v2';
        } else {
            throw new \RuntimeException('Invalid Tremendous API key format. Expected TEST_ or PROD_ prefix.');
        }

        $payload = [
            'external_id' => $externalId,
            'payment' => [
                'funding_source_id' => 'balance'
            ],
            'reward' => [
                'campaign_id' => $campaignId,
                'value' => [
                    'denomination' => round($amount, 2),
                    'currency_code' => 'USD'
                ],
                'recipient' => [
                    'name' => $recipientName,
                    'email' => $recipientEmail
                ],
                'delivery' => [
                    'method' => 'EMAIL',
                    'meta' => [
                        'message' => $message
                    ]
                ]
            ]
        ];

        $ch = curl_init($baseUrl . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Tremendous payout request failed: ' . $curlError);
        }

        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            throw new \RuntimeException('Tremendous payout failed: invalid JSON response.');
        }

        if ($httpCode === 409) {
            $error = $responseData['errors']['message'] ?? 'Idempotency conflict.';
            throw new \RuntimeException('Tremendous payout failed: ' . $error);
        }

        if (!in_array($httpCode, [200, 201], true)) {
            $error = $responseData['errors']['message'] ?? 'Unknown Tremendous API error.';
            throw new \RuntimeException('Tremendous payout failed: ' . $error);
        }

        $orderId = $responseData['order']['id'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw new \RuntimeException('Tremendous payout failed: missing order ID.');
        }

        $orderStatus = strtoupper(trim((string) ($responseData['order']['status'] ?? '')));
        if ($orderStatus === '') {
            throw new \RuntimeException('Tremendous payout failed: missing order status.');
        }

        $rewardId = null;
        if (!empty($responseData['order']['rewards'][0]['id']) && is_string($responseData['order']['rewards'][0]['id'])) {
            $rewardId = $responseData['order']['rewards'][0]['id'];
        }

        return [
            'order_id' => $orderId,
            'reward_id' => $rewardId,
            'order_status' => $orderStatus
        ];
    }

    protected function buildTremendousExternalId(string $prefix, string $retryToken = '0'): string
    {
        $sanitizedPrefix = preg_replace('/[^A-Za-z0-9_-]/', '-', trim($prefix)) ?: 'numok-payout';
        $hashPrefix = $sanitizedPrefix;

        $normalizedRetryToken = trim($retryToken) !== '' ? trim($retryToken) : '0';
        $hashRetryToken = $normalizedRetryToken;

        if (strlen($normalizedRetryToken) > 16) {
            $normalizedRetryToken = substr($normalizedRetryToken, -16);
        }

        if (strlen($sanitizedPrefix) > 32) {
            $sanitizedPrefix = substr($sanitizedPrefix, 0, 32);
        }

        $hash = substr(sha1($hashPrefix . '|' . $hashRetryToken), 0, 20);
        return $sanitizedPrefix . '-r' . $normalizedRetryToken . '-' . $hash;
    }
}
