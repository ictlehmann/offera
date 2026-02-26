<?php
/**
 * Shop Model
 * Manages shop products, variants, orders and order items.
 * All queries use prepared statements to prevent SQL injection.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../src/Auth.php';

class Shop {

    /**
     * Roles allowed to manage products and view all orders
     */
    const MANAGER_ROLES = ['board_finance', 'board_internal', 'board_external', 'head'];

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    /**
     * Return all active products (with their variants).
     *
     * @return array
     */
    public static function getActiveProducts(): array {
        try {
            $db = Database::getContentDB();
            $stmt = $db->query("
                SELECT id, name, description, base_price, image_path
                FROM shop_products
                WHERE active = 1
                ORDER BY name ASC
            ");
            $products = $stmt->fetchAll();

            foreach ($products as &$product) {
                $product['variants'] = self::getVariantsByProduct($product['id']);
            }

            return $products;
        } catch (Exception $e) {
            error_log('Shop::getActiveProducts – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return all products (active and inactive) for admin view.
     *
     * @return array
     */
    public static function getAllProducts(): array {
        try {
            $db = Database::getContentDB();
            $stmt = $db->query("
                SELECT id, name, description, base_price, image_path, active
                FROM shop_products
                ORDER BY name ASC
            ");
            $products = $stmt->fetchAll();

            foreach ($products as &$product) {
                $product['variants'] = self::getVariantsByProduct($product['id']);
            }

            return $products;
        } catch (Exception $e) {
            error_log('Shop::getAllProducts – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return a single product by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getProductById(int $id): ?array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT id, name, description, base_price, image_path, active
                FROM shop_products
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                return null;
            }
            $product['variants'] = self::getVariantsByProduct($id);
            return $product;
        } catch (Exception $e) {
            error_log('Shop::getProductById – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new product.
     *
     * @param array $data  Keys: name, description, base_price, image_path, active
     * @return int|null  New product ID or null on failure
     */
    public static function createProduct(array $data): ?int {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                INSERT INTO shop_products (name, description, base_price, image_path, active)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['base_price'] ?? 0,
                $data['image_path'] ?? null,
                isset($data['active']) ? (int) $data['active'] : 1,
            ]);
            return (int) $db->lastInsertId();
        } catch (Exception $e) {
            error_log('Shop::createProduct – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing product.
     *
     * @param int   $id
     * @param array $data  Keys: name, description, base_price, image_path, active
     * @return bool
     */
    public static function updateProduct(int $id, array $data): bool {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                UPDATE shop_products
                SET name = ?, description = ?, base_price = ?, image_path = ?, active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['base_price'] ?? 0,
                $data['image_path'] ?? null,
                isset($data['active']) ? (int) $data['active'] : 1,
                $id,
            ]);
            return true;
        } catch (Exception $e) {
            error_log('Shop::updateProduct – ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a product and all its variants (cascade).
     *
     * @param int $id
     * @return bool
     */
    public static function deleteProduct(int $id): bool {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("DELETE FROM shop_products WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Shop::deleteProduct – ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Variants
    // -------------------------------------------------------------------------

    /**
     * Return all variants for a product.
     *
     * @param int $productId
     * @return array
     */
    public static function getVariantsByProduct(int $productId): array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT id, type, value, stock_quantity
                FROM shop_variants
                WHERE product_id = ?
                ORDER BY type ASC, value ASC
            ");
            $stmt->execute([$productId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Shop::getVariantsByProduct – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Replace all variants for a product.
     * Deletes existing variants, then inserts new ones.
     *
     * @param int   $productId
     * @param array $variants  Each element: ['type' => string, 'value' => string, 'stock_quantity' => int]
     * @return bool
     */
    public static function setVariants(int $productId, array $variants): bool {
        try {
            $db = Database::getContentDB();
            $db->beginTransaction();

            $del = $db->prepare("DELETE FROM shop_variants WHERE product_id = ?");
            $del->execute([$productId]);

            $ins = $db->prepare("
                INSERT INTO shop_variants (product_id, type, value, stock_quantity)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($variants as $v) {
                $ins->execute([
                    $productId,
                    $v['type'] ?? '',
                    $v['value'] ?? '',
                    (int) ($v['stock_quantity'] ?? 0),
                ]);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Shop::setVariants – ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    /**
     * Check whether all variant items in the cart have sufficient stock.
     *
     * @param array $cart  Each element: ['variant_id' => int|null, 'quantity' => int, ...]
     * @return array  List of human-readable error strings; empty when stock is sufficient.
     */
    public static function checkStock(array $cart): array {
        $errors = [];
        try {
            $db = Database::getContentDB();
            foreach ($cart as $item) {
                if (empty($item['variant_id'])) {
                    continue;
                }
                $stmt = $db->prepare("
                    SELECT sv.stock_quantity, sv.type, sv.value, sp.name
                    FROM shop_variants sv
                    JOIN shop_products sp ON sp.id = sv.product_id
                    WHERE sv.id = ?
                ");
                $stmt->execute([(int) $item['variant_id']]);
                $variant = $stmt->fetch();
                if ($variant && (int) $variant['stock_quantity'] < (int) $item['quantity']) {
                    $errors[] = 'Artikel ' . $variant['name'] . ' ist leider nicht mehr verfügbar';
                }
            }
        } catch (Exception $e) {
            error_log('Shop::checkStock – ' . $e->getMessage());
            $errors[] = 'Lagerbestand konnte nicht geprüft werden.';
        }
        return $errors;
    }

    /**
     * Subtract purchased quantities from shop_variants for all variant items in an order.
     * Call this only after payment has been successfully initiated.
     *
     * @param int $orderId
     * @return bool
     */
    public static function decrementStock(int $orderId): bool {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT variant_id, quantity
                FROM shop_order_items
                WHERE order_id = ? AND variant_id IS NOT NULL
            ");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll();

            $upd = $db->prepare("
                UPDATE shop_variants
                SET stock_quantity = GREATEST(0, stock_quantity - ?)
                WHERE id = ?
            ");
            foreach ($items as $item) {
                $upd->execute([(int) $item['quantity'], (int) $item['variant_id']]);
            }
            return true;
        } catch (Exception $e) {
            error_log('Shop::decrementStock – ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create an order from the current session cart.
     *
     * @param int    $userId
     * @param array  $cart    Keys: product_id, variant_id, quantity, price
     * @param string $paymentMethod  'paypal' or 'sepa'
     * @return int|null  New order ID or null on failure
     */
    public static function createOrder(int $userId, array $cart, string $paymentMethod): ?int {
        try {
            $db = Database::getContentDB();
            $db->beginTransaction();

            $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cart));

            $stmt = $db->prepare("
                INSERT INTO shop_orders (user_id, total_amount, payment_method, payment_status, shipping_status)
                VALUES (?, ?, ?, 'pending', 'pending')
            ");
            $stmt->execute([$userId, $total, $paymentMethod]);
            $orderId = (int) $db->lastInsertId();

            $ins = $db->prepare("
                INSERT INTO shop_order_items (order_id, product_id, variant_id, quantity, price_at_purchase)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($cart as $item) {
                $ins->execute([
                    $orderId,
                    $item['product_id'],
                    $item['variant_id'] ?: null,
                    (int) $item['quantity'],
                    (float) $item['price'],
                ]);
            }

            $db->commit();
            return $orderId;
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Shop::createOrder – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return all orders (admin view), optionally joined with user email.
     *
     * @return array
     */
    public static function getAllOrders(): array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->query("
                SELECT id, user_id, total_amount, payment_method, payment_status, shipping_status, created_at
                FROM shop_orders
                ORDER BY created_at DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Shop::getAllOrders – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return orders for a specific user.
     *
     * @param int $userId
     * @return array
     */
    public static function getOrdersByUser(int $userId): array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT id, total_amount, payment_method, payment_status, shipping_status, created_at
                FROM shop_orders
                WHERE user_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Shop::getOrdersByUser – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update payment and/or shipping status of an order.
     *
     * @param int         $orderId
     * @param string|null $paymentStatus
     * @param string|null $shippingStatus
     * @return bool
     */
    public static function updateOrderStatus(int $orderId, ?string $paymentStatus, ?string $shippingStatus): bool {
        try {
            $db = Database::getContentDB();

            $validPayment  = ['pending', 'paid', 'failed'];
            $validShipping = ['pending', 'shipped', 'delivered'];

            $fields = [];
            $params = [];

            if ($paymentStatus !== null && in_array($paymentStatus, $validPayment)) {
                $fields[] = 'payment_status = ?';
                $params[]  = $paymentStatus;
            }
            if ($shippingStatus !== null && in_array($shippingStatus, $validShipping)) {
                $fields[] = 'shipping_status = ?';
                $params[]  = $shippingStatus;
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $orderId;
            $stmt = $db->prepare("UPDATE shop_orders SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            return true;
        } catch (Exception $e) {
            error_log('Shop::updateOrderStatus – ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    /**
     * Return monthly sales count and revenue for the last N months.
     *
     * @param int $months
     * @return array  Each row: ['month' => 'YYYY-MM', 'count' => int, 'revenue' => float]
     */
    public static function getMonthlySalesStats(int $months = 12): array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m') AS month,
                    COUNT(*)                          AS count,
                    COALESCE(SUM(total_amount), 0)   AS revenue
                FROM shop_orders
                WHERE payment_status = 'paid'
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                GROUP BY month
                ORDER BY month ASC
            ");
            $stmt->execute([$months]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Shop::getMonthlySalesStats – ' . $e->getMessage());
            return [];
        }
    }
}
