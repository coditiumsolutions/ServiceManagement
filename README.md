# Service Management System — Step 2 (Application Foundation)

Core PHP 8 + MySQL/MariaDB (PDO) + Bootstrap 5.3. No Composer, no frameworks.
Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 are loaded from the jsDelivr CDN (with SRI hashes).

This step provides: database connection, secure admin login/logout, session
protection, the shared admin layout (header, top navbar, collapsible sidebar,
footer), a working dashboard that reads the existing 14 business tables, and
placeholder pages for every sidebar module. Module CRUD screens come in later steps.

---

## 1. Files

```
ServiceManagement/
├── .htaccess                      Apache hardening (blocks config/includes/sql, create_admin.php, *.sql, dotfiles; security headers)
├── index.php                      Entry point → dashboard (logged in) or login page
├── schema.sql                     Step 1 schema — 14 business tables (unchanged)
├── README.md
├── config/
│   ├── .htaccess                  Deny all
│   ├── app.php                    App constants (name, timezone, currency, session timeouts, BASE_URL)
│   └── database.php               PDO connection (db()), env overrides, safe error page
├── sql/
│   ├── .htaccess                  Deny all
│   └── admin_users.sql            Creates the admin_users table (login accounts)
├── includes/
│   ├── .htaccess                  Deny all
│   ├── functions.php              Helpers: e(), url(), redirect(), CSRF, flash messages, formatting, sidebar menu
│   ├── auth.php                   Secure session, login/logout, require_login(), login throttling
│   ├── header.php                 <head>, CSS, opens layout
│   ├── navbar.php                 Top bar: sidebar toggle, brand, date, user menu (logout)
│   ├── sidebar.php                Grouped sidebar menu with active-item highlighting
│   ├── footer.php                 Closes layout, JS
│   └── placeholder.php            Shared "coming in a later step" page body
├── auth/
│   ├── login.php                  Login form (CSRF, throttling, generic errors)
│   ├── logout.php                 Logout (POST + CSRF; GET shows a confirm page)
│   └── create_admin.php           CLI-only one-time script to create the first admin
├── dashboard/
│   └── index.php                  Summary cards, recent requests/assignments, 6-month summary, quick actions
├── modules/                       Placeholder pages (login-protected) for every sidebar item
│   ├── customers/{index,create}.php
│   ├── service_categories/index.php
│   ├── service_types/index.php
│   ├── professionals/{index,create}.php
│   ├── service_requests/{index,create,in_progress,completed}.php
│   ├── service_assignments/index.php
│   ├── salary/{index,withdrawals}.php
│   ├── payroll/index.php
│   ├── commissions/{index,payments,ledger}.php
│   ├── payments/index.php
│   ├── expenses/{index,reports}.php
│   └── users/{index,change_password}.php
└── assets/
    ├── css/style.css              Theme (navy sidebar, cards, login page)
    ├── js/app.js                  Sidebar toggle/collapse, alerts, password toggle, summary bars
    └── images/.gitkeep
```

---

## 2. Database configuration

Edit `config/database.php`. Defaults match a stock XAMPP install:

| Setting  | Default               | Environment override |
|----------|-----------------------|----------------------|
| Host     | `127.0.0.1`           | `DB_HOST`            |
| Port     | `3306`                | `DB_PORT`            |
| Database | `ServiceManagementDB` | `DB_NAME`            |
| User     | `root`                | `DB_USER`            |
| Password | *(empty)*             | `DB_PASS`            |

Connection uses `utf8mb4`, exceptions, real prepared statements, and the MySQL
session time zone is aligned with the app time zone (`Asia/Karachi`, set in
`config/app.php`). If the connection fails, users see a generic
"Service unavailable" page; the real error goes to the PHP error log only.

Other settings in `config/app.php`:

- `APP_BASE_URL` (env) — URL path the app is served from. Auto-detected; falls back to `/ServiceManagement`.
- `APP_DEBUG` (env, `1`/`0`) — show PHP errors. Keep `0` in production.
- Session idle timeout 30 min, absolute lifetime 12 h, 5 failed logins → 5 min lock.

> For production, create a dedicated MySQL user with only the privileges the app
> needs instead of using `root`.

---

## 3. Running on XAMPP (Windows)

