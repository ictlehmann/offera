-- ================================================
-- Shop Database Setup Script (dbs15381315)
-- ================================================
-- Host:   db5019914573.hosting-data.io
-- User:   dbu2343609
-- This database handles: Shop products, variants,
-- images, orders and order items
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: shop_products
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_products` (
    `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(255)   NOT NULL,
    `description`    TEXT,
    `hints`          TEXT           DEFAULT NULL COMMENT 'Hinweise zum Produkt (z.B. Pflegehinweise, Besonderheiten)',
    `base_price`     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `image_path`     VARCHAR(500)   DEFAULT NULL,
    `active`         TINYINT(1)     NOT NULL DEFAULT 1,
    `is_bulk_order`  TINYINT(1)     NOT NULL DEFAULT 1 COMMENT 'Sammelbestellung aktiv',
    `bulk_end_date`  DATETIME       DEFAULT NULL COMMENT 'Ende der Sammelbestellfrist',
    `bulk_min_goal`  INT            DEFAULT NULL COMMENT 'Mindestanzahl an Vorbestellungen für Produktion',
    `sku`            VARCHAR(100)   DEFAULT NULL COMMENT 'Lagerbestandseinheit (SKU)',
    `category`       ENUM('Kleidung','Accessoires','Bürobedarf','Sonstiges') DEFAULT NULL COMMENT 'Produktkategorie',
    `target_group`   ENUM('Herren','Damen','Unisex','Keine') NOT NULL DEFAULT 'Keine' COMMENT 'Zielgruppe / Geschlecht',
    `pickup_location` VARCHAR(255)  DEFAULT NULL COMMENT 'Abholort und Zeitpunkt',
    `variants`       VARCHAR(255)   DEFAULT NULL COMMENT 'Kommagetrennte Varianten (z.B. S, M, L, XL)',
    `shipping_cost`  DECIMAL(10,2)  NOT NULL DEFAULT 0.00 COMMENT 'Versandkosten für dieses Produkt',
    `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`active`),
    KEY `idx_category` (`category`),
    KEY `idx_target_group` (`target_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Shop-Produkte mit Varianten, Kategorien und Zielgruppen';

-- ================================================
-- TABLE: shop_product_images
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_product_images` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `image_path` VARCHAR(500) NOT NULL,
    `sort_order` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `fk_img_product` (`product_id`),
    CONSTRAINT `fk_img_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Produktbilder mit Sortierreihenfolge';

-- ================================================
-- TABLE: shop_variants
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_variants` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`     INT UNSIGNED NOT NULL,
    `type`           VARCHAR(100) NOT NULL COMMENT 'z.B. Größe, Farbe',
    `value`          VARCHAR(100) NOT NULL COMMENT 'z.B. XL, Blau',
    `stock_quantity` INT          DEFAULT NULL COMMENT 'NULL = kein Lagerbestand verwaltet (Sammelbestellung)',
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_variant_product` (`product_id`),
    CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Produktvarianten (z.B. Größe, Farbe) mit Lagerbestand';

-- ================================================
-- TABLE: shop_orders
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_orders` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED  NOT NULL,
    `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method`   ENUM('paypal','bank_transfer') NOT NULL DEFAULT 'paypal',
    `payment_status`   VARCHAR(50)   NOT NULL DEFAULT 'pending'  COMMENT 'pending, paid, failed',
    `shipping_status`  VARCHAR(50)   NOT NULL DEFAULT 'pending'  COMMENT 'pending, shipped, delivered',
    `shipping_method`  VARCHAR(50)   DEFAULT NULL                COMMENT 'pickup oder mail',
    `shipping_cost`    DECIMAL(10,2) NOT NULL DEFAULT 0.00       COMMENT 'Versandkosten',
    `shipping_address` TEXT          DEFAULT NULL                COMMENT 'Lieferadresse für Postversand',
    `delivery_status`  ENUM('open','delivered') NOT NULL DEFAULT 'open' COMMENT 'Lieferstatus',
    `selected_variant` VARCHAR(255)  DEFAULT NULL                COMMENT 'Gewählte Variante (z.B. Größe) aus dem Produktfeld',
    `delivery_method`  VARCHAR(50)   DEFAULT NULL                COMMENT 'Gewählte Liefermethode: Versand oder Abholung',
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_user` (`user_id`),
    KEY `idx_payment_status` (`payment_status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Shop-Bestellungen mit Zahlungs- und Versandstatus';

-- ================================================
-- TABLE: shop_order_items
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_order_items` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`          INT UNSIGNED  NOT NULL,
    `product_id`        INT UNSIGNED  NOT NULL,
    `variant_id`        INT UNSIGNED  DEFAULT NULL,
    `quantity`          INT           NOT NULL DEFAULT 1,
    `price_at_purchase` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_item_order`   (`order_id`),
    KEY `fk_item_product` (`product_id`),
    KEY `fk_item_variant` (`variant_id`),
    CONSTRAINT `fk_item_order`   FOREIGN KEY (`order_id`)   REFERENCES `shop_orders`   (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`),
    CONSTRAINT `fk_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `shop_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Bestellpositionen mit Preis zum Kaufzeitpunkt';

-- ================================================
-- TABLE: shop_restock_notifications
-- ================================================
CREATE TABLE IF NOT EXISTS `shop_restock_notifications` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `product_id`    INT UNSIGNED NOT NULL,
    `variant_type`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Varianten-Typ (z.B. Größe)',
    `variant_value` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Varianten-Wert (z.B. XL)',
    `email`         VARCHAR(255) NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at`   DATETIME     DEFAULT NULL COMMENT 'Zeitpunkt der letzten Benachrichtigung',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_variant` (`user_id`, `product_id`, `variant_type`, `variant_value`),
    KEY `fk_restock_product` (`product_id`),
    CONSTRAINT `fk_restock_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Benachrichtigungen bei Wiederauffüllung ausverkaufter Varianten';

COMMIT;
