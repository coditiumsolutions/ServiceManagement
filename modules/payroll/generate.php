<?php
/**
 * Payroll generation is no longer used — totals sync automatically on Salary Payment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_login();

flash('info', 'Salary totals are built automatically. Open Salary Payment and record installment payments there.');
redirect('modules/payroll/index.php');
