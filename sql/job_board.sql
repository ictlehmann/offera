-- ================================================
-- Job Board Migration
-- Add this to the content database (dbs15161271)
-- ================================================

CREATE TABLE IF NOT EXISTS `job_board` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `search_type` ENUM('Festanstellung', 'Werksstudententätigkeit', 'Praxissemester', 'Praktikum') NOT NULL,
  `description` TEXT NOT NULL,
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_search_type` (`search_type`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Job and internship listings posted by users';
