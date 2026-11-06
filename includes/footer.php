<?php
/**
 * Page footer: closes the main content area and loads JavaScript.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    </div><!-- /.app-content -->

    <footer class="app-footer">
        <span>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></span>
        <span class="text-muted">All times in <?= e(APP_TIMEZONE) ?></span>
    </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
