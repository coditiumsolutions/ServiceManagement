<?php
/**
 * Entry point: send signed-in users to the dashboard, everyone else to login.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

redirect(is_logged_in() ? 'dashboard/index.php' : 'auth/login.php');
