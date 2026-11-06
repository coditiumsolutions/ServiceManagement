<?php
/**
 * Placeholder: Customer Payments (module implemented in a later step).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_login();

$activeMenu = 'customer_payments';
$plannedFeatures = [
    'Record customer payments against requests',
    'Payment methods and receipts',
];

require __DIR__ . '/../../includes/placeholder.php';
