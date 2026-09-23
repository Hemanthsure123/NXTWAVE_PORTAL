-- ============================================================
-- NxtWave Portal - Module 1 schema
-- Run this once:  mysql -u root < sql/schema.sql
-- Or paste it into phpMyAdmin > SQL tab.
-- ============================================================

CREATE DATABASE IF NOT EXISTS nxtwave_portal
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nxtwave_portal;

-- ------------------------------------------------------------
-- 1. roles
-- A role is a "kind of user". We keep them in their own table
-- instead of typing 'learner' into every row, so that the list
-- of valid roles lives in exactly one place.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(50)  NOT NULL UNIQUE,   -- used by code:  learner
    label VARCHAR(100) NOT NULL           -- shown to humans: Learner
);

INSERT INTO roles (name, label) VALUES
    ('learner',      'Learner'),
    ('corporate_hr', 'Corporate HR'),
    ('employee',     'NxtWave Employee')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ------------------------------------------------------------
-- 2. users
-- One row per registered person.
-- The learner-only and HR-only columns are NULL for the other
-- role. That is fine for a project this size - see lesson 01
-- for the "why not two tables?" discussion.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    role_id         INT NOT NULL,

    -- common fields
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    phone           VARCHAR(15)  NOT NULL,
    city            VARCHAR(80)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,

    -- learner-only fields
    college         VARCHAR(150) NULL,
    degree          VARCHAR(100) NULL,
    graduation_year INT          NULL,
    tech_stack      VARCHAR(100) NULL,

    -- corporate HR-only fields
    company_name    VARCHAR(150) NULL,
    work_email      VARCHAR(150) NULL,
    company_size    VARCHAR(50)  NULL,
    designation     VARCHAR(100) NULL,

    -- 'pending' accounts can log in only after a NxtWave employee approves
    status          ENUM('pending', 'active', 'rejected') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- ------------------------------------------------------------
-- 3. user_documents
-- The file itself lives on disk (uploads/...).
-- MySQL only stores where it is and what it is.
-- One user -> many documents, so this is a separate table.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    document_type VARCHAR(50)  NOT NULL,   -- profile_photo / resume / govt_id / ...
    file_path     VARCHAR(255) NOT NULL,   -- uploads/resume/6f2a...pdf
    original_name VARCHAR(255) NOT NULL,   -- what the user called it
    file_size     INT          NOT NULL,   -- bytes
    mime_type     VARCHAR(100) NOT NULL,
    uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_documents_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 4. password_resets
-- One row per OTP we send out.
-- We store a HASH of the OTP, never the 6 digits themselves -
-- same reasoning as passwords.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    otp_hash   VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,   -- created_at + 15 minutes
    used_at    DATETIME     NULL,       -- NULL = not used yet
    attempts   INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 5. login_attempts
-- One row per failed login. We count recent rows to decide
-- whether an account is being brute forced.
-- Successful logins clear the account's rows.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_email_time (email, attempted_at)
);
