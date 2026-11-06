<?php
/**
 * Placeholder: Customers (module implemented in a later step).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_login();

$activeMenu = 'customers';
$plannedFeatures = [
    'Customer list with search by name, code and mobile number',
    'Add, edit and deactivate customers',
    'Customer service history',
];

require __DIR__ . '/../../includes/placeholder.php';
