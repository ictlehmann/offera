-- ================================================
-- Drop unused local inventory tables
-- ================================================
-- These tables are no longer needed because EasyVerein
-- is the single source of truth for all inventory data.
-- Run this script ONCE on each database instance after
-- deploying the EasyVerein-backed Inventory model.
--
-- The dependent table (inventory_transactions) is dropped
-- first to satisfy any foreign key constraints referencing
-- the parent (inventory) table.
-- ================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `inventory_transactions`;
DROP TABLE IF EXISTS `inventory`;

SET FOREIGN_KEY_CHECKS = 1;
