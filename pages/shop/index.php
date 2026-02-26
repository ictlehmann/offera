<?php
/**
 * Shop – Member-facing storefront
 * Handles: product grid, product detail, cart management, checkout
 * Access: all authenticated users
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';
require_once __DIR__ . '/../../src/ShopPaymentService.php';
require_once __DIR__ . '/../../src/MailService.php';

// Authentication check
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user   = Auth::user();
$userId = (int) ($user['id'] ?? 0);

// Bootstrap the cart in the session
if (!isset($_SESSION['shop_cart'])) {
    $_SESSION['shop_cart'] = [];
}

$successMessage = '';
$errorMessage   = '';
$action         = $_GET['action'] ?? 'list';
$productId      = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

// ─── Cart helpers ─────────────────────────────────────────────────────────────

function cartKey(int $productId, ?int $variantId): string {
    return $productId . '_' . ($variantId ?? 0);
}

function cartTotal(): float {
    $total = 0;
    foreach ($_SESSION['shop_cart'] as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

function cartCount(): int {
    return array_sum(array_column($_SESSION['shop_cart'], 'quantity'));
}

// ─── Handle GET: PayPal return after buyer approval ───────────────────────────

if ($action === 'payment_return' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId       = isset($_GET['order']) ? (int) $_GET['order'] : 0;
    $paypalToken   = $_GET['token'] ?? '';          // PayPal order ID

    if ($orderId && $paypalToken) {
        $captureResult = ShopPaymentService::capturePaypalPayment($orderId, $paypalToken);
        if ($captureResult['success']) {
            $successMessage = 'Zahlung für Bestellung #' . $orderId . ' erfolgreich abgeschlossen!';
        } else {
            $errorMessage = 'PayPal-Zahlung konnte nicht abgebucht werden: ' . ($captureResult['error'] ?? '');
        }
    } else {
        $errorMessage = 'Ungültige Rückkehr-URL von PayPal.';
    }
    $action = 'list';
}

// ─── Handle POST actions ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'add_to_cart') {
        $pid       = (int) ($_POST['product_id'] ?? 0);
        $vid       = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int) $_POST['variant_id'] : null;
        $qty       = max(1, (int) ($_POST['quantity'] ?? 1));
        $product   = Shop::getProductById($pid);

        if ($product) {
            $price = (float) $product['base_price'];
            $key   = cartKey($pid, $vid);
            $variantName = '';

            if ($vid) {
                foreach ($product['variants'] as $v) {
                    if ((int) $v['id'] === $vid) {
                        $variantName = $v['type'] . ': ' . $v['value'];
                        break;
                    }
                }
            }

            if (isset($_SESSION['shop_cart'][$key])) {
                $_SESSION['shop_cart'][$key]['quantity'] += $qty;
            } else {
                $_SESSION['shop_cart'][$key] = [
                    'product_id'   => $pid,
                    'variant_id'   => $vid,
                    'product_name' => $product['name'],
                    'variant_name' => $variantName,
                    'price'        => $price,
                    'quantity'     => $qty,
                ];
            }
            $successMessage = htmlspecialchars($product['name']) . ' wurde zum Warenkorb hinzugefügt.';
        } else {
            $errorMessage = 'Produkt nicht gefunden.';
        }
    } elseif ($postAction === 'update_cart') {
        foreach ($_POST['quantities'] as $key => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                unset($_SESSION['shop_cart'][$key]);
            } elseif (isset($_SESSION['shop_cart'][$key])) {
                $_SESSION['shop_cart'][$key]['quantity'] = $qty;
            }
        }
        $successMessage = 'Warenkorb aktualisiert.';
        $action = 'cart';
    } elseif ($postAction === 'remove_from_cart') {
        $key = $_POST['cart_key'] ?? '';
        unset($_SESSION['shop_cart'][$key]);
        $action = 'cart';
    } elseif ($postAction === 'checkout') {
        $paymentMethod = in_array($_POST['payment_method'] ?? '', ['paypal', 'sepa']) ? $_POST['payment_method'] : 'paypal';

        if (empty($_SESSION['shop_cart'])) {
            $errorMessage = 'Ihr Warenkorb ist leer.';
            $action = 'cart';
        } else {
            $stockErrors = Shop::checkStock(array_values($_SESSION['shop_cart']));
            if (!empty($stockErrors)) {
                $errorMessage = implode(' ', $stockErrors);
                $action = 'cart';
            } else {
                $orderId = Shop::createOrder($userId, array_values($_SESSION['shop_cart']), $paymentMethod);

                if ($orderId) {
                    if ($paymentMethod === 'paypal') {
                        $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
                        $returnUrl = $baseUrl . '/pages/shop/index.php?action=payment_return&order=' . $orderId;
                        $cancelUrl = $baseUrl . '/pages/shop/index.php?action=cart';
                        $payResult = ShopPaymentService::initiatePayPal($orderId, cartTotal(), $returnUrl, $cancelUrl);

                        if ($payResult['success'] && !empty($payResult['redirect_url'])) {
                            Shop::decrementStock($orderId);
                            $cartForEmail = array_values($_SESSION['shop_cart']);
                            $totalForEmail = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartForEmail));
                            $_SESSION['shop_cart'] = [];
                            try {
                                MailService::sendNewOrderNotification(
                                    $orderId,
                                    $user['first_name'] ?? '',
                                    $user['last_name']  ?? '',
                                    $user['email']      ?? '',
                                    $cartForEmail,
                                    $paymentMethod,
                                    $totalForEmail
                                );
                            } catch (Exception $e) {
                                error_log('pages/shop/index.php – order notification email failed: ' . $e->getMessage());
                            }
                            header('Location: ' . $payResult['redirect_url']);
                            exit;
                        }
                        $errorMessage = $payResult['error'] ?? 'PayPal-Weiterleitung fehlgeschlagen.';
                        $action = 'cart';
                    } elseif ($paymentMethod === 'sepa') {
                        $iban       = trim($_POST['sepa_iban'] ?? '');
                        $holder     = trim($_POST['sepa_holder'] ?? '');
                        $payResult  = ShopPaymentService::initiateSepa($orderId, cartTotal(), $iban, $holder);

                        if ($payResult['success']) {
                            Shop::decrementStock($orderId);
                            $cartForEmail = array_values($_SESSION['shop_cart']);
                            $totalForEmail = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartForEmail));
                            try {
                                MailService::sendNewOrderNotification(
                                    $orderId,
                                    $user['first_name'] ?? '',
                                    $user['last_name']  ?? '',
                                    $user['email']      ?? '',
                                    $cartForEmail,
                                    $paymentMethod,
                                    $totalForEmail
                                );
                            } catch (Exception $e) {
                                error_log('pages/shop/index.php – order notification email failed: ' . $e->getMessage());
                            }
                            $successMessage = 'Bestellung #' . $orderId . ' aufgegeben! Ihre SEPA-Lastschrift wurde bei der Bank eingereicht.';
                        } else {
                            $errorMessage = $payResult['error'] ?? 'SEPA-Zahlung fehlgeschlagen.';
                        }
                        $_SESSION['shop_cart'] = [];
                        $action = 'list';
                    } else {
                        $_SESSION['shop_cart'] = [];
                        $successMessage = 'Bestellung #' . $orderId . ' wurde erfolgreich aufgegeben!';
                        $action = 'list';
                    }
                } else {
                    $errorMessage = 'Fehler beim Erstellen der Bestellung. Bitte versuchen Sie es erneut.';
                    $action = 'cart';
                }
            }
        }
    } elseif ($postAction === 'toggle_restock_notification') {
        $pid  = (int) ($_POST['product_id'] ?? 0);
        $type = trim($_POST['variant_type']  ?? '');
        $val  = trim($_POST['variant_value'] ?? '');

        if ($pid && $userId) {
            $hasNotif = Shop::hasRestockNotification($userId, $pid, $type, $val);
            if ($hasNotif) {
                Shop::removeRestockNotification($userId, $pid, $type, $val);
                $successMessage = 'Benachrichtigung deaktiviert.';
            } else {
                $userEmail = $user['email'] ?? '';
                Shop::addRestockNotification($userId, $pid, $type, $val, $userEmail);
                $successMessage = 'Du wirst benachrichtigt, sobald der Artikel wieder verfügbar ist.';
            }
        }
        $action    = 'detail';
        $productId = $pid;
    }
}

// ─── Prepare view data ─────────────────────────────────────────────────────────

$products       = [];
$currentProduct = null;

if ($action === 'list' || $action === 'add_to_cart') {
    $products = Shop::getActiveProducts();
} elseif ($action === 'detail' && $productId) {
    $currentProduct = Shop::getProductById($productId);
    if (!$currentProduct || !$currentProduct['active']) {
        $action = 'list';
        $products = Shop::getActiveProducts();
    }
}

$cartItems  = $_SESSION['shop_cart'];
$cartCount  = cartCount();
$cartTotalAmt = cartTotal();

$title = 'Shop – IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-shopping-cart mr-3 text-blue-600 dark:text-blue-400"></i>
                Shop
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Exklusive Artikel für IBC-Mitglieder</p>
        </div>
        <a href="<?php echo asset('pages/shop/index.php?action=cart'); ?>"
           class="relative inline-flex items-center px-5 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg font-medium no-underline">
            <i class="fas fa-shopping-cart mr-2"></i>
            Warenkorb
            <?php if ($cartCount > 0): ?>
            <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center">
                <?php echo $cartCount > 99 ? '99+' : $cartCount; ?>
            </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Flash messages -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo $successMessage; ?>
    </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
    <!-- ── Product Grid ──────────────────────────────────────────────────────── -->
    <?php if (empty($products)): ?>
    <div class="text-center py-20 text-gray-500 dark:text-gray-400">
        <i class="fas fa-box-open text-5xl mb-4 opacity-40"></i>
        <p class="text-xl">Derzeit sind keine Produkte verfügbar.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php foreach ($products as $product): ?>
        <div class="card rounded-xl shadow-md hover:shadow-xl transition-shadow duration-300 overflow-hidden flex flex-col">
            <!-- Product image -->
            <?php if (!empty($product['image_path'])): ?>
            <div class="h-48 overflow-hidden bg-gray-100 dark:bg-gray-700">
                <img src="<?php echo asset($product['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                     class="w-full h-full object-cover">
            </div>
            <?php else: ?>
            <div class="h-48 bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-900 dark:to-blue-800 flex items-center justify-center">
                <i class="fas fa-box text-blue-400 text-5xl opacity-50"></i>
            </div>
            <?php endif; ?>

            <!-- Product info -->
            <div class="p-4 flex flex-col flex-1">
                <h3 class="font-bold text-gray-800 dark:text-gray-100 text-lg mb-1 line-clamp-2">
                    <?php echo htmlspecialchars($product['name']); ?>
                </h3>
                <?php if (!empty($product['description'])): ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-3 flex-1">
                    <?php echo htmlspecialchars($product['description']); ?>
                </p>
                <?php else: ?>
                <div class="flex-1"></div>
                <?php endif; ?>
                <div class="flex items-center justify-between mt-3">
                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400">
                        <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                    </span>
                    <a href="<?php echo asset('pages/shop/index.php?action=detail&product_id=' . $product['id']); ?>"
                       class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium no-underline">
                        Ansehen
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($action === 'detail' && $currentProduct): ?>
    <!-- ── Product Detail ────────────────────────────────────────────────────── -->
    <div class="mb-4">
        <a href="<?php echo asset('pages/shop/index.php'); ?>" class="text-blue-600 dark:text-blue-400 hover:underline text-sm no-underline">
            <i class="fas fa-arrow-left mr-1"></i> Zurück zum Shop
        </a>
    </div>
    <div class="card rounded-xl shadow-lg p-6 lg:p-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Image -->
            <div>
                <?php if (!empty($currentProduct['image_path'])): ?>
                <img src="<?php echo asset($currentProduct['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($currentProduct['name']); ?>"
                     class="w-full rounded-xl object-cover max-h-96">
                <?php else: ?>
                <div class="w-full h-72 bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-900 dark:to-blue-800 rounded-xl flex items-center justify-center">
                    <i class="fas fa-box text-blue-400 text-6xl opacity-50"></i>
                </div>
                <?php endif; ?>
            </div>

            <!-- Details & Add to cart -->
            <div class="flex flex-col">
                <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-100 mb-3">
                    <?php echo htmlspecialchars($currentProduct['name']); ?>
                </h2>
                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mb-4">
                    <?php echo number_format((float) $currentProduct['base_price'], 2, ',', '.'); ?> €
                </p>
                <?php if (!empty($currentProduct['description'])): ?>
                <p class="text-gray-600 dark:text-gray-300 mb-6 leading-relaxed">
                    <?php echo nl2br(htmlspecialchars($currentProduct['description'])); ?>
                </p>
                <?php endif; ?>

                <form method="POST" action="<?php echo asset('pages/shop/index.php?action=detail&product_id=' . $currentProduct['id']); ?>">
                    <input type="hidden" name="post_action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?php echo $currentProduct['id']; ?>">

                    <!-- Variants -->
                    <?php if (!empty($currentProduct['variants'])): ?>
                    <?php
                        // Group variants by type
                        $grouped = [];
                        foreach ($currentProduct['variants'] as $v) {
                            $grouped[$v['type']][] = $v;
                        }
                    ?>
                    <?php foreach ($grouped as $type => $variants): ?>
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            <?php echo htmlspecialchars($type); ?>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($variants as $v): ?>
                            <?php $outOfStock = $v['stock_quantity'] <= 0; ?>
                            <label class="relative cursor-pointer<?php echo $outOfStock ? ' opacity-60' : ''; ?>">
                                <input type="radio" name="variant_id" value="<?php echo $v['id']; ?>"
                                       <?php echo $outOfStock ? 'disabled' : ''; ?>
                                       class="sr-only peer"
                                       <?php echo !$outOfStock ? 'required' : ''; ?>>
                                <span class="inline-flex items-center px-4 py-2 border-2 rounded-lg text-sm font-medium transition-all
                                    <?php echo $outOfStock
                                        ? 'border-gray-200 dark:border-gray-600 text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/30 line-through'
                                        : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/30 peer-checked:text-blue-700 dark:peer-checked:text-blue-300 hover:border-blue-400'; ?>">
                                    <?php echo htmlspecialchars($v['value']); ?>
                                    <?php if ($outOfStock): ?><span class="ml-1 text-xs">(Ausverkauft)</span><?php endif; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Restock notification for sold-out variants in this type group -->
                        <?php foreach ($variants as $v):
                            if ($v['stock_quantity'] > 0) continue;
                            $hasNotif = Shop::hasRestockNotification($userId, $currentProduct['id'], $type, $v['value']);
                        ?>
                        <form method="POST"
                              action="<?php echo asset('pages/shop/index.php?action=detail&product_id=' . $currentProduct['id']); ?>"
                              class="mt-1 inline-block">
                            <input type="hidden" name="post_action"   value="toggle_restock_notification">
                            <input type="hidden" name="product_id"    value="<?php echo $currentProduct['id']; ?>">
                            <input type="hidden" name="variant_type"  value="<?php echo htmlspecialchars($type); ?>">
                            <input type="hidden" name="variant_value" value="<?php echo htmlspecialchars($v['value']); ?>">
                            <button type="submit"
                                    class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
                                        <?php echo $hasNotif
                                            ? 'border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 hover:bg-amber-100'
                                            : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400'; ?>">
                                <i class="fas <?php echo $hasNotif ? 'fa-bell-slash' : 'fa-bell'; ?>"></i>
                                <?php echo $hasNotif
                                    ? 'Benachrichtigung für ' . htmlspecialchars($v['value']) . ' deaktivieren'
                                    : 'Benachrichtigung wenn ' . htmlspecialchars($v['value']) . ' wieder verfügbar'; ?>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Quantity -->
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Anzahl</label>
                        <input type="number" name="quantity" value="1" min="1" max="99"
                               class="w-20 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <?php
                        $anyInStock = !empty($currentProduct['variants'])
                            ? array_sum(array_column($currentProduct['variants'], 'stock_quantity')) > 0
                            : true;
                    ?>
                    <?php if ($anyInStock): ?>
                    <button type="submit"
                            class="w-full py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold transition-all shadow-lg text-lg">
                        <i class="fas fa-cart-plus mr-2"></i>In den Warenkorb
                    </button>
                    <?php else: ?>
                    <div class="w-full py-3 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-lg text-center font-semibold text-lg">
                        <i class="fas fa-ban mr-2"></i>Derzeit nicht verfügbar
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($action === 'cart'): ?>
    <!-- ── Cart ──────────────────────────────────────────────────────────────── -->
    <div class="card rounded-xl shadow-lg p-6 lg:p-8">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-shopping-cart mr-2 text-blue-600"></i>Warenkorb
        </h2>

        <?php if (empty($cartItems)): ?>
        <div class="text-center py-16 text-gray-500 dark:text-gray-400">
            <i class="fas fa-shopping-cart text-5xl mb-4 opacity-30"></i>
            <p class="text-xl mb-4">Ihr Warenkorb ist leer.</p>
            <a href="<?php echo asset('pages/shop/index.php'); ?>"
               class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors no-underline font-medium">
                <i class="fas fa-arrow-left mr-2"></i>Weiter einkaufen
            </a>
        </div>
        <?php else: ?>
        <form method="POST" id="cart-form">
            <input type="hidden" name="post_action" value="update_cart">
            <div class="overflow-x-auto mb-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-left">
                            <th class="pb-3 font-semibold">Produkt</th>
                            <th class="pb-3 font-semibold text-center">Variante</th>
                            <th class="pb-3 font-semibold text-center">Preis</th>
                            <th class="pb-3 font-semibold text-center">Anzahl</th>
                            <th class="pb-3 font-semibold text-right">Gesamt</th>
                            <th class="pb-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($cartItems as $key => $item): ?>
                        <tr class="py-4">
                            <td class="py-4 font-medium text-gray-800 dark:text-gray-100">
                                <?php echo htmlspecialchars($item['product_name']); ?>
                            </td>
                            <td class="py-4 text-center text-gray-500 dark:text-gray-400">
                                <?php echo $item['variant_name'] ? htmlspecialchars($item['variant_name']) : '–'; ?>
                            </td>
                            <td class="py-4 text-center text-gray-700 dark:text-gray-300">
                                <?php echo number_format($item['price'], 2, ',', '.'); ?> €
                            </td>
                            <td class="py-4 text-center">
                                <input type="number"
                                       name="quantities[<?php echo htmlspecialchars($key); ?>]"
                                       value="<?php echo $item['quantity']; ?>"
                                       min="0" max="99"
                                       class="w-16 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-center bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100">
                            </td>
                            <td class="py-4 text-right font-semibold text-gray-800 dark:text-gray-100">
                                <?php echo number_format($item['price'] * $item['quantity'], 2, ',', '.'); ?> €
                            </td>
                            <td class="py-4 text-right">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="post_action" value="remove_from_cart">
                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 dark:text-red-400 p-1" title="Entfernen">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                            <td colspan="4" class="pt-4 font-bold text-gray-800 dark:text-gray-100 text-right">Gesamtsumme:</td>
                            <td class="pt-4 text-right font-bold text-xl text-blue-600 dark:text-blue-400">
                                <?php echo number_format($cartTotalAmt, 2, ',', '.'); ?> €
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 justify-between">
                <a href="<?php echo asset('pages/shop/index.php'); ?>"
                   class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all text-center font-medium no-underline">
                    <i class="fas fa-arrow-left mr-2"></i>Weiter einkaufen
                </a>
                <div class="flex gap-3">
                    <button type="submit" form="cart-form"
                            class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-all font-medium">
                        <i class="fas fa-sync mr-2"></i>Aktualisieren
                    </button>
                    <a href="<?php echo asset('pages/shop/index.php?action=checkout'); ?>"
                       class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all font-semibold shadow-lg no-underline">
                        <i class="fas fa-credit-card mr-2"></i>Zur Kasse
                    </a>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php elseif ($action === 'checkout'): ?>
    <!-- ── Checkout ───────────────────────────────────────────────────────────── -->
    <?php if (empty($cartItems)): ?>
    <div class="text-center py-20 text-gray-500 dark:text-gray-400">
        <p class="text-xl mb-4">Ihr Warenkorb ist leer.</p>
        <a href="<?php echo asset('pages/shop/index.php'); ?>"
           class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors no-underline font-medium">
            <i class="fas fa-arrow-left mr-2"></i>Zum Shop
        </a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Payment form -->
        <div class="lg:col-span-2">
            <div class="card rounded-xl shadow-lg p-6 lg:p-8">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
                    <i class="fas fa-credit-card mr-2 text-blue-600"></i>Zahlungsmethode
                </h2>
                <form method="POST" id="checkout-form">
                    <input type="hidden" name="post_action" value="checkout">

                    <!-- Payment method selection -->
                    <div class="mb-6 space-y-3">
                        <label class="flex items-center gap-4 p-4 border-2 rounded-xl cursor-pointer transition-all
                                      border-gray-200 dark:border-gray-700 hover:border-blue-400
                                      has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/20">
                            <input type="radio" name="payment_method" value="paypal" checked class="sr-only peer">
                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fab fa-paypal text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800 dark:text-gray-100">PayPal</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Schnell und sicher mit PayPal bezahlen</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-4 p-4 border-2 rounded-xl cursor-pointer transition-all
                                      border-gray-200 dark:border-gray-700 hover:border-blue-400
                                      has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/20"
                               id="sepa-label">
                            <input type="radio" name="payment_method" value="sepa" id="sepa-radio" class="sr-only peer">
                            <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-university text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800 dark:text-gray-100">SEPA-Lastschrift</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Per Bankeinzug bezahlen</p>
                            </div>
                        </label>
                    </div>

                    <!-- SEPA fields (shown when SEPA is selected) -->
                    <div id="sepa-fields" class="hidden mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Kontoinhaber</label>
                            <input type="text" name="sepa_holder" placeholder="Max Mustermann"
                                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">IBAN</label>
                            <input type="text" name="sepa_iban" placeholder="DE89 3704 0044 0532 0130 00"
                                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <button type="submit" form="checkout-form"
                            class="w-full py-4 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 font-bold text-lg transition-all shadow-lg">
                        <i class="fas fa-lock mr-2"></i>
                        Jetzt kaufen – <?php echo number_format($cartTotalAmt, 2, ',', '.'); ?> €
                    </button>
                </form>
            </div>
        </div>

        <!-- Order summary -->
        <div>
            <div class="card rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Bestellübersicht</h3>
                <div class="space-y-3 divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($cartItems as $item): ?>
                    <div class="flex justify-between pt-3 first:pt-0">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($item['product_name']); ?></p>
                            <?php if ($item['variant_name']): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($item['variant_name']); ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400">× <?php echo $item['quantity']; ?></p>
                        </div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <?php echo number_format($item['price'] * $item['quantity'], 2, ',', '.'); ?> €
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 pt-4 border-t-2 border-gray-300 dark:border-gray-600 flex justify-between items-center">
                    <span class="font-bold text-gray-800 dark:text-gray-100">Gesamt</span>
                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400">
                        <?php echo number_format($cartTotalAmt, 2, ',', '.'); ?> €
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<script>
// Show/hide SEPA fields based on payment selection
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('input[name="payment_method"]');
    const sepaFields = document.getElementById('sepa-fields');

    function toggleSepaFields() {
        const selected = document.querySelector('input[name="payment_method"]:checked');
        if (sepaFields) {
            sepaFields.classList.toggle('hidden', !selected || selected.value !== 'sepa');
        }
    }

    radios.forEach(function(r) { r.addEventListener('change', toggleSepaFields); });
    toggleSepaFields();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
