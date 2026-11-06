-- =====================================================================
-- Service Management System - Step 2
-- Table: admin_users (application login accounts)
-- Run AFTER schema.sql. Safe to run more than once.
-- Passwords are stored ONLY as password_hash() values, never plain text.
-- =====================================================================

USE ServiceManagementDB;

CREATE TABLE IF NOT EXISTS admin_users (
    uid             INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    email           VARCHAR(150) NULL,
    role            VARCHAR(30)  NOT NULL DEFAULT 'admin',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_admin_users_username UNIQUE (username),
    INDEX IX_admin_users_email (email),
    INDEX IX_admin_users_role (role),
    INDEX IX_admin_users_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
