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
        $pid             = (int) ($_POST['product_id'] ?? 0);
        $vid             = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int) $_POST['variant_id'] : null;
        $qty             = max(1, (int) ($_POST['quantity'] ?? 1));
        $selectedVariant = trim($_POST['selected_variant'] ?? '');
        $product         = Shop::getProductById($pid);

        if ($product) {
            // Validate that a variant was selected if the product requires one
            if (!empty($product['variants_csv']) && $selectedVariant === '') {
                $errorMessage = 'Bitte wähle eine Variante aus.';
                $action = 'detail';
                $productId = $pid;
                $currentProduct = $product;
            } else {
            $price = (float) $product['base_price'];
            $key   = cartKey($pid, $vid) . ($selectedVariant !== '' ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $selectedVariant) : '');
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
                    'product_id'      => $pid,
                    'variant_id'      => $vid,
                    'product_name'    => $product['name'],
                    'variant_name'    => $variantName,
                    'selected_variant' => $selectedVariant,
                    'price'           => $price,
                    'quantity'        => $qty,
                ];
            }
            $successMessage = htmlspecialchars($product['name']) . ' wurde zum Warenkorb hinzugefügt.';
            }
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
        $paymentMethod = in_array($_POST['payment_method'] ?? '', ['paypal', 'bank_transfer']) ? $_POST['payment_method'] : 'paypal';
        $shippingMethod  = in_array($_POST['shipping_method'] ?? '', ['pickup', 'mail']) ? $_POST['shipping_method'] : 'pickup';
        $shippingCountry = strtoupper(trim($_POST['shipping_country'] ?? 'DE'));
        if (!preg_match('/^[A-Z]{2}$/', $shippingCountry)) {
            $shippingCountry = 'DE';
        }
        $shippingCost    = ($shippingMethod === 'mail') ? Shop::calculateShippingCost($shippingCountry, cartTotal()) : 0.00;
        $shippingAddress = trim($_POST['shipping_address'] ?? '');

        if ($shippingMethod === 'mail' && $shippingAddress === '') {
            $errorMessage = 'Bitte gib eine Lieferadresse an.';
            $action = 'checkout';
        } elseif (empty($_SESSION['shop_cart'])) {
            $errorMessage = 'Ihr Warenkorb ist leer.';
            $action = 'cart';
        } else {
            $stockErrors = Shop::checkStock(array_values($_SESSION['shop_cart']));
            if (!empty($stockErrors)) {
                $errorMessage = implode(' ', $stockErrors);
                $action = 'cart';
            } else {
                // Collect selected variants from all cart items
                $variantParts = [];
                foreach ($_SESSION['shop_cart'] as $item) {
                    if (!empty($item['selected_variant'])) {
                        $variantParts[] = $item['selected_variant'];
                    }
                }
                $selectedVariant = implode(', ', array_unique($variantParts));

                $orderId = Shop::createOrder($userId, array_values($_SESSION['shop_cart']), $paymentMethod, $shippingMethod, $shippingCost, $shippingAddress, $selectedVariant);

                if ($orderId) {
                    if ($paymentMethod === 'paypal') {
                        $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
                        $returnUrl = $baseUrl . '/pages/shop/index.php?action=payment_return&order=' . $orderId;
                        $cancelUrl = $baseUrl . '/pages/shop/index.php?action=cart';
                        $grandTotal = cartTotal() + $shippingCost;
                        $payResult = ShopPaymentService::initiatePayPal($orderId, $grandTotal, $returnUrl, $cancelUrl);

                        if ($payResult['success'] && !empty($payResult['redirect_url'])) {
                            Shop::decrementStock($orderId);
                            $cartForEmail = array_values($_SESSION['shop_cart']);
                            $totalForEmail = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartForEmail)) + $shippingCost;
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
                    } elseif ($paymentMethod === 'bank_transfer') {
                        $grandTotal   = cartTotal() + $shippingCost;
                        $cartForEmail = array_values($_SESSION['shop_cart']);
                        Shop::decrementStock($orderId);
                        $payResult = ShopPaymentService::initiateBankTransfer(
                            $orderId,
                            $grandTotal,
                            $user['first_name'] ?? '',
                            $user['last_name']  ?? '',
                            $userId,
                            $user['email']      ?? ''
                        );
                        $_SESSION['shop_cart'] = [];
                        if ($payResult['success']) {
                            try {
                                MailService::sendNewOrderNotification(
                                    $orderId,
                                    $user['first_name'] ?? '',
                                    $user['last_name']  ?? '',
                                    $user['email']      ?? '',
                                    $cartForEmail,
                                    $paymentMethod,
                                    $grandTotal
                                );
                            } catch (Exception $e) {
                                error_log('pages/shop/index.php – order notification email failed: ' . $e->getMessage());
                            }
                            $successMessage = 'Bestellung #' . $orderId . ' aufgegeben! Die Überweisungsdetails wurden per E-Mail gesendet.';
                        } else {
                            $errorMessage = $payResult['error'] ?? 'Banküberweisung konnte nicht initiiert werden.';
                        }
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

    <!-- ── Filter Panel ── -->
    <?php
    $availableGenders = array_unique(array_filter(array_column($products, 'gender'), function($g) {
        return !empty($g) && $g !== 'Keine';
    }));
    sort($availableGenders);
    $availableCategories = array_unique(array_filter(array_column($products, 'category')));
    sort($availableCategories);
    ?>
    <div class="mb-8 rounded-2xl overflow-hidden shadow-md border border-blue-100 dark:border-gray-700" style="background: linear-gradient(135deg,#f0f7ff 0%,#fff 60%) ;">
        <style>
        .dark .shop-filter-wrap { background: linear-gradient(135deg,#1e293b 0%,#1f2937 60%) !important; }
        .filter-section-card { background: rgba(255,255,255,0.7); border: 1px solid rgba(99,102,241,0.08); }
        .dark .filter-section-card { background: rgba(31,41,55,0.8); border: 1px solid rgba(99,102,241,0.15); }
        .fpill { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border-radius: 9999px; font-size: 0.8125rem; font-weight: 600; border: 2px solid transparent; cursor: pointer; transition: all .18s ease; }
        .fpill:not(.fpill-active) { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
        .dark .fpill:not(.fpill-active) { background: #374151; color: #d1d5db; border-color: #4b5563; }
        .fpill:not(.fpill-active):hover { border-color: currentColor; }
        .fpill.fpill-active { color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .fpill-avail.fpill-active  { background: linear-gradient(135deg,#059669,#10b981); border-color: #059669; }
        .fpill-avail:not(.fpill-active):hover { color: #059669; border-color: #059669; background: #ecfdf5; }
        .dark .fpill-avail:not(.fpill-active):hover { background: rgba(16,185,129,.1); }
        .fpill-sold.fpill-active   { background: linear-gradient(135deg,#6b7280,#9ca3af); border-color: #6b7280; }
        .fpill-sold:not(.fpill-active):hover  { color: #6b7280; border-color: #9ca3af; }
        .fpill-gender.fpill-active { background: linear-gradient(135deg,#2563eb,#6366f1); border-color: #2563eb; }
        .fpill-gender:not(.fpill-active):hover { color: #2563eb; border-color: #6366f1; background: #eff6ff; }
        .dark .fpill-gender:not(.fpill-active):hover { background: rgba(99,102,241,.1); }
        .fpill-cat.fpill-active    { background: linear-gradient(135deg,#7c3aed,#a855f7); border-color: #7c3aed; }
        .fpill-cat:not(.fpill-active):hover   { color: #7c3aed; border-color: #a855f7; background: #faf5ff; }
        .dark .fpill-cat:not(.fpill-active):hover { background: rgba(168,85,247,.1); }
        .fpill-all.fpill-active    { background: linear-gradient(135deg,#2563eb,#4f46e5); border-color: #2563eb; }
        .fpill-all:not(.fpill-active):hover { color: #4f46e5; border-color: #4f46e5; background: #eff6ff; }
        .dark .fpill-all:not(.fpill-active):hover { background: rgba(79,70,229,.1); }
        </style>

        <!-- Filter header -->
        <div class="shop-filter-wrap px-5 py-3.5 flex items-center justify-between border-b border-blue-100 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                    <i class="fas fa-sliders-h text-white text-sm"></i>
                </div>
                <div>
                    <span class="font-bold text-gray-800 dark:text-gray-100 text-base leading-tight">Filter &amp; Suche</span>
                    <span id="filter-active-count" class="hidden ml-2 px-2 py-0.5 bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-xs font-bold rounded-full shadow-sm"></span>
                </div>
            </div>
            <button id="filter-toggle" type="button"
                    class="w-8 h-8 rounded-xl bg-white/60 dark:bg-gray-700/60 hover:bg-white dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-all shadow-sm"
                    aria-label="Filter ein-/ausklappen">
                <i class="fas fa-chevron-up text-xs" id="filter-toggle-icon"></i>
            </button>
        </div>

        <!-- Filter body -->
        <div id="filter-body" class="shop-filter-wrap p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">

                <!-- Search -->
                <div class="sm:col-span-2 xl:col-span-1">
                    <p class="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-widest mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-magnifying-glass"></i> Suche
                    </p>
                    <div class="relative filter-section-card rounded-xl overflow-hidden">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-blue-400 dark:text-blue-500 text-sm pointer-events-none"></i>
                        <input type="text" id="search-input" placeholder="Produkte suchen…"
                               class="w-full pl-10 pr-9 py-2.5 bg-transparent text-gray-800 dark:text-gray-100 text-sm placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:ring-inset outline-none transition-all">
                        <button type="button" id="search-clear"
                                class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition-colors">
                            <i class="fas fa-times-circle text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Availability -->
                <div>
                    <p class="text-xs font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-circle-dot"></i> Verfügbarkeit
                    </p>
                    <div class="flex flex-wrap gap-1.5" id="filter-bar">
                        <button type="button" data-filter="all"
                                class="filter-pill fpill fpill-all fpill-active">
                            <i class="fas fa-border-all text-xs"></i>Alle
                        </button>
                        <button type="button" data-filter="available"
                                class="filter-pill fpill fpill-avail">
                            <i class="fas fa-circle-check text-xs"></i>Verfügbar
                        </button>
                        <button type="button" data-filter="soldout"
                                class="filter-pill fpill fpill-sold">
                            <i class="fas fa-ban text-xs"></i>Ausverkauft
                        </button>
                    </div>
                </div>

                <!-- Gender -->
                <?php if (!empty($availableGenders)): ?>
                <div id="gender-filter-wrap">
                    <p class="text-xs font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-widest mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-person"></i> Zielgruppe
                    </p>
                    <div class="flex flex-wrap gap-1.5" id="gender-filter-bar">
                        <button type="button" data-gender="all"
                                class="gender-pill fpill fpill-all fpill-active">
                            <i class="fas fa-border-all text-xs"></i>Alle
                        </button>
                        <?php foreach (['Herren', 'Damen', 'Unisex'] as $g): ?>
                        <?php if (in_array($g, $availableGenders, true)): ?>
                        <?php
                        $gIcon = $g === 'Herren' ? 'fa-mars' : ($g === 'Damen' ? 'fa-venus' : 'fa-venus-mars');
                        ?>
                        <button type="button" data-gender="<?php echo htmlspecialchars($g); ?>"
                                class="gender-pill fpill fpill-gender">
                            <i class="fas <?php echo $gIcon; ?> text-xs"></i><?php echo htmlspecialchars($g); ?>
                        </button>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Category -->
                <?php if (!empty($availableCategories)): ?>
                <div id="category-filter-wrap">
                    <p class="text-xs font-bold text-purple-600 dark:text-purple-400 uppercase tracking-widest mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-shapes"></i> Kategorie
                    </p>
                    <div class="flex flex-wrap gap-1.5" id="category-filter-bar">
                        <button type="button" data-category="all"
                                class="category-pill fpill fpill-all fpill-active">
                            <i class="fas fa-border-all text-xs"></i>Alle
                        </button>
                        <?php foreach ($availableCategories as $cat): ?>
                        <button type="button" data-category="<?php echo htmlspecialchars($cat); ?>"
                                class="category-pill fpill fpill-cat">
                            <?php echo htmlspecialchars($cat); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Results count -->
            <div class="mt-4 pt-4 border-t border-blue-100 dark:border-gray-700 flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded-lg font-bold text-xs" id="results-count"><?php echo count($products); ?></span>
                    von <?php echo count($products); ?> Produkten
                </p>
                <button type="button" id="reset-filters"
                        class="hidden text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-200 font-semibold flex items-center gap-1.5 transition-colors px-3 py-1.5 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20">
                    <i class="fas fa-rotate-left text-xs"></i> Filter zurücksetzen
                </button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5" id="product-grid">
        <?php foreach ($products as $product):
            $productOutOfStock = !empty($product['variants']) && array_sum(array_column($product['variants'], 'stock_quantity')) === 0;
            $isBulk = !empty($product['is_bulk_order']);
            $bulkProgress = $isBulk ? Shop::getBulkOrderProgress($product['id']) : 0;
            $bulkGoal     = $isBulk ? (int) $product['bulk_min_goal'] : 0;
            $allImages    = $product['images'] ?? [];
            if (!empty($product['image_path']) && empty($allImages)) {
                $allImages = [['image_path' => $product['image_path']]];
            }
            $sliderId = 'slider-grid-' . $product['id'];
            $productUrl = asset('pages/shop/view.php?id=' . $product['id']);
        ?>
        <div class="bg-white dark:bg-gray-800 rounded-2xl overflow-hidden flex flex-col group hover:shadow-xl transition-all duration-300 border border-gray-100 dark:border-gray-700 hover:border-blue-200 dark:hover:border-blue-800 <?php echo $productOutOfStock ? 'opacity-70' : ''; ?>"
             data-instock="<?php echo $productOutOfStock ? '0' : '1'; ?>"
             data-gender="<?php echo htmlspecialchars($product['gender'] ?? ''); ?>"
             data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
             data-name="<?php echo htmlspecialchars(mb_strtolower($product['name'])); ?>">
            <!-- Product image (square) -->
            <a href="<?php echo $productUrl; ?>" class="block relative aspect-square overflow-hidden bg-gray-50 dark:bg-gray-900" id="<?php echo $sliderId; ?>">
                <?php if (!empty($allImages)): ?>
                    <?php foreach ($allImages as $idx => $img): ?>
                    <img src="<?php echo asset($img['image_path']); ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         data-slide="<?php echo $idx; ?>"
                         loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>"
                         class="slider-img absolute inset-0 w-full h-full object-cover transition-all duration-500 group-hover:scale-105 <?php echo $idx === 0 ? 'opacity-100' : 'opacity-0'; ?>">
                    <?php endforeach; ?>
                    <?php if (count($allImages) > 1): ?>
                    <button type="button" onclick="slideImg('<?php echo $sliderId; ?>',-1)" aria-label="Vorheriges Bild"
                            class="absolute left-2 top-1/2 -translate-y-1/2 bg-white/80 dark:bg-gray-800/80 hover:bg-white dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full w-8 h-8 flex items-center justify-center z-10 shadow transition-all opacity-0 group-hover:opacity-100">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <button type="button" onclick="slideImg('<?php echo $sliderId; ?>',1)" aria-label="Nächstes Bild"
                            class="absolute right-2 top-1/2 -translate-y-1/2 bg-white/80 dark:bg-gray-800/80 hover:bg-white dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full w-8 h-8 flex items-center justify-center z-10 shadow transition-all opacity-0 group-hover:opacity-100">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-800 dark:to-gray-900">
                    <i class="fas fa-box text-gray-300 dark:text-gray-600 text-5xl"></i>
                </div>
                <?php endif; ?>
                <!-- Badges -->
                <div class="absolute top-2.5 left-2.5 flex flex-col gap-1.5 z-10">
                    <?php if ($productOutOfStock): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-900/80 backdrop-blur-sm text-white text-xs font-bold rounded-full uppercase tracking-wide">
                        <i class="fas fa-ban text-xs"></i> Ausverkauft
                    </span>
                    <?php endif; ?>
                    <?php if ($isBulk): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-600/90 backdrop-blur-sm text-white text-xs font-bold rounded-full">
                        <i class="fas fa-layer-group text-xs"></i> Sammelbestellung
                    </span>
                    <?php endif; ?>
                </div>
                <!-- Quick view hint on hover -->
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 dark:group-hover:bg-black/20 transition-all duration-300 flex items-end justify-center pb-4 opacity-0 group-hover:opacity-100">
                    <span class="px-3 py-1.5 bg-white/90 dark:bg-gray-800/90 backdrop-blur-sm text-gray-800 dark:text-gray-100 text-xs font-semibold rounded-full shadow">
                        <i class="fas fa-eye mr-1.5 text-blue-600"></i>Details ansehen
                    </span>
                </div>
            </a>

            <!-- Product info -->
            <div class="p-4 flex flex-col flex-1">
                <h3 class="font-bold text-gray-900 dark:text-gray-100 text-sm mb-1 line-clamp-2 leading-snug">
                    <a href="<?php echo $productUrl; ?>" class="no-underline text-inherit hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </a>
                </h3>
                <p class="text-blue-600 dark:text-blue-400 font-bold text-base mb-2 flex-1">
                    <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                </p>

                <?php
                $namedVariants = array_values(array_filter($product['variants'] ?? [], fn($v) => $v['type'] !== '' || $v['value'] !== ''));
                $gridTotalStock = count($namedVariants) > 0 ? array_sum(array_column($namedVariants, 'stock_quantity')) : null;
                if (!$productOutOfStock && $gridTotalStock !== null && $gridTotalStock <= 5 && $gridTotalStock > 0): ?>
                <p class="text-xs font-semibold text-amber-600 dark:text-amber-400 mb-2 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse flex-shrink-0"></span>
                    Nur noch <?php echo $gridTotalStock; ?> Stück verfügbar
                </p>
                <?php elseif (!$productOutOfStock && $gridTotalStock !== null && $gridTotalStock > 5): ?>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mb-2 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                    <?php echo $gridTotalStock; ?> Stück auf Lager
                </p>
                <?php endif; ?>

                <?php if ($isBulk && $bulkGoal > 0): ?>
                <!-- Bulk order progress bar -->
                <div class="mb-3">
                    <?php $pct = min(100, (int) round($bulkProgress / $bulkGoal * 100)); ?>
                    <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5 mb-1 overflow-hidden">
                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-1.5 rounded-full transition-all" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500"><?php echo $bulkProgress; ?>/<?php echo $bulkGoal; ?> (<?php echo $pct; ?>%)</p>
                    <?php if (!empty($product['bulk_end_date'])): ?>
                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        <i class="fas fa-clock mr-1"></i>Bis: <?php echo date('d.m.Y', strtotime($product['bulk_end_date'])); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($productOutOfStock): ?>
                <button type="button" disabled
                        class="w-full py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-sm font-semibold rounded-xl cursor-not-allowed select-none">
                    <i class="fas fa-ban mr-1"></i>Ausverkauft
                </button>
                <?php elseif (empty($product['variants']) && empty($product['variants_csv'])): ?>
                <form method="POST" action="<?php echo asset('pages/shop/index.php'); ?>">
                    <input type="hidden" name="post_action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit"
                            class="w-full py-2.5 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-semibold rounded-xl hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors flex items-center justify-center gap-1.5">
                        <i class="fas fa-cart-plus text-sm"></i>In den Warenkorb
                    </button>
                </form>
                <?php else: ?>
                <a href="<?php echo $productUrl; ?>"
                   class="w-full block text-center py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white text-sm font-semibold rounded-xl transition-all no-underline flex items-center justify-center gap-1.5 shadow-sm hover:shadow-md">
                    <i class="fas fa-cart-plus text-sm"></i>Auswählen &amp; kaufen
                </a>
                <?php endif; ?>
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
            <!-- Image / Slider -->
            <?php
                $detailImages = $currentProduct['images'] ?? [];
                if (!empty($currentProduct['image_path']) && empty($detailImages)) {
                    $detailImages = [['image_path' => $currentProduct['image_path']]];
                }
                $detailSliderId = 'slider-detail-' . $currentProduct['id'];
            ?>
            <div>
                <?php if (!empty($detailImages)): ?>
                <div class="relative rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700" style="aspect-ratio:4/3" id="<?php echo $detailSliderId; ?>">
                    <?php foreach ($detailImages as $idx => $img): ?>
                    <img src="<?php echo asset($img['image_path']); ?>"
                         alt="<?php echo htmlspecialchars($currentProduct['name']); ?>"
                         data-slide="<?php echo $idx; ?>"
                         loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>"
                         class="slider-img absolute inset-0 w-full h-full object-cover transition-opacity duration-300 <?php echo $idx === 0 ? 'opacity-100' : 'opacity-0'; ?>">
                    <?php endforeach; ?>
                    <?php if (count($detailImages) > 1): ?>
                    <button type="button" onclick="slideImg('<?php echo $detailSliderId; ?>',-1)" aria-label="Vorheriges Bild"
                            class="absolute left-2 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/60 text-white rounded-full w-9 h-9 flex items-center justify-center z-10 transition-colors">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button type="button" onclick="slideImg('<?php echo $detailSliderId; ?>',1)" aria-label="Nächstes Bild"
                            class="absolute right-2 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/60 text-white rounded-full w-9 h-9 flex items-center justify-center z-10 transition-colors">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <!-- Dot indicators -->
                    <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex gap-1.5">
                        <?php foreach ($detailImages as $idx => $img): ?>
                        <span data-dot="<?php echo $idx; ?>"
                              class="slider-dot w-2 h-2 rounded-full transition-all <?php echo $idx === 0 ? 'bg-white' : 'bg-white/50'; ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (count($detailImages) > 1): ?>
                <!-- Thumbnail strip -->
                <div class="flex gap-2 mt-3 overflow-x-auto pb-1" id="<?php echo $detailSliderId; ?>-thumbs">
                    <?php foreach ($detailImages as $thumbIdx => $thumbImgData): ?>
                    <button type="button" onclick="goToSlide('<?php echo $detailSliderId; ?>', <?php echo $thumbIdx; ?>)"
                            class="thumb-btn flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden border-2 transition-all <?php echo $thumbIdx === 0 ? 'border-blue-500' : 'border-gray-200 dark:border-gray-600 opacity-70 hover:opacity-100'; ?>">
                        <img src="<?php echo asset($thumbImgData['image_path']); ?>" alt="" loading="<?php echo $thumbIdx === 0 ? 'eager' : 'lazy'; ?>" class="w-full h-full object-cover pointer-events-none">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
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

                <?php if (!empty($currentProduct['is_bulk_order'])): ?>
                <!-- Bulk order progress & deadline -->
                <div class="mb-6 p-4 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-700">
                    <p class="text-sm font-semibold text-purple-700 dark:text-purple-300 mb-2">
                        <i class="fas fa-layer-group mr-1"></i>Sammelbestellung
                    </p>
                    <?php if (!empty($currentProduct['bulk_min_goal'])): ?>
                    <?php
                        $dBulkProgress = Shop::getBulkOrderProgress($currentProduct['id']);
                        $dBulkGoal     = (int) $currentProduct['bulk_min_goal'];
                        $dPct          = $dBulkGoal > 0 ? min(100, (int) round($dBulkProgress / $dBulkGoal * 100)) : 0;
                    ?>
                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300 mb-1">
                        <span><?php echo $dBulkProgress; ?>/<?php echo $dBulkGoal; ?> bestellt, damit produziert wird</span>
                        <span><?php echo $dPct; ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 mb-2">
                        <div class="bg-purple-600 h-3 rounded-full transition-all" style="width:<?php echo $dPct; ?>%"></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($currentProduct['bulk_end_date'])): ?>
                    <p class="text-sm text-amber-600 dark:text-amber-400">
                        <i class="fas fa-clock mr-1"></i>Bestellbar bis: <strong><?php echo date('d.m.Y', strtotime($currentProduct['bulk_end_date'])); ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
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

                    <!-- Simple text variants (comma-separated from variants_csv field) -->
                    <?php if (!empty($currentProduct['variants_csv'])): ?>
                    <?php $variantOptions = array_filter(array_map('trim', explode(',', $currentProduct['variants_csv']))); ?>
                    <div class="mb-4">
                        <label for="selected-variant" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Bitte Größe/Variante wählen <span class="text-red-500">*</span>
                        </label>
                        <select name="selected_variant" id="selected-variant" required
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                            <option value="">– Bitte wählen –</option>
                            <?php foreach ($variantOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Quantity -->
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Anzahl</label>
                        <div class="inline-flex items-center rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600">
                            <button type="button" onclick="adjustQty(-1)"
                                    class="px-3 h-10 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-xl font-bold select-none">−</button>
                            <input type="number" name="quantity" id="qty-input" value="1" min="1" max="99"
                                   class="w-14 h-10 text-center bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border-x border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-1 focus:ring-inset focus:ring-blue-500">
                            <button type="button" onclick="adjustQty(1)"
                                    class="px-3 h-10 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-xl font-bold select-none">+</button>
                        </div>
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
                <table class="w-full text-sm card-table">
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
                            <td class="py-4 font-medium text-gray-800 dark:text-gray-100" data-label="Produkt">
                                <?php echo htmlspecialchars($item['product_name']); ?>
                            </td>
                            <td class="py-4 text-center text-gray-500 dark:text-gray-400" data-label="Variante">
                                <?php
                                    $displayVariant = '';
                                    if (!empty($item['variant_name'])) {
                                        $displayVariant = $item['variant_name'];
                                    } elseif (!empty($item['selected_variant'])) {
                                        $displayVariant = $item['selected_variant'];
                                    }
                                ?>
                                <?php echo $displayVariant !== '' ? htmlspecialchars($displayVariant) : '–'; ?>
                            </td>
                            <td class="py-4 text-center text-gray-700 dark:text-gray-300" data-label="Preis">
                                <?php echo number_format($item['price'], 2, ',', '.'); ?> €
                            </td>
                            <td class="py-4 text-center" data-label="Anzahl">
                                <input type="number"
                                       name="quantities[<?php echo htmlspecialchars($key); ?>]"
                                       value="<?php echo $item['quantity']; ?>"
                                       min="0" max="99"
                                       class="w-16 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-center bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100">
                            </td>
                            <td class="py-4 text-right font-semibold text-gray-800 dark:text-gray-100" data-label="Gesamt">
                                <?php echo number_format($item['price'] * $item['quantity'], 2, ',', '.'); ?> €
                            </td>
                            <td class="py-4 text-right" data-label="Entfernen">
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
                    <i class="fas fa-credit-card mr-2 text-blue-600"></i>Zahlungsmethode &amp; Versand
                </h2>
                <form method="POST" id="checkout-form">
                    <input type="hidden" name="post_action" value="checkout">

                    <!-- Shipping method selection -->
                    <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-3">Liefermethode</h3>
                    <div class="mb-6 space-y-3">
                        <label class="flex items-center gap-4 p-4 border-2 rounded-xl cursor-pointer transition-all
                                      border-gray-200 dark:border-gray-700 hover:border-green-400
                                      has-[:checked]:border-green-500 has-[:checked]:bg-green-50 dark:has-[:checked]:bg-green-900/20">
                            <input type="radio" name="shipping_method" value="pickup" checked id="shipping-pickup" class="sr-only peer">
                            <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-building text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-800 dark:text-gray-100">Selbstabholung im MiMe</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">kostenlos, nach Benachrichtigung</p>
                            </div>
                            <span class="text-green-600 dark:text-green-400 font-bold text-sm">0,00 €</span>
                        </label>

                        <label class="flex items-center gap-4 p-4 border-2 rounded-xl cursor-pointer transition-all
                                      border-gray-200 dark:border-gray-700 hover:border-blue-400
                                      has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/20">
                            <input type="radio" name="shipping_method" value="mail" id="shipping-mail" class="sr-only peer">
                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-truck text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-800 dark:text-gray-100">Postversand</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">zzgl. 7,99 €</p>
                            </div>
                            <span class="text-blue-600 dark:text-blue-400 font-bold text-sm">7,99 €</span>
                        </label>
                    </div>

                    <input type="hidden" name="selected_delivery_method" id="selected-delivery-method" value="pickup">

                    <!-- Shipping address (shown when mail is selected) -->
                    <div id="shipping-address-field" class="hidden mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            Lieferadresse <span class="text-red-500">*</span>
                        </label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            <i class="fas fa-info-circle mr-1"></i>Tippe deine Adresse ein – wir machen dir passende Vorschläge und berechnen die Versandkosten automatisch.
                        </p>
                        <div class="relative mb-4">
                            <input type="text" id="shipping-address-search"
                                   autocomplete="off"
                                   aria-label="Lieferadresse suchen"
                                   aria-expanded="false"
                                   aria-controls="shipping-address-suggestions"
                                   aria-autocomplete="list"
                                   placeholder="Straße, Hausnummer, PLZ, Ort …"
                                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow pr-10">
                            <span id="shipping-address-spinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                            <ul id="shipping-address-suggestions"
                                role="listbox"
                                class="hidden absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-60 overflow-y-auto text-sm">
                            </ul>
                        </div>
                        <!-- Hidden field submitted with the form -->
                        <input type="hidden" name="shipping_address" id="shipping-address-input">
                        <div id="shipping-address-preview" class="hidden mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg text-sm text-green-800 dark:text-green-300">
                            <i class="fas fa-check-circle mr-1"></i>
                            <span id="shipping-address-preview-text"></span>
                            <button type="button" id="shipping-address-clear" class="ml-2 text-gray-500 hover:text-red-500 text-xs underline">Ändern</button>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1" for="shipping-country-select">
                                Versandland <span class="text-red-500">*</span>
                            </label>
                            <select name="shipping_country" id="shipping-country-select"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                <option value="DE" selected>Deutschland</option>
                                <option value="AT">Österreich</option>
                                <option value="CH">Schweiz</option>
                                <option value="BE">Belgien</option>
                                <option value="BG">Bulgarien</option>
                                <option value="CY">Zypern</option>
                                <option value="CZ">Tschechien</option>
                                <option value="DK">Dänemark</option>
                                <option value="EE">Estland</option>
                                <option value="ES">Spanien</option>
                                <option value="FI">Finnland</option>
                                <option value="FR">Frankreich</option>
                                <option value="GR">Griechenland</option>
                                <option value="HR">Kroatien</option>
                                <option value="HU">Ungarn</option>
                                <option value="IE">Irland</option>
                                <option value="IT">Italien</option>
                                <option value="LT">Litauen</option>
                                <option value="LU">Luxemburg</option>
                                <option value="LV">Lettland</option>
                                <option value="MT">Malta</option>
                                <option value="NL">Niederlande</option>
                                <option value="PL">Polen</option>
                                <option value="PT">Portugal</option>
                                <option value="RO">Rumänien</option>
                                <option value="SE">Schweden</option>
                                <option value="SI">Slowenien</option>
                                <option value="SK">Slowakei</option>
                            </select>
                        </div>
                    </div>

                    <!-- Payment method selection -->
                    <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-3 mt-6">Zahlungsmethode</h3>
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
                               id="bank-transfer-label">
                            <input type="radio" name="payment_method" value="bank_transfer" id="bank-transfer-radio" class="sr-only peer">
                            <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-university text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800 dark:text-gray-100">Überweisung</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Per Banküberweisung bezahlen – du erhältst die Details per E-Mail</p>
                            </div>
                        </label>
                    </div>

                    <button type="submit" form="checkout-form" id="checkout-submit-btn"
                            class="w-full py-4 bg-gradient-to-r from-purple-600 to-purple-700 text-white rounded-xl hover:from-purple-700 hover:to-purple-800 font-bold text-lg transition-all shadow-lg">
                        <i class="fas fa-lock mr-2"></i>
                        Kostenpflichtig bestellen – <span id="checkout-total-display"><?php echo number_format($cartTotalAmt, 2, ',', '.'); ?></span> €
                    </button>

                    <!-- PayPal JS SDK button container (shown only for PayPal, hidden for bank transfer) -->
                    <div id="paypal-notice" class="hidden mb-4 p-4 rounded-lg border"></div>
                    <div id="paypal-button-container" class="mt-2"></div>
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
                            <?php elseif (!empty($item['selected_variant'])): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($item['selected_variant']); ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400">× <?php echo $item['quantity']; ?></p>
                        </div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <?php echo number_format($item['price'] * $item['quantity'], 2, ',', '.'); ?> €
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between text-sm text-gray-600 dark:text-gray-300">
                    <span>Versand</span>
                    <span id="summary-shipping-cost">0,00 €</span>
                </div>
                <div class="mt-3 pt-3 border-t-2 border-gray-300 dark:border-gray-600 flex justify-between items-center">
                    <span class="font-bold text-gray-800 dark:text-gray-100">Gesamt</span>
                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400">
                        <span id="summary-total"><?php echo number_format($cartTotalAmt, 2, ',', '.'); ?></span> €
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<script>
// ── Image Slider ──────────────────────────────────────────────────────────────
function updateThumbs(sliderId, activeIndex) {
    var thumbsContainer = document.getElementById(sliderId + '-thumbs');
    if (!thumbsContainer) return;
    thumbsContainer.querySelectorAll('.thumb-btn').forEach(function(btn, i) {
        btn.classList.toggle('border-blue-500', i === activeIndex);
        btn.classList.toggle('border-gray-200', i !== activeIndex);
        btn.classList.toggle('dark:border-gray-600', i !== activeIndex);
        btn.classList.toggle('opacity-70', i !== activeIndex);
    });
}

function goToSlide(sliderId, index) {
    var container = document.getElementById(sliderId);
    if (!container) return;
    var imgs = container.querySelectorAll('.slider-img');
    var current = parseInt(container.dataset.slideIndex || '0', 10);
    if (current === index) return;
    imgs[current].classList.add('opacity-0');
    imgs[current].classList.remove('opacity-100');
    imgs[index].classList.remove('opacity-0');
    imgs[index].classList.add('opacity-100');
    container.dataset.slideIndex = index;
    var dots = container.querySelectorAll('.slider-dot');
    dots.forEach(function(dot, i) {
        dot.className = 'slider-dot w-2 h-2 rounded-full transition-all ' + (i === index ? 'bg-white' : 'bg-white/50');
    });
    updateThumbs(sliderId, index);
}

function slideImg(sliderId, direction) {
    var container = document.getElementById(sliderId);
    if (!container) return;
    var imgs = container.querySelectorAll('.slider-img');
    if (imgs.length < 2) return;
    var current = parseInt(container.dataset.slideIndex || '0', 10);
    imgs[current].classList.add('opacity-0');
    imgs[current].classList.remove('opacity-100');
    var next = (current + direction + imgs.length) % imgs.length;
    imgs[next].classList.remove('opacity-0');
    imgs[next].classList.add('opacity-100');
    container.dataset.slideIndex = next;
    // Update dot indicators
    var dots = container.querySelectorAll('.slider-dot');
    dots.forEach(function(dot, i) {
        dot.className = 'slider-dot w-2 h-2 rounded-full transition-all ' + (i === next ? 'bg-white' : 'bg-white/50');
    });
    // Update thumbnail strip
    updateThumbs(sliderId, next);
}

// ── Quantity stepper ──────────────────────────────────────────────────────────
function adjustQty(delta) {
    var input = document.getElementById('qty-input');
    if (!input) return;
    var val = (parseInt(input.value, 10) || 1) + delta;
    input.value = Math.max(1, Math.min(99, val));
}

// ── Filter pills + search + toggle ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var grid = document.getElementById('product-grid');
    if (!grid) return;

    var activeStock    = 'all';
    var activeGender   = 'all';
    var activeCategory = 'all';
    var activeSearch   = '';
    var totalProducts  = grid.querySelectorAll('[data-instock]').length;

    var resultsCount   = document.getElementById('results-count');
    var resetBtn       = document.getElementById('reset-filters');
    var activeCountBadge = document.getElementById('filter-active-count');

    function isFiltered() {
        return activeStock !== 'all' || activeGender !== 'all' || activeCategory !== 'all' || activeSearch !== '';
    }

    function updateUI(visibleCount) {
        if (resultsCount) resultsCount.textContent = visibleCount;
        if (resetBtn) resetBtn.classList.toggle('hidden', !isFiltered());
        var activeCount = (activeStock !== 'all' ? 1 : 0) + (activeGender !== 'all' ? 1 : 0)
                        + (activeCategory !== 'all' ? 1 : 0) + (activeSearch !== '' ? 1 : 0);
        if (activeCountBadge) {
            activeCountBadge.textContent = activeCount;
            activeCountBadge.classList.toggle('hidden', activeCount === 0);
        }
    }

    function applyFilters() {
        var visible = 0;
        grid.querySelectorAll('[data-instock]').forEach(function(card) {
            var inStock    = card.dataset.instock   === '1';
            var cardGender = card.dataset.gender    || '';
            var cardCat    = card.dataset.category  || '';
            var cardName   = card.dataset.name      || '';

            var stockOk  = activeStock    === 'all'
                        || (activeStock   === 'available' && inStock)
                        || (activeStock   === 'soldout'   && !inStock);
            var genderOk = activeGender   === 'all' || cardGender  === activeGender;
            var catOk    = activeCategory === 'all' || cardCat      === activeCategory;
            var searchOk = activeSearch   === ''    || cardName.includes(activeSearch);

            var show = stockOk && genderOk && catOk && searchOk;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        updateUI(visible);
    }

    var PILL_ACTIVE   = 'filter-pill fpill fpill-avail fpill-active';
    var PILL_INACTIVE = 'filter-pill fpill fpill-avail';
    var PILL_ALL_ACTIVE   = 'filter-pill fpill fpill-all fpill-active';
    var PILL_ALL_INACTIVE = 'filter-pill fpill fpill-all';
    var PILL_SOLD_ACTIVE   = 'filter-pill fpill fpill-sold fpill-active';
    var PILL_SOLD_INACTIVE = 'filter-pill fpill fpill-sold';
    var GENDER_ACTIVE   = 'gender-pill fpill fpill-gender fpill-active';
    var GENDER_INACTIVE = 'gender-pill fpill fpill-gender';
    var GENDER_ALL_ACTIVE   = 'gender-pill fpill fpill-all fpill-active';
    var GENDER_ALL_INACTIVE = 'gender-pill fpill fpill-all';
    var CAT_ACTIVE   = 'category-pill fpill fpill-cat fpill-active';
    var CAT_INACTIVE = 'category-pill fpill fpill-cat';
    var CAT_ALL_ACTIVE   = 'category-pill fpill fpill-all fpill-active';
    var CAT_ALL_INACTIVE = 'category-pill fpill fpill-all';

    document.querySelectorAll('.filter-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            activeStock = this.dataset.filter;
            document.querySelectorAll('.filter-pill').forEach(function(p) {
                var isActive = p.dataset.filter === activeStock;
                if (p.dataset.filter === 'all')     p.className = isActive ? PILL_ALL_ACTIVE  : PILL_ALL_INACTIVE;
                else if (p.dataset.filter === 'soldout') p.className = isActive ? PILL_SOLD_ACTIVE : PILL_SOLD_INACTIVE;
                else                                p.className = isActive ? PILL_ACTIVE     : PILL_INACTIVE;
            });
            applyFilters();
        });
    });

    document.querySelectorAll('.gender-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            activeGender = this.dataset.gender;
            document.querySelectorAll('.gender-pill').forEach(function(p) {
                var isActive = p.dataset.gender === activeGender;
                p.className = (p.dataset.gender === 'all') ? (isActive ? GENDER_ALL_ACTIVE : GENDER_ALL_INACTIVE) : (isActive ? GENDER_ACTIVE : GENDER_INACTIVE);
            });
            applyFilters();
        });
    });

    document.querySelectorAll('.category-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            activeCategory = this.dataset.category;
            document.querySelectorAll('.category-pill').forEach(function(p) {
                var isActive = p.dataset.category === activeCategory;
                p.className = (p.dataset.category === 'all') ? (isActive ? CAT_ALL_ACTIVE : CAT_ALL_INACTIVE) : (isActive ? CAT_ACTIVE : CAT_INACTIVE);
            });
            applyFilters();
        });
    });

    // Search input
    var searchInput = document.getElementById('search-input');
    var searchClear = document.getElementById('search-clear');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            activeSearch = this.value.trim().toLowerCase();
            if (searchClear) searchClear.classList.toggle('hidden', activeSearch === '');
            applyFilters();
        });
    }
    if (searchClear) {
        searchClear.addEventListener('click', function() {
            if (searchInput) { searchInput.value = ''; }
            activeSearch = '';
            this.classList.add('hidden');
            applyFilters();
        });
    }

    // Reset all filters
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            activeStock    = 'all';
            activeGender   = 'all';
            activeCategory = 'all';
            activeSearch   = '';
            if (searchInput) searchInput.value = '';
            if (searchClear) searchClear.classList.add('hidden');
            document.querySelectorAll('.filter-pill').forEach(function(p) {
                var isAll = p.dataset.filter === 'all';
                var isSold = p.dataset.filter === 'soldout';
                p.className = isAll ? PILL_ALL_ACTIVE : (isSold ? PILL_SOLD_INACTIVE : PILL_INACTIVE);
            });
            document.querySelectorAll('.gender-pill').forEach(function(p) {
                p.className = (p.dataset.gender === 'all') ? GENDER_ALL_ACTIVE : GENDER_INACTIVE;
            });
            document.querySelectorAll('.category-pill').forEach(function(p) {
                p.className = (p.dataset.category === 'all') ? CAT_ALL_ACTIVE : CAT_INACTIVE;
            });
            applyFilters();
        });
    }

    // Filter panel toggle
    var filterToggle = document.getElementById('filter-toggle');
    var filterBody   = document.getElementById('filter-body');
    var filterIcon   = document.getElementById('filter-toggle-icon');
    if (filterToggle && filterBody) {
        filterToggle.addEventListener('click', function() {
            var collapsed = filterBody.classList.toggle('hidden');
            if (filterIcon) {
                filterIcon.className = collapsed
                    ? 'fas fa-chevron-down text-xs'
                    : 'fas fa-chevron-up text-xs';
            }
        });
    }

    updateUI(totalProducts);
});

// ── Checkout: loading spinner on submit ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var checkoutForm = document.getElementById('checkout-form');
    var submitBtn    = document.getElementById('checkout-submit-btn');
    if (checkoutForm && submitBtn) {
        checkoutForm.addEventListener('submit', function (e) {
            var methodEl = document.querySelector('input[name="shipping_method"]:checked');
            if (methodEl && methodEl.value === 'mail') {
                var addrHidden = document.getElementById('shipping-address-input');
                if (!addrHidden || addrHidden.value.trim() === '') {
                    e.preventDefault();
                    var searchEl = document.getElementById('shipping-address-search');
                    if (searchEl) {
                        searchEl.focus();
                        searchEl.classList.add('ring-2', 'ring-red-500', 'border-red-500');
                        setTimeout(function() {
                            searchEl.classList.remove('ring-2', 'ring-red-500', 'border-red-500');
                        }, 3000);
                    }
                    return;
                }
            }
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Wird gesendet...';
        });
    }
});

// ── Checkout: shipping method toggle + live total ────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var CART_TOTAL = <?php echo json_encode((float) $cartTotalAmt); ?>;
    var GET_SHIPPING_COST_URL = <?php echo json_encode(asset('api/shop/get_shipping_cost.php')); ?>;

    var shippingRadios      = document.querySelectorAll('input[name="shipping_method"]');
    var countrySelect       = document.getElementById('shipping-country-select');
    var addressField        = document.getElementById('shipping-address-field');
    var addressInput        = document.getElementById('shipping-address-input');   // hidden field
    var summaryShipping     = document.getElementById('summary-shipping-cost');
    var summaryTotal        = document.getElementById('summary-total');
    var checkoutDisplay     = document.getElementById('checkout-total-display');
    var deliveryMethodField = document.getElementById('selected-delivery-method');

    // ── Address autocomplete (Nominatim / OpenStreetMap) ─────────────────────
    var searchInput    = document.getElementById('shipping-address-search');
    var spinner        = document.getElementById('shipping-address-spinner');
    var suggestionList = document.getElementById('shipping-address-suggestions');
    var previewBox     = document.getElementById('shipping-address-preview');
    var previewText    = document.getElementById('shipping-address-preview-text');
    var clearBtn       = document.getElementById('shipping-address-clear');

    // ISO alpha-2 → value mapping for the country <select>
    var countryMap = {
        'DE':'DE','AT':'AT','CH':'CH','BE':'BE','BG':'BG','CY':'CY','CZ':'CZ',
        'DK':'DK','EE':'EE','ES':'ES','FI':'FI','FR':'FR','GR':'GR','HR':'HR',
        'HU':'HU','IE':'IE','IT':'IT','LT':'LT','LU':'LU','LV':'LV','MT':'MT',
        'NL':'NL','PL':'PL','PT':'PT','RO':'RO','SE':'SE','SI':'SI','SK':'SK'
    };

    var nominatimTimer = null;
    var activeIndex    = -1;

    function closeSuggestions() {
        if (suggestionList) {
            suggestionList.innerHTML = '';
            suggestionList.classList.add('hidden');
        }
        if (searchInput) searchInput.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
    }

    function selectAddress(displayName, countryCode) {
        var code = (countryCode || '').toUpperCase();
        // Fill hidden form field
        if (addressInput) addressInput.value = displayName;
        // Show confirmation preview
        if (previewText) previewText.textContent = displayName;
        if (previewBox)  previewBox.classList.remove('hidden');
        if (searchInput) {
            searchInput.value = '';
            searchInput.classList.add('hidden');
        }
        // Sync country selector and trigger live shipping cost
        if (countrySelect && countryMap[code]) {
            countrySelect.value = code;
            countrySelect.dispatchEvent(new Event('change'));
        } else if (countrySelect) {
            countrySelect.dispatchEvent(new Event('change'));
        }
        closeSuggestions();
    }

    function renderSuggestions(results) {
        if (!suggestionList) return;
        suggestionList.innerHTML = '';
        activeIndex = -1;
        if (!results || results.length === 0) {
            suggestionList.classList.add('hidden');
            if (searchInput) searchInput.setAttribute('aria-expanded', 'false');
            return;
        }
        results.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'px-4 py-2 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30 text-gray-800 dark:text-gray-100 border-b border-gray-100 dark:border-gray-700 last:border-0';
            li.setAttribute('role', 'option');
            var icon = '<i class="fas fa-map-marker-alt text-blue-400 mr-2 flex-shrink-0"></i>';
            var addr = item.display_name || '';
            li.innerHTML = '<span class="flex items-start gap-1">' + icon + '<span>' + escapeHtml(addr) + '</span></span>';
            li.addEventListener('mousedown', function(e) {
                e.preventDefault(); // keep focus on input during selection
                var cc = (item.address && item.address.country_code)
                    ? item.address.country_code.toUpperCase()
                    : '';
                selectAddress(addr, cc);
            });
            suggestionList.appendChild(li);
        });
        suggestionList.classList.remove('hidden');
        if (searchInput) searchInput.setAttribute('aria-expanded', 'true');
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function searchNominatim(query) {
        if (!query || query.trim().length < 3) { closeSuggestions(); return; }
        if (spinner) spinner.classList.remove('hidden');
        var url = 'https://nominatim.openstreetmap.org/search'
            + '?format=json&addressdetails=1&limit=5'
            + '&q=' + encodeURIComponent(query.trim());
        fetch(url, {
            headers: {
                'Accept-Language': 'de',
                'User-Agent': 'offera-intranet-shop/1.0'
            }
        })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                renderSuggestions(data);
            })
            .catch(function() {
                closeSuggestions();
                // Show non-intrusive hint that suggestions are unavailable
                if (suggestionList) {
                    suggestionList.innerHTML = '<li class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs italic">'
                        + '<i class="fas fa-exclamation-circle mr-1"></i>Adressvorschläge momentan nicht verfügbar.</li>';
                    suggestionList.classList.remove('hidden');
                    if (searchInput) searchInput.setAttribute('aria-expanded', 'true');
                }
            })
            .finally(function() {
                if (spinner) spinner.classList.add('hidden');
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(nominatimTimer);
            var q = searchInput.value;
            if (q.length < 3) { closeSuggestions(); return; }
            nominatimTimer = setTimeout(function() { searchNominatim(q); }, 400);
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            var items = suggestionList ? suggestionList.querySelectorAll('li[role="option"]') : [];
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
            } else if (e.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                items[activeIndex].dispatchEvent(new MouseEvent('mousedown'));
                return;
            } else if (e.key === 'Escape') {
                closeSuggestions();
                return;
            }
            items.forEach(function(li, i) {
                li.classList.toggle('bg-blue-50', i === activeIndex);
                li.classList.toggle('dark:bg-blue-900/30', i === activeIndex);
            });
        });

        searchInput.addEventListener('blur', function() {
            // Delay closing so mousedown on a suggestion fires before blur hides the list
            setTimeout(closeSuggestions, 200);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (addressInput) addressInput.value = '';
            if (previewBox)   previewBox.classList.add('hidden');
            if (searchInput) {
                searchInput.value = '';
                searchInput.classList.remove('hidden');
                searchInput.focus();
            }
        });
    }
    // ── End address autocomplete ─────────────────────────────────────────────

    function formatMoney(val) {
        return val.toFixed(2).replace('.', ',');
    }

    function applyShippingCost(shippingCost) {
        var grandTotal = CART_TOTAL + shippingCost;
        if (summaryShipping) summaryShipping.textContent = formatMoney(shippingCost) + ' €';
        if (summaryTotal)    summaryTotal.textContent    = formatMoney(grandTotal);
        if (checkoutDisplay) checkoutDisplay.textContent = formatMoney(grandTotal);
    }

    function fetchShippingCost() {
        var country = countrySelect ? countrySelect.value : 'DE';
        var url = GET_SHIPPING_COST_URL + '?country=' + encodeURIComponent(country) + '&cart_total=' + encodeURIComponent(CART_TOTAL);
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    applyShippingCost(data.shipping_cost);
                }
            })
            .catch(function() {
                console.warn('Versandkosten konnten nicht abgerufen werden.');
            });
    }

    function updateTotals() {
        var selected = document.querySelector('input[name="shipping_method"]:checked');
        var isMail = selected && selected.value === 'mail';

        if (deliveryMethodField) deliveryMethodField.value = selected ? selected.value : 'pickup';

        if (addressField) {
            addressField.classList.toggle('hidden', !isMail);
            if (countrySelect) countrySelect.required = isMail;
        }

        if (isMail) {
            fetchShippingCost();
        } else {
            applyShippingCost(0);
        }
    }

    shippingRadios.forEach(function(r) { r.addEventListener('change', updateTotals); });
    if (countrySelect) { countrySelect.addEventListener('change', function() {
        var selected = document.querySelector('input[name="shipping_method"]:checked');
        if (selected && selected.value === 'mail') {
            fetchShippingCost();
        }
    }); }
    updateTotals();

    // Show/hide submit button vs PayPal button based on payment method
    var paymentRadios = document.querySelectorAll('input[name="payment_method"]');

    function togglePaymentUI() {
        var selected = document.querySelector('input[name="payment_method"]:checked');
        var isPayPal = !selected || selected.value === 'paypal';

        var submitBtn        = document.getElementById('checkout-submit-btn');
        var paypalContainer  = document.getElementById('paypal-button-container');
        if (submitBtn)       submitBtn.classList.toggle('hidden', isPayPal);
        if (paypalContainer) paypalContainer.classList.toggle('hidden', !isPayPal);
    }

    paymentRadios.forEach(function(r) { r.addEventListener('change', togglePaymentUI); });
    togglePaymentUI();
});
</script>