1. Copy the `ServiceManagement` folder to `C:\xampp\htdocs\ServiceManagement`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Import the database (phpMyAdmin → Import, or the command line):
   ```bat
   cd C:\xampp\mysql\bin
   mysql -u root < C:\xampp\htdocs\ServiceManagement\schema.sql
   mysql -u root < C:\xampp\htdocs\ServiceManagement\sql\admin_users.sql
   ```
   `schema.sql` creates `ServiceManagementDB` and the 14 business tables;
   `admin_users.sql` adds the login table (it is safe to re-run).
4. **mod_rewrite / .htaccess:** XAMPP enables `mod_rewrite` and `mod_headers`
   and allows `.htaccess` by default (`AllowOverride All` in
   `C:\xampp\apache\conf\httpd.conf`). If you get a *500 Internal Server Error*,
   make sure these lines are not commented out in `httpd.conf` and restart Apache:
   ```
   LoadModule rewrite_module modules/mod_rewrite.so
   LoadModule headers_module modules/mod_headers.so
   ```
   The rules are wrapped in `<IfModule>`, so the app still works without them;
   you just lose the extra web-server-level protection.
5. Create the first admin (next section).
6. Open the app:
   - Login: <http://localhost/ServiceManagement/auth/login.php>
   - Dashboard: <http://localhost/ServiceManagement/dashboard/index.php>

### Running without XAMPP (PHP built-in server, Linux/macOS)

```bash
# from the folder that CONTAINS ServiceManagement/
php -S 127.0.0.1:8080 -t .
# open http://127.0.0.1:8080/ServiceManagement/
```
Note: the built-in server ignores `.htaccess`. PHP-level guards still block
direct access to `config/` and `includes/` files and the web use of
`create_admin.php`, but use Apache for anything beyond local testing.

---

## 4. Creating the first admin user

`auth/create_admin.php` is a **command-line only** script. Over HTTP it always
returns *403 Forbidden*. It refuses to run if any admin user already exists.

```bat
cd C:\xampp\htdocs\ServiceManagement
C:\xampp\php\php.exe auth\create_admin.php --full-name="System Administrator" --username=admin --email=admin@example.com
```
(Linux/macOS: `php auth/create_admin.php --full-name="System Administrator" --username=admin`)

- You are prompted for the password (hidden input on Linux/macOS terminals;
  you can also pipe it in: `printf '%s\n' 'MyStr0ngPass1' | php auth/create_admin.php --username=admin`).
- Password rules: 10–72 characters, at least one letter and one digit.
- It is stored with `password_hash()` (bcrypt); the plain password is never stored.
- Running it again exits with: *"An administrator account already exists…"* (exit code 2).

**After creating the admin, delete `auth/create_admin.php`** (or move it out of
the web root). It is already blocked from the web, but removing it is the safest option.

---

## 5. Testing login, logout and session protection

1. **Session protection** — while logged out, open
   <http://localhost/ServiceManagement/dashboard/index.php> or any module page,
   e.g. `/modules/customers/index.php`. You must be redirected to the login page.
   After you sign in you are returned to the page you originally asked for.
2. **Bad login** — enter a wrong password. You see *"Invalid username or password."*
   (the same message for an unknown username). After 5 failures the form is
   locked for 5 minutes.
3. **Good login** — sign in with the admin you created. You land on the dashboard
   with a "Welcome back" message and your name in the top-right menu.
4. **Navigation** — every sidebar link opens (placeholders show "Coming in a
   later step"); the current item is highlighted. On small screens the ☰ button
   opens the sidebar; on desktop it collapses/expands it (remembered by the browser).
5. **Logout** — use *Logout* in the sidebar or the user menu. You return to the
   login page with *"You have been signed out successfully."* Pressing *Back* or opening the
   dashboard URL again sends you back to login.
6. **Timeout** — after 30 minutes without activity the session expires and you
   are asked to sign in again.
7. **create_admin over the web** — open
   <http://localhost/ServiceManagement/auth/create_admin.php>; you must get *403 Forbidden*.

---

## 6. Security notes

- All SQL uses PDO prepared statements; all output is escaped with `e()`.
- CSRF tokens on the login and logout forms (and for all future forms).
- Session: HttpOnly, SameSite=Lax, strict mode, Secure on HTTPS, ID regenerated on login,
  idle and absolute timeouts, inactive accounts are logged out on their next request.
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`; authenticated pages are sent `Cache-Control: no-store`.
- Login throttling is per browser session in this step; a database-backed
  (per-IP/per-username) limiter is recommended in a later step.
