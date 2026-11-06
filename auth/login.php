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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-wrapper">
    <div class="auth-card card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <span class="auth-logo"><i class="bi bi-gear-wide-connected"></i></span>
                <h1 class="h4 fw-bold mt-3 mb-1"><?= e(APP_NAME) ?></h1>
                <p class="text-muted mb-0">Sign in to continue to your dashboard</p>
            </div>

            <?php foreach ($flashMessages as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> py-2" role="alert"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert" id="loginError">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= e($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('auth/login.php')) ?>" id="loginForm" novalidate autocomplete="on">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= e($enteredUsername) ?>" maxlength="50" required autofocus
                               autocomplete="username" pattern="[A-Za-z0-9._@\-]{3,50}">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               maxlength="255" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password"
                                aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="loginButton">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
                </button>
            </form>
        </div>
    </div>
    <p class="auth-footer text-center mt-4 mb-0">&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></p>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