<?php if ($action === 'checkout' && !empty($cartItems) && defined('PAYPAL_CLIENT_ID') && PAYPAL_CLIENT_ID !== ''): ?>
<script>
(function () {
    var PAYPAL_CLIENT_ID = <?php echo json_encode(defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : ''); ?>;
    var CREATE_URL  = <?php echo json_encode(asset('api/shop/checkout.php') . '?action=create'); ?>;
    var CAPTURE_URL = <?php echo json_encode(asset('api/shop/checkout.php') . '?action=capture'); ?>;
    var SHOP_URL    = <?php echo json_encode(asset('pages/shop/index.php')); ?>;

    if (!PAYPAL_CLIENT_ID) return;

    // Dynamically load PayPal JS SDK v6
    var sdkScript = document.createElement('script');
    sdkScript.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(PAYPAL_CLIENT_ID) + '&currency=EUR';
    sdkScript.onload = initPayPalButtons;
    document.head.appendChild(sdkScript);

    function showPaypalNotice(msg, type) {
        var el = document.getElementById('paypal-notice');
        if (!el) return;
        var isError = (type === 'error');
        el.className = 'mb-4 p-4 rounded-lg border ' + (isError
            ? 'bg-red-100 dark:bg-red-900 border-red-400 text-red-700 dark:text-red-300'
            : 'bg-yellow-100 dark:bg-yellow-900 border-yellow-400 text-yellow-700 dark:text-yellow-300');
        el.innerHTML = '<i class="fas fa-' + (isError ? 'exclamation-circle' : 'info-circle') + ' mr-2"></i>'
            + msg;
        el.classList.remove('hidden');
    }

    function hidePaypalNotice() {
        var el = document.getElementById('paypal-notice');
        if (el) el.classList.add('hidden');
    }

    function initPayPalButtons() {
        var buttonsConfig = {
            createOrder: function () {
                hidePaypalNotice();
                var shippingMethodEl  = document.querySelector('input[name="shipping_method"]:checked');
                var shippingAddressEl = document.getElementById('shipping-address-input');
                return fetch(CREATE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        payment_method:   'paypal',
                        shipping_method:  shippingMethodEl  ? shippingMethodEl.value  : 'pickup',
                        shipping_country: (document.getElementById('shipping-country-select') || {value: 'DE'}).value,
                        shipping_address: shippingAddressEl ? shippingAddressEl.value : ''
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) return data.paypal_order_id;
                    throw new Error(data.message || 'Fehler beim Erstellen der Bestellung.');
                });
            },

            onApprove: function (data) {
                var container = document.getElementById('paypal-button-container');
                if (container) {
                    container.innerHTML = '<div class="text-center py-4">'
                        + '<i class="fas fa-spinner fa-spin text-2xl text-blue-500"></i>'
                        + '</div>';
                }
                return fetch(CAPTURE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ paypal_order_id: data.orderID })
                })
                .then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result.success) {
                        var grid = document.querySelector('.grid.grid-cols-1.lg\\:grid-cols-3');
                        if (grid) {
                            grid.innerHTML = '<div class="col-span-full text-center py-16">'
                                + '<div class="inline-flex flex-col items-center gap-4">'
                                + '<div class="w-20 h-20 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center">'
                                + '<i class="fas fa-check text-green-500 text-4xl"></i></div>'
                                + '<h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Zahlung erfolgreich!</h2>'
                                + '<p class="text-gray-600 dark:text-gray-300">Bestellung #' + result.order_id
                                + ' wurde abgeschlossen. Du erhältst eine Bestätigungs-E-Mail.</p>'
                                + '<a href="' + SHOP_URL + '" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 font-medium no-underline">'
                                + '<i class="fas fa-arrow-left mr-2"></i>Zurück zum Shop</a>'
                                + '</div></div>';
                        }
                    } else {
                        // Close the current instance, clear the container, then re-render
                        if (renderedButtons) renderedButtons.close();
                        if (container) container.innerHTML = '';
                        renderedButtons = paypal.Buttons(buttonsConfig);
                        renderedButtons.render('#paypal-button-container');
                        showPaypalNotice(result.message || 'Zahlung fehlgeschlagen.', 'error');
                    }
                });
            },

            onCancel: function () {
                showPaypalNotice('Zahlung abgebrochen. Du kannst es jederzeit erneut versuchen.', 'info');
            },

            onError: function (err) {
                showPaypalNotice('Fehler bei der PayPal-Zahlung. Bitte versuche es erneut.', 'error');
                console.error('PayPal Fehler:', err);
            }
        };

        var renderedButtons = paypal.Buttons(buttonsConfig);
        renderedButtons.render('#paypal-button-container');
    }
}());
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
