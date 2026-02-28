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
        $shippingCost    = ($shippingMethod === 'mail') ? 7.99 : 0.00;
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

    <!-- ── Availability filter pills ── -->
    <div class="mb-6 flex flex-wrap gap-2" id="filter-bar">
        <button type="button" data-filter="all"
                class="filter-pill px-4 py-1.5 rounded-full text-sm font-medium transition-all bg-purple-600 text-white shadow-sm">
            Alle
        </button>
        <button type="button" data-filter="available"
                class="filter-pill px-4 py-1.5 rounded-full text-sm font-medium transition-all bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-purple-600 hover:text-white">
            Verfügbar
        </button>
        <button type="button" data-filter="soldout"
                class="filter-pill px-4 py-1.5 rounded-full text-sm font-medium transition-all bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-purple-600 hover:text-white">
            Ausverkauft
        </button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-8" id="product-grid">
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
        ?>
        <div class="bg-white rounded-lg overflow-hidden flex flex-col group hover:shadow-lg transition-shadow duration-300 <?php echo $productOutOfStock ? 'opacity-60' : ''; ?>"
             data-instock="<?php echo $productOutOfStock ? '0' : '1'; ?>">
            <!-- Product image (square) -->
            <div class="relative aspect-square overflow-hidden bg-gray-100" id="<?php echo $sliderId; ?>">
                <?php if (!empty($allImages)): ?>
                    <?php foreach ($allImages as $idx => $img): ?>
                    <img src="<?php echo asset($img['image_path']); ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         data-slide="<?php echo $idx; ?>"
                         class="slider-img absolute inset-0 w-full h-full object-cover transition-all duration-500 group-hover:scale-105 <?php echo $idx === 0 ? 'opacity-100' : 'opacity-0'; ?>">
                    <?php endforeach; ?>
                    <?php if (count($allImages) > 1): ?>
                    <button type="button" onclick="slideImg('<?php echo $sliderId; ?>',-1)" aria-label="Vorheriges Bild"
                            class="absolute left-1 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/60 text-white rounded-full w-7 h-7 flex items-center justify-center z-10 transition-colors">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <button type="button" onclick="slideImg('<?php echo $sliderId; ?>',1)" aria-label="Nächstes Bild"
                            class="absolute right-1 top-1/2 -translate-y-1/2 bg-black/40 hover:bg-black/60 text-white rounded-full w-7 h-7 flex items-center justify-center z-10 transition-colors">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                <div class="absolute inset-0 flex items-center justify-center">
                    <i class="fas fa-box text-gray-300 dark:text-gray-600 text-5xl"></i>
                </div>
                <?php endif; ?>
                <?php if ($productOutOfStock): ?>
                <span class="absolute top-3 left-3 px-3 py-1 bg-gray-800 bg-opacity-75 text-white text-xs font-bold rounded-full uppercase tracking-wide">
                    Ausverkauft
                </span>
                <?php endif; ?>
                <?php if ($isBulk): ?>
                <span class="absolute top-3 right-3 px-2 py-0.5 bg-purple-600 bg-opacity-90 text-white text-xs font-bold rounded-full">
                    Sammelbestellung
                </span>
                <?php endif; ?>
            </div>

            <!-- Product info -->
            <div class="p-4 flex flex-col flex-1">
                <h3 class="font-bold text-gray-900 dark:text-gray-100 text-base mb-1 line-clamp-2">
                    <a href="<?php echo asset('pages/shop/index.php?action=detail&product_id=' . $product['id']); ?>" class="no-underline text-inherit hover:underline">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </a>
                </h3>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-3 flex-1">
                    <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                </p>

                <?php if ($isBulk && $bulkGoal > 0): ?>
                <!-- Bulk order progress bar -->
                <div class="mb-3">
                    <?php $pct = min(100, (int) round($bulkProgress / $bulkGoal * 100)); ?>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 mb-1">
                        <div class="bg-purple-500 h-1.5 rounded-full transition-all" style="width:<?php echo $pct; ?>%"></div>
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
                        class="w-full py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-sm font-semibold rounded-lg cursor-not-allowed select-none">
                    <i class="fas fa-ban mr-1"></i>Ausverkauft
                </button>
                <?php elseif (empty($product['variants']) && empty($product['variants_csv'])): ?>
                <form method="POST" action="<?php echo asset('pages/shop/index.php'); ?>">
                    <input type="hidden" name="post_action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit"
                            class="w-full py-2.5 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-semibold rounded-lg hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors">
                        <i class="fas fa-cart-plus mr-1"></i>In den Warenkorb
                    </button>
                </form>
                <?php else: ?>
                <a href="<?php echo asset('pages/shop/index.php?action=detail&product_id=' . $product['id']); ?>"
                   class="w-full block text-center py-2.5 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-semibold rounded-lg hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors no-underline">
                    <i class="fas fa-cart-plus mr-1"></i>In den Warenkorb
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
                        <img src="<?php echo asset($thumbImgData['image_path']); ?>" alt="" class="w-full h-full object-cover pointer-events-none">
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
                        <textarea name="shipping_address" id="shipping-address-input" rows="3"
                                  placeholder="Straße und Hausnummer, PLZ Ort"
                                  class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow resize-none"></textarea>
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

