-- ================================================
-- Migration: alumni_access_requests
-- Module:    Alumni E-Mail Recovery
-- ================================================
-- Run against the Content Database (dbs15161271)
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- ================================================
-- TABLE: alumni_access_requests
-- Stores public requests from alumni who need help
-- recovering or updating their e-mail address.
-- ================================================
CREATE TABLE IF NOT EXISTS `alumni_access_requests` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name`          VARCHAR(100)  NOT NULL                    COMMENT 'Applicant first name',
  `last_name`           VARCHAR(100)  NOT NULL                    COMMENT 'Applicant last name',
  `new_email`           VARCHAR(255)  NOT NULL                    COMMENT 'New / desired e-mail address',
  `old_email`           VARCHAR(255)  DEFAULT NULL                COMMENT 'Previously used e-mail address (optional)',
  `graduation_semester` VARCHAR(20)   NOT NULL                    COMMENT 'Graduation semester, e.g. WS 2019/20',
  `study_program`       VARCHAR(255)  NOT NULL                    COMMENT 'Field of study / study programme',
  `status`              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT 'Processing status',
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`        TIMESTAMP     NULL DEFAULT NULL           COMMENT 'Timestamp when the request was processed',
  `processed_by`        INT UNSIGNED  DEFAULT NULL                COMMENT 'User ID of the admin who processed the request',
  INDEX `idx_status`       (`status`),
  INDEX `idx_new_email`    (`new_email`),
  INDEX `idx_processed_by` (`processed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
