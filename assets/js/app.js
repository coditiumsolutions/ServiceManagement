/**
 * Service Management System - UI behaviour (no dependencies besides Bootstrap).
 */
(function () {
    'use strict';

    var body = document.body;
    var toggleButton = document.getElementById('sidebarToggle');
    var backdrop = document.getElementById('sidebarBackdrop');
    var desktopQuery = window.matchMedia('(min-width: 992px)');
    var STORAGE_KEY = 'sms.sidebarCollapsed';

    /* ---------- Sidebar toggle ---------- */
    function isMobileOpen() { return body.classList.contains('sidebar-open'); }

    function setMobileOpen(open) {
        body.classList.toggle('sidebar-open', open);
        if (backdrop) { backdrop.hidden = !open; }
        if (toggleButton) { toggleButton.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function setDesktopCollapsed(collapsed) {
        body.classList.toggle('sidebar-collapsed', collapsed);
        if (toggleButton) { toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); }
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) { /* storage unavailable */ }
    }

    if (toggleButton) {
        try {
            if (desktopQuery.matches && localStorage.getItem(STORAGE_KEY) === '1') {
                body.classList.add('sidebar-collapsed');
            }
        } catch (e) { /* storage unavailable */ }

        toggleButton.addEventListener('click', function () {
            if (desktopQuery.matches) {
                setDesktopCollapsed(!body.classList.contains('sidebar-collapsed'));
            } else {
                setMobileOpen(!isMobileOpen());
            }
        });
    }

    if (backdrop) { backdrop.addEventListener('click', function () { setMobileOpen(false); }); }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isMobileOpen()) { setMobileOpen(false); }
    });

    desktopQuery.addEventListener('change', function () { setMobileOpen(false); });

    /* ---------- Sidebar group expand / collapse ---------- */
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var groupId = toggle.getAttribute('data-sidebar-toggle');
            var submenu = groupId ? document.getElementById(groupId) : null;
            var group = toggle.closest('.sidebar-group');
            if (!submenu || !group) { return; }

            var willOpen = submenu.hasAttribute('hidden');
            if (willOpen) {
                submenu.removeAttribute('hidden');
                group.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            } else {
                submenu.setAttribute('hidden', '');
                group.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    /* ---------- Monthly summary bars ---------- */
    document.querySelectorAll('.summary-bar-fill[data-percent]').forEach(function (bar) {
        var percent = Math.max(0, Math.min(100, parseInt(bar.getAttribute('data-percent'), 10) || 0));
        window.requestAnimationFrame(function () { bar.style.width = percent + '%'; });
    });

    /* ---------- Auto-dismiss flash alerts ---------- */
    document.querySelectorAll('[data-auto-dismiss]').forEach(function (alertElement) {
        window.setTimeout(function () {
            if (window.bootstrap && window.bootstrap.Alert) {
                window.bootstrap.Alert.getOrCreateInstance(alertElement).close();
            }
        }, 6000);
    });

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.querySelector(button.getAttribute('data-toggle-password'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var icon = button.querySelector('i');
            if (icon) { icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye'; }
        });
    });

    /* ---------- Prevent double submit on login ---------- */
    var loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function () {
            var button = document.getElementById('loginButton');
            if (button) {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Signing in...';
            }
        });
    }
})();
