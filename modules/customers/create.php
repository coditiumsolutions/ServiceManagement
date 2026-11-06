<?php
/**
 * Placeholder: Add Customer (module implemented in a later step).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_login();

$activeMenu = 'customers';
$pageTitle  = 'Add Customer';
$plannedFeatures = [
    'Customer registration form with validation',
    'Duplicate mobile number check',
    'Auto-generated customer code',
];

require __DIR__ . '/../../includes/placeholder.php';
