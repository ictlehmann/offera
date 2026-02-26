-- ================================================
-- Drop all old local inventory tables and create
-- the single inventory_requests table for the
-- board approval workflow.
--
-- All actual item data is fetched live from the
-- easyVerein API (/api/v2.0/inventory-object and
-- /api/v2.0/lending).
-- ================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `inventory_transactions`;
DROP TABLE IF EXISTS `inventory_rentals`;
DROP TABLE IF EXISTS `inventory`;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================
-- TABLE: inventory_requests
-- Stores only the board approval workflow state.
-- Item master data comes live from easyVerein.
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_requests` (
  `id`                  INT UNSIGNED        AUTO_INCREMENT PRIMARY KEY,
  `inventory_object_id` VARCHAR(64)         NOT NULL    COMMENT 'easyVerein inventory-object ID',
  `user_id`             INT UNSIGNED        NOT NULL    COMMENT 'Applicant (Antragsteller) local user ID',
  `start_date`          DATE                NOT NULL    COMMENT 'Requested start date of the loan',
  `end_date`            DATE                NOT NULL    COMMENT 'Requested end date of the loan',
  `quantity`            INT UNSIGNED        NOT NULL DEFAULT 1 COMMENT 'Number of units requested',
  `status`              ENUM('pending','approved','rejected','returned','pending_return') NOT NULL DEFAULT 'pending' COMMENT 'Approval workflow status',
  `returned_condition`  ENUM('einwandfrei','leichte_gebrauchsspuren','beschädigt','defekt_verlust') DEFAULT NULL COMMENT 'Item condition at return',
  `return_notes`        TEXT                DEFAULT NULL COMMENT 'Optional remarks at return',
  `returned_at`         DATETIME            DEFAULT NULL COMMENT 'When the return was verified',
  `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the request was created',
  INDEX `idx_ir_inventory_object_id` (`inventory_object_id`),
  INDEX `idx_ir_user_id`             (`user_id`),
  INDEX `idx_ir_status`              (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
