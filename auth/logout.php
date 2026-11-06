<?php
/**
 * Secure logout.
 *
 * POST with a valid CSRF token: destroys the session (data, file and cookie)
 * and returns to the login page.
 * GET: shows a confirmation form (prevents logout-CSRF via links/images).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        flash('danger', 'Logout request could not be verified. Please use the Logout button again.');
        redirect('auth/logout.php');
    }

    end_user_session();

    // Start a fresh, empty session only to carry the confirmation message.
    start_secure_session();
    session_regenerate_id(true);
    flash('success', 'You have been signed out successfully.');
    redirect('auth/login.php');
}

$flashMessages = pull_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign out | <?= e(APP_NAME) ?></title>
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
        <div class="card-body p-4 p-md-5 text-center">
            <span class="auth-logo"><i class="bi bi-box-arrow-right"></i></span>
            <h1 class="h4 fw-bold mt-3 mb-2">Sign out?</h1>
            <?php foreach ($flashMessages as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> py-2" role="alert"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>
            <p class="text-muted mb-4">You will need to sign in again to access <?= e(APP_NAME) ?>.</p>
            <form method="post" action="<?= e(url('auth/logout.php')) ?>" class="d-grid gap-2">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-box-arrow-right me-1"></i> Yes, sign me out</button>
                <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
</main>
</body>
</html>
