<?php

declare(strict_types=1);

use Numok\Database\Database;

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/config/config.php';

try {
    $stmt = Database::query(
        "UPDATE conversions c
         JOIN partner_programs pp ON c.partner_program_id = pp.id
         JOIN programs p ON pp.program_id = p.id
         SET c.status = 'payable',
             c.updated_at = CURRENT_TIMESTAMP
         WHERE c.status = 'pending'
           AND c.created_at <= DATE_SUB(NOW(), INTERVAL COALESCE(p.reward_days, 0) DAY)"
    );

    $updatedRows = (int) $stmt->rowCount();
    fwrite(STDOUT, '[promote-conversions] Updated rows: ' . $updatedRows . PHP_EOL);
    exit(0);
} catch (\Throwable $e) {
    error_log('Failed to promote pending conversions: ' . $e->getMessage());
    fwrite(STDERR, '[promote-conversions] Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
