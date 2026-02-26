-- ================================================
-- Migration: Add quantity columns for partial lending
-- (Mengenverwaltung / Teilausleihen)
--
-- Adds total_quantity to inventory_items to store
-- the total stock from EasyVerein data, and
-- rented_quantity to inventory_rentals to track
-- how many units a user has borrowed per rental.
-- ================================================

ALTER TABLE `inventory_items`
  ADD COLUMN `total_quantity` INT NOT NULL DEFAULT 1
  COMMENT 'Total stock from EasyVerein data';

ALTER TABLE `inventory_rentals`
  ADD COLUMN `rented_quantity` INT NOT NULL DEFAULT 1
  COMMENT 'Number of units rented in this transaction (partial lending)';
