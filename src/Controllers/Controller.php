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
        $allowed = ['stripe_customer_balance'];
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
}
