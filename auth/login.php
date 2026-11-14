<?php
/**
 * Login page: username + password, CSRF protected, throttled.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$errorMessage    = '';
$enteredUsername = '';

if (is_post_request()) {
    $enteredUsername = post_string('username', 50);
    $password        = post_raw('password', 255);
    $lockSeconds     = login_lockout_remaining();

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session has expired. Please try again.';
        regenerate_csrf_token();
    } elseif ($lockSeconds > 0) {
        $errorMessage = sprintf(
            'Too many failed attempts. Please wait %d minute(s) before trying again.',
            (int) ceil($lockSeconds / 60)
        );
    } elseif ($enteredUsername === '' || $password === '') {
        $errorMessage = 'Please enter both username and password.';
    } elseif (!is_valid_username($enteredUsername)) {
        register_failed_login();
        $errorMessage = 'Invalid username or password.';
    } else {
        $user = attempt_login($enteredUsername, $password);

        if ($user === null) {
            register_failed_login();
            error_log(sprintf('[ServiceManagement] Failed login for "%s" from %s', $enteredUsername, client_ip()));
            $errorMessage = 'Invalid username or password.';
        } else {
            $intendedUrl = (string) ($_SESSION['intended_url'] ?? '');
            unset($_SESSION['intended_url']);

            login_user($user);
            flash('success', 'Welcome back, ' . $user['full_name'] . '!');

            redirect(is_safe_local_url($intendedUrl) ? $intendedUrl : 'dashboard/index.php');
        }
    }
}

$flashMessages = pull_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-brand" aria-label="<?= e(APP_NAME) ?> branding">
        <div class="auth-brand-media" role="img" aria-label="Home services professionals at work"></div>
        <div class="auth-brand-overlay"></div>
        <div class="auth-brand-content">
            <div class="auth-brand-badge auth-reveal" style="--delay: .05s">
                <i class="bi bi-house-heart-fill" aria-hidden="true"></i>
                Home Services Platform
            </div>
            <h1 class="auth-brand-title auth-reveal" style="--delay: .12s"><?= e(APP_NAME) ?></h1>
            <p class="auth-brand-tagline auth-reveal" style="--delay: .2s">Schedule jobs. Manage professionals. Delight every customer.</p>
            <ul class="auth-highlights auth-reveal" style="--delay: .3s">
                <li>
                    <span class="auth-highlight-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
                    <span>Service Requests &amp; Scheduling</span>
                </li>
                <li>
                    <span class="auth-highlight-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
                    <span>Professionals &amp; Assignments</span>
                </li>
                <li>
                    <span class="auth-highlight-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                    <span>Payroll, Commissions &amp; Payments</span>
                </li>
            </ul>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card auth-reveal" style="--delay: .15s">
            <div class="auth-card-header text-center">
                <span class="auth-logo" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>
                <h2 class="auth-welcome">Welcome Back!</h2>
                <p class="auth-subtitle">Sign in to access your services dashboard.</p>
            </div>

            <?php foreach ($flashMessages as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> auth-alert py-2" role="alert"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 auth-alert py-2" role="alert" id="loginError">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span><?= e($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('auth/login.php')) ?>" id="loginForm" novalidate autocomplete="on">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label auth-label">Username</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= e($enteredUsername) ?>" maxlength="50" required autofocus
                               autocomplete="username" pattern="[A-Za-z0-9._@\-]{3,50}"
                               placeholder="Enter your username">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label auth-label">Password</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               maxlength="255" required autocomplete="current-password"
                               placeholder="Enter your password">
                        <button class="btn btn-outline-secondary auth-password-toggle" type="button"
                                data-toggle-password="#password" aria-label="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="auth-meta d-flex align-items-center justify-content-between mb-4">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="rememberMe" value="1">
                        <label class="form-check-label" for="rememberMe">Remember Me</label>
                    </div>
                    <a class="auth-forgot" href="#" title="Contact your system administrator to reset your password">Forgot Password?</a>
                </div>

                <button type="submit" class="btn auth-login-btn w-100 py-2 fw-semibold" id="loginButton">
                    <span>Login</span>
                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                </button>
            </form>

            <p class="auth-card-foot mb-0">&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></p>
        </div>
    </section>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