// ── Filter pills ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var pills = document.querySelectorAll('.filter-pill');
    var grid  = document.getElementById('product-grid');

    if (pills.length && grid) {
        pills.forEach(function(pill) {
            pill.addEventListener('click', function() {
                pills.forEach(function(p) {
                    p.dataset.active = '0';
                    p.className = 'filter-pill px-4 py-1.5 rounded-full text-sm font-medium transition-all bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-purple-600 hover:text-white';
                });
                this.dataset.active = '1';
                this.className = 'filter-pill px-4 py-1.5 rounded-full text-sm font-medium transition-all bg-purple-600 text-white shadow-sm';

                var filter = this.dataset.filter;
                grid.querySelectorAll('[data-instock]').forEach(function(card) {
                    var inStock = card.dataset.instock === '1';
                    if (filter === 'all') {
                        card.style.display = '';
                    } else if (filter === 'available') {
                        card.style.display = inStock ? '' : 'none';
                    } else if (filter === 'soldout') {
                        card.style.display = inStock ? 'none' : '';
                    }
                });
            });
        });
    }
});

// ── Checkout: shipping method toggle + live total ────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var CART_TOTAL = <?php echo json_encode((float) $cartTotalAmt); ?>;
    var SHIPPING_COST_MAIL = 7.99;

    var shippingRadios  = document.querySelectorAll('input[name="shipping_method"]');
    var addressField    = document.getElementById('shipping-address-field');
    var addressInput    = document.getElementById('shipping-address-input');
    var summaryShipping = document.getElementById('summary-shipping-cost');
    var summaryTotal    = document.getElementById('summary-total');
    var checkoutDisplay = document.getElementById('checkout-total-display');
    var deliveryMethodField = document.getElementById('selected-delivery-method');

    function formatMoney(val) {
        return val.toFixed(2).replace('.', ',');
    }

    function updateTotals() {
        var selected = document.querySelector('input[name="shipping_method"]:checked');
        var isMail = selected && selected.value === 'mail';
        var shippingCost = isMail ? SHIPPING_COST_MAIL : 0;
        var grandTotal   = CART_TOTAL + shippingCost;

        if (summaryShipping) summaryShipping.textContent = formatMoney(shippingCost) + ' €';
        if (summaryTotal)    summaryTotal.textContent    = formatMoney(grandTotal);
        if (checkoutDisplay) checkoutDisplay.textContent = formatMoney(grandTotal);
        if (deliveryMethodField) deliveryMethodField.value = selected ? selected.value : 'pickup';

        if (addressField) {
            addressField.classList.toggle('hidden', !isMail);
            if (addressInput) addressInput.required = isMail;
        }
    }

    shippingRadios.forEach(function(r) { r.addEventListener('change', updateTotals); });
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
