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

    protected function promoteMaturePendingConversions(): void {
        try {
            Database::query(
                "UPDATE conversions c
                 JOIN partner_programs pp ON c.partner_program_id = pp.id
                 JOIN programs p ON pp.program_id = p.id
                 SET c.status = 'payable',
                     c.updated_at = CURRENT_TIMESTAMP
                 WHERE c.status = 'pending'
                   AND p.reward_days > 0
                   AND c.created_at <= DATE_SUB(NOW(), INTERVAL p.reward_days DAY)"
            );
        } catch (\Exception $e) {
            error_log('Failed to auto-promote pending conversions: ' . $e->getMessage());
        }
    }

    protected function getEnabledPayoutMethods(): array {
        $allowed = ['stripe_customer_balance'];
        $settings = $this->getSettings();
        $raw = $settings['enabled_payout_methods'] ?? '';

        if (!is_string($raw) || trim($raw) === '') {
            return ['manual', 'stripe_customer_balance'];
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)));
        $enabled = array_values(array_intersect($allowed, $requested));

        if (empty($enabled)) {
            return ['manual'];
        }

        return array_values(array_unique(array_merge(['manual'], $enabled)));
    }
}
