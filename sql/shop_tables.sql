-- Shop Module Tables
-- Creates tables for products, variants, orders and order items

CREATE TABLE IF NOT EXISTS `shop_products` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(255)   NOT NULL,
    `description` TEXT,
    `base_price`  DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `image_path`  VARCHAR(500),
    `active`      TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_variants` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`     INT UNSIGNED NOT NULL,
    `type`           VARCHAR(100) NOT NULL COMMENT 'z.B. Größe, Farbe',
    `value`          VARCHAR(100) NOT NULL COMMENT 'z.B. XL, Blau',
    `stock_quantity` INT          NOT NULL DEFAULT 0,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_variant_product` (`product_id`),
    CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_orders` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method`  ENUM('paypal','sepa') NOT NULL DEFAULT 'paypal',
    `payment_status`  VARCHAR(50)  NOT NULL DEFAULT 'pending'  COMMENT 'pending, paid, failed',
    `shipping_status` VARCHAR(50)  NOT NULL DEFAULT 'pending'  COMMENT 'pending, shipped, delivered',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_order_items` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`         INT UNSIGNED  NOT NULL,
    `product_id`       INT UNSIGNED  NOT NULL,
    `variant_id`       INT UNSIGNED  DEFAULT NULL,
    `quantity`         INT           NOT NULL DEFAULT 1,
    `price_at_purchase` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_item_order`   (`order_id`),
    KEY `fk_item_product` (`product_id`),
    KEY `fk_item_variant` (`variant_id`),
    CONSTRAINT `fk_item_order`   FOREIGN KEY (`order_id`)   REFERENCES `shop_orders`   (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`),
    CONSTRAINT `fk_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `shop_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
