<?php
/**
 * Shop – Product Detail Page
 * A full-featured product detail view with image gallery,
 * variant selection, stock indicator and add-to-cart.
 * Access: all authenticated users
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

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

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$productId) {
    header('Location: ' . asset('pages/shop/index.php'));
    exit;
}

$product = Shop::getProductById($productId);
if (!$product || !$product['active']) {
    header('Location: ' . asset('pages/shop/index.php'));
    exit;
}

// ─── Cart helpers ─────────────────────────────────────────────────────────────

function viewCartKey(int $productId, ?int $variantId): string {
    return $productId . '_' . ($variantId ?? 0);
}

function viewCartCount(): int {
    return array_sum(array_column($_SESSION['shop_cart'], 'quantity'));
}

// ─── Handle POST actions ───────────────────────────────────────────────────────

$successMessage = '';
$errorMessage   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    if ($postAction === 'add_to_cart') {
        $pid             = (int) ($_POST['product_id'] ?? 0);
        $vid             = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int) $_POST['variant_id'] : null;
        $qty             = max(1, (int) ($_POST['quantity'] ?? 1));
        $selectedVariant = trim($_POST['selected_variant'] ?? '');
        $p               = Shop::getProductById($pid);

        if ($p) {
            if (!empty($p['variants_csv']) && $selectedVariant === '') {
                $errorMessage = 'Bitte wähle eine Variante aus.';
            } else {
                $price = (float) $p['base_price'];
                $key   = viewCartKey($pid, $vid) . ($selectedVariant !== '' ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $selectedVariant) : '');
                $variantName = '';

                if ($vid) {
                    foreach ($p['variants'] as $v) {
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
                        'product_id'       => $pid,
                        'variant_id'       => $vid,
                        'product_name'     => $p['name'],
                        'variant_name'     => $variantName,
                        'selected_variant' => $selectedVariant,
                        'price'            => $price,
                        'quantity'         => $qty,
                    ];
                }
                $successMessage = htmlspecialchars($p['name']) . ' wurde zum Warenkorb hinzugefügt.';
            }
        } else {
            $errorMessage = 'Produkt nicht gefunden.';
        }
    } elseif ($postAction === 'toggle_restock_notification') {
        $pid  = (int) ($_POST['product_id'] ?? 0);
        $type = trim($_POST['variant_type']  ?? '');
        $val  = trim($_POST['variant_value'] ?? '');

        if ($pid && $userId) {
            $hasNotif = Shop::hasRestockNotification($userId, $pid, $type, $val);
            if ($hasNotif) {
                Shop::removeRestockNotification($userId, $pid, $type, $val);
                $successMessage = 'Benachrichtigung für ' . htmlspecialchars($val) . ' deaktiviert.';
            } else {
                $userEmail = $user['email'] ?? '';
                Shop::addRestockNotification($userId, $pid, $type, $val, $userEmail);
                $successMessage = 'Du wirst benachrichtigt, sobald ' . htmlspecialchars($val) . ' wieder verfügbar ist.';
            }
        }
        // Re-fetch product to reflect updated notification state
        $product = Shop::getProductById($productId);
    }
}

// ─── Prepare view data ─────────────────────────────────────────────────────────

$images = $product['images'] ?? [];
if (!empty($product['image_path']) && empty($images)) {
    $images = [['image_path' => $product['image_path']]];
}

// Group variants by type
$groupedVariants = [];
foreach ($product['variants'] as $v) {
    $groupedVariants[$v['type']][] = $v;
}

// Compute stock totals
$hasStructuredVariants = !empty($product['variants']);
$totalStock            = $hasStructuredVariants
    ? array_sum(array_column($product['variants'], 'stock_quantity'))
    : null;
$anyInStock = $hasStructuredVariants ? $totalStock > 0 : true;

// Bulk order data
$isBulk       = !empty($product['is_bulk_order']);
$bulkProgress = $isBulk ? Shop::getBulkOrderProgress($productId) : 0;
$bulkGoal     = $isBulk ? (int) $product['bulk_min_goal'] : 0;
$bulkPct      = ($isBulk && $bulkGoal > 0) ? min(100, (int) round($bulkProgress / $bulkGoal * 100)) : 0;

$cartCount = viewCartCount();
$title = htmlspecialchars($product['name']) . ' – IBC Shop';
ob_start();
?>

<div class="max-w-7xl mx-auto">

    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <a href="<?php echo asset('pages/shop/index.php'); ?>"
           class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors no-underline flex items-center gap-1.5">
            <i class="fas fa-shopping-bag"></i>
            <span>Shop</span>
        </a>
        <i class="fas fa-chevron-right text-xs opacity-50"></i>
        <span class="text-gray-800 dark:text-gray-200 font-medium truncate max-w-xs">
            <?php echo htmlspecialchars($product['name']); ?>
        </span>
        <!-- Cart shortcut -->
        <a href="<?php echo asset('pages/shop/index.php?action=cart'); ?>"
           class="ml-auto relative inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-md font-medium text-sm no-underline">
            <i class="fas fa-shopping-cart"></i>
            Warenkorb
            <?php if ($cartCount > 0): ?>
            <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center leading-none">
                <?php echo min(99, $cartCount); ?>
            </span>
            <?php endif; ?>
        </a>
    </nav>

    <!-- Flash messages -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 rounded-xl flex items-center gap-3">
        <div class="w-8 h-8 bg-emerald-100 dark:bg-emerald-800 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fas fa-check text-emerald-600 dark:text-emerald-400 text-sm"></i>
        </div>
        <?php echo $successMessage; ?>
    </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-xl flex items-center gap-3">
        <div class="w-8 h-8 bg-red-100 dark:bg-red-800 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fas fa-exclamation-circle text-red-600 dark:text-red-400 text-sm"></i>
        </div>
        <?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Main product card -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">

            <!-- ── Left: Image Gallery ── -->
            <div class="relative bg-gray-50 dark:bg-gray-900 flex flex-col">
                <?php if (!empty($images)): ?>
                <!-- Main image viewer -->
                <div class="relative overflow-hidden" style="aspect-ratio: 1/1" id="view-slider">
                    <?php foreach ($images as $idx => $img): ?>
                    <img src="<?php echo asset($img['image_path']); ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         data-slide="<?php echo $idx; ?>"
                         loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>"
                         class="view-slide-img absolute inset-0 w-full h-full object-contain transition-opacity duration-400 <?php echo $idx === 0 ? 'opacity-100' : 'opacity-0'; ?>">
                    <?php endforeach; ?>

                    <?php if (count($images) > 1): ?>
                    <!-- Arrow navigation -->
                    <button type="button" onclick="viewSlide(-1)" aria-label="Vorheriges Bild"
                            class="absolute left-3 top-1/2 -translate-y-1/2 bg-white/80 dark:bg-gray-800/80 hover:bg-white dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full w-11 h-11 flex items-center justify-center z-10 shadow-lg backdrop-blur-sm transition-all">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button type="button" onclick="viewSlide(1)" aria-label="Nächstes Bild"
                            class="absolute right-3 top-1/2 -translate-y-1/2 bg-white/80 dark:bg-gray-800/80 hover:bg-white dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full w-11 h-11 flex items-center justify-center z-10 shadow-lg backdrop-blur-sm transition-all">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <!-- Dot indicators -->
                    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2 z-10" id="view-dots">
                        <?php foreach ($images as $idx => $img): ?>
                        <button type="button" onclick="viewGoTo(<?php echo $idx; ?>)" aria-label="Bild <?php echo $idx + 1; ?>"
                                class="view-dot w-2.5 h-2.5 rounded-full transition-all shadow-sm <?php echo $idx === 0 ? 'bg-white scale-125' : 'bg-white/50 hover:bg-white/80'; ?>">
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Image counter -->
                    <div class="absolute top-4 right-4 bg-black/40 backdrop-blur-sm text-white text-xs font-medium px-2.5 py-1 rounded-full" id="view-counter">
                        1 / <?php echo count($images); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Badges overlay -->
                    <div class="absolute top-4 left-4 flex flex-col gap-2 z-10">
                        <?php if (!$anyInStock && $hasStructuredVariants): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-800/90 backdrop-blur-sm text-white text-xs font-bold rounded-full uppercase tracking-wider">
                            <i class="fas fa-ban text-xs"></i> Ausverkauft
                        </span>
                        <?php endif; ?>
                        <?php if ($isBulk): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-600/90 backdrop-blur-sm text-white text-xs font-bold rounded-full">
                            <i class="fas fa-layer-group text-xs"></i> Sammelbestellung
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (count($images) > 1): ?>
                <!-- Thumbnail strip -->
                <div class="flex gap-2.5 p-4 overflow-x-auto" id="view-thumbs">
                    <?php foreach ($images as $idx => $img): ?>
                    <button type="button" onclick="viewGoTo(<?php echo $idx; ?>)"
                            class="view-thumb flex-shrink-0 w-16 h-16 sm:w-20 sm:h-20 rounded-xl overflow-hidden border-2 transition-all focus:outline-none
                                <?php echo $idx === 0 ? 'border-blue-500 shadow-md' : 'border-transparent opacity-60 hover:opacity-100 hover:border-gray-300 dark:hover:border-gray-500'; ?>">
                        <img src="<?php echo asset($img['image_path']); ?>"
                             alt="Bild <?php echo $idx + 1; ?>"
                             loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>"
                             class="w-full h-full object-cover pointer-events-none">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="flex-1 flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-800 dark:to-gray-900" style="aspect-ratio:1/1">
                    <i class="fas fa-box text-5xl text-blue-200 dark:text-gray-600"></i>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Right: Product Details & Add to Cart ── -->
            <div class="p-7 lg:p-10 flex flex-col overflow-y-auto" style="max-height: 90vh">

                <!-- Category + Gender tags -->
                <div class="flex flex-wrap gap-2 mb-5">
                    <?php if (!empty($product['category'])): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-semibold rounded-full border border-blue-100 dark:border-blue-800">
                        <i class="fas fa-tag text-xs"></i>
                        <?php echo htmlspecialchars($product['category']); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($product['gender']) && $product['gender'] !== 'Keine'): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-xs font-semibold rounded-full border border-purple-100 dark:border-purple-800">
                        <i class="fas fa-person text-xs"></i>
                        <?php echo htmlspecialchars($product['gender']); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($product['sku'])): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs font-medium rounded-full border border-gray-200 dark:border-gray-600">
                        SKU: <?php echo htmlspecialchars($product['sku']); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Product name -->
                <h1 class="text-xl sm:text-2xl md:text-3xl lg:text-4xl font-bold text-gray-900 dark:text-gray-50 mb-3 leading-tight">
                    <?php echo htmlspecialchars($product['name']); ?>
                </h1>

                <!-- Price row -->
                <div class="flex items-baseline gap-4 mb-5">
                    <span class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-blue-600 dark:text-blue-400">
                        <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                    </span>
                    <?php if (!empty($product['shipping_cost']) && (float) $product['shipping_cost'] > 0): ?>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        zzgl. <?php echo number_format((float) $product['shipping_cost'], 2, ',', '.'); ?> € Versand
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Stock indicator -->
                <?php if ($hasStructuredVariants): ?>
                <?php if ($totalStock <= 0): ?>
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl mb-5 w-fit">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0"></span>
                    <span class="text-sm font-semibold text-red-700 dark:text-red-300">Ausverkauft</span>
                </div>
                <?php elseif ($totalStock <= 5): ?>
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl mb-5 w-fit">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 flex-shrink-0 animate-pulse"></span>
                    <span class="text-sm font-semibold text-amber-700 dark:text-amber-300">
                        Nur noch <?php echo $totalStock; ?> auf Lager
                    </span>
                </div>
                <?php else: ?>
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl mb-5 w-fit">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                    <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"><?php echo $totalStock; ?> Stück auf Lager</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Description -->
                <?php if (!empty($product['description'])): ?>
                <p class="text-gray-600 dark:text-gray-300 mb-5 leading-relaxed text-[0.95rem] break-words">
                    <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                </p>
                <?php endif; ?>

                <!-- Hints -->
                <?php if (!empty($product['hints'])): ?>
                <div class="mb-5 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl">
                    <div class="flex gap-2.5">
                        <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <p class="text-sm text-amber-800 dark:text-amber-200 leading-relaxed">
                            <?php echo nl2br(htmlspecialchars($product['hints'])); ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bulk order progress -->
                <?php if ($isBulk): ?>
                <div class="mb-5 p-5 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fas fa-layer-group text-purple-600 dark:text-purple-400"></i>
                        <span class="font-semibold text-purple-800 dark:text-purple-200 text-sm">Sammelbestellung</span>
                    </div>
                    <?php if ($bulkGoal > 0): ?>
                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300 mb-2">
                        <span><?php echo $bulkProgress; ?> von <?php echo $bulkGoal; ?> bestellt</span>
                        <span class="font-semibold"><?php echo $bulkPct; ?>%</span>
                    </div>
                    <div class="w-full bg-purple-200 dark:bg-purple-800 rounded-full h-3 mb-2 overflow-hidden">
                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-3 rounded-full transition-all duration-500" style="width:<?php echo $bulkPct; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Produktion startet ab <?php echo $bulkGoal; ?> Bestellungen
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($product['bulk_end_date'])): ?>
                    <div class="mt-2 flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-300 font-medium">
                        <i class="fas fa-clock text-xs"></i>
                        Bestellbar bis: <?php echo date('d.m.Y', strtotime($product['bulk_end_date'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Add to cart form -->
                <form method="POST"
                      action="<?php echo asset('pages/shop/view.php?id=' . $productId); ?>"
                      class="flex flex-col gap-5">
                    <input type="hidden" name="post_action" value="add_to_cart">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $productId; ?>">

                    <!-- Structured variant selection (size, color, etc.) -->
                    <?php if (!empty($groupedVariants)): ?>
                    <?php foreach ($groupedVariants as $type => $variants): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                            <?php echo htmlspecialchars($type); ?>
                            <span class="ml-1 text-red-500">*</span>
                        </label>
                        <div class="flex flex-wrap gap-2.5" id="variant-group-<?php echo htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '-', $type)); ?>">
                            <?php foreach ($variants as $v):
                                $outOfStock = (int) $v['stock_quantity'] <= 0;
                                $lowStock   = !$outOfStock && (int) $v['stock_quantity'] <= 5;
                            ?>
                            <label class="relative cursor-pointer group <?php echo $outOfStock ? 'opacity-60' : ''; ?>">
                                <input type="radio" name="variant_id" value="<?php echo $v['id']; ?>"
                                       <?php echo $outOfStock ? 'disabled' : ''; ?>
                                       class="sr-only peer variant-radio"
                                       data-stock="<?php echo (int) $v['stock_quantity']; ?>"
                                       data-type="<?php echo htmlspecialchars($type); ?>"
                                       data-value="<?php echo htmlspecialchars($v['value']); ?>"
                                       <?php echo !$outOfStock ? 'required' : ''; ?>>
                                <span class="relative inline-flex flex-col items-center justify-center
                                             min-w-[3.5rem] px-4 py-2.5 border-2 rounded-xl text-sm font-semibold
                                             transition-all duration-200 select-none
                                             <?php echo $outOfStock
                                                 ? 'border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-600 bg-gray-50 dark:bg-gray-800/50'
                                                 : 'border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800
                                                    peer-checked:border-blue-500 peer-checked:bg-blue-500 peer-checked:text-white
                                                    hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20'; ?>">
                                    <?php echo htmlspecialchars($v['value']); ?>
                                    <?php if ($outOfStock): ?>
                                    <span class="absolute inset-0 flex items-center justify-center">
                                        <span class="absolute w-full h-0.5 bg-gray-300 dark:bg-gray-600 rotate-45 origin-center"></span>
                                    </span>
                                    <?php elseif ($lowStock): ?>
                                    <span class="absolute -top-1.5 -right-1.5 w-3.5 h-3.5 bg-amber-400 rounded-full border-2 border-white dark:border-gray-800"></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($outOfStock): ?>
                                <span class="block text-center text-xs text-gray-400 dark:text-gray-500 mt-1">Ausverkauft</span>
                                <?php elseif ($lowStock): ?>
                                <span class="block text-center text-xs text-amber-600 dark:text-amber-400 mt-1">
                                    Noch <?php echo (int) $v['stock_quantity']; ?>
                                </span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- CSV variants (simple dropdown) -->
                    <?php if (!empty($product['variants_csv'])): ?>
                    <?php $variantOptions = array_filter(array_map('trim', explode(',', $product['variants_csv']))); ?>
                    <div>
                        <label for="selected-variant" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Größe / Variante <span class="text-red-500">*</span>
                        </label>
                        <select name="selected_variant" id="selected-variant" required
                                class="w-full px-4 py-3 border-2 border-gray-200 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow appearance-none cursor-pointer">
                            <option value="">– Bitte Größe/Variante wählen –</option>
                            <?php foreach ($variantOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Selected variant stock hint (shown dynamically) -->
                    <div id="variant-stock-hint" class="hidden"></div>

                    <!-- Quantity stepper -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Anzahl</label>
                        <div class="inline-flex items-center rounded-2xl overflow-hidden border-2 border-gray-200 dark:border-gray-600">
                            <button type="button" onclick="viewAdjustQty(-1)"
                                    class="w-11 h-12 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors text-xl font-bold select-none flex items-center justify-center">
                                −
                            </button>
                            <input type="number" name="quantity" id="view-qty-input" value="1" min="1" max="99"
                                   class="w-16 h-12 text-center bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-lg font-semibold border-x-2 border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-0">
                            <button type="button" onclick="viewAdjustQty(1)"
                                    class="w-11 h-12 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors text-xl font-bold select-none flex items-center justify-center">
                                +
                            </button>
                        </div>
                    </div>

                    <!-- Add to cart button -->
                    <?php if ($anyInStock): ?>
                    <button type="submit"
                            class="w-full py-4 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 active:from-blue-800 text-white rounded-2xl font-bold text-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center gap-3 group">
                        <i class="fas fa-cart-plus text-xl transition-transform group-hover:scale-110"></i>
                        In den Warenkorb
                    </button>
                    <?php else: ?>
                    <div class="w-full py-4 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 rounded-2xl text-center font-semibold text-lg flex items-center justify-center gap-3">
                        <i class="fas fa-ban"></i>
                        Derzeit nicht verfügbar
                    </div>
                    <?php endif; ?>
                </form>

                <!-- Restock notifications (separate standalone forms, outside the cart form) -->
                <?php
                $hasAnyOutOfStock = false;
                foreach ($groupedVariants as $type => $variants) {
                    foreach ($variants as $v) {
                        if ((int) $v['stock_quantity'] <= 0) { $hasAnyOutOfStock = true; break 2; }
                    }
                }
                if ($hasAnyOutOfStock): ?>
                <div class="mt-2 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                        <i class="fas fa-bell text-amber-400"></i> Wieder verfügbar – informier mich
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($groupedVariants as $type => $variants): ?>
                        <?php foreach ($variants as $v):
                            if ((int) $v['stock_quantity'] > 0) continue;
                            $hasNotif = Shop::hasRestockNotification($userId, $productId, $type, $v['value']);
                        ?>
                        <form method="POST"
                              action="<?php echo asset('pages/shop/view.php?id=' . $productId); ?>">
                            <input type="hidden" name="post_action"   value="toggle_restock_notification">
                            <input type="hidden" name="csrf_token"    value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="product_id"    value="<?php echo $productId; ?>">
                            <input type="hidden" name="variant_type"  value="<?php echo htmlspecialchars($type); ?>">
                            <input type="hidden" name="variant_value" value="<?php echo htmlspecialchars($v['value']); ?>">
                            <button type="submit"
                                    class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-xl border transition-all
                                        <?php echo $hasNotif
                                            ? 'border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/30'
                                            : 'border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400'; ?>">
                                <i class="fas <?php echo $hasNotif ? 'fa-bell-slash' : 'fa-bell'; ?>"></i>
                                <span><?php echo htmlspecialchars($type . ': ' . $v['value']); ?></span>
                                <?php if ($hasNotif): ?>
                                <i class="fas fa-check text-xs text-amber-500"></i>
                                <?php endif; ?>
                            </button>
                        </form>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meta info -->
                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-700 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <?php if (!empty($product['pickup_location'])): ?>
                    <div class="flex items-start gap-3 text-gray-600 dark:text-gray-400">
                        <div class="w-8 h-8 rounded-lg bg-green-50 dark:bg-green-900/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-building text-green-600 dark:text-green-400 text-xs"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 text-xs uppercase tracking-wide mb-0.5">Abholort</p>
                            <p><?php echo htmlspecialchars($product['pickup_location']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($product['sku'])): ?>
                    <div class="flex items-start gap-3 text-gray-600 dark:text-gray-400">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-barcode text-gray-500 dark:text-gray-400 text-xs"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 text-xs uppercase tracking-wide mb-0.5">Artikelnummer</p>
                            <p class="font-mono"><?php echo htmlspecialchars($product['sku']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
            <!-- end right panel -->
        </div>
    </div>
    <!-- end main card -->

</div>

<script>
// ── Gallery ───────────────────────────────────────────────────────────────────
(function () {
    var currentIndex = 0;
    var images = document.querySelectorAll('.view-slide-img');
    var dots   = document.querySelectorAll('.view-dot');
    var thumbs = document.querySelectorAll('.view-thumb');
    var counter = document.getElementById('view-counter');
    var total = images.length;

    function goTo(idx) {
        if (total < 2) return;
        idx = ((idx % total) + total) % total;
        images[currentIndex].classList.remove('opacity-100');
        images[currentIndex].classList.add('opacity-0');
        images[idx].classList.remove('opacity-0');
        images[idx].classList.add('opacity-100');

        // dots
        dots.forEach(function(d, i) {
            d.classList.toggle('bg-white', i === idx);
            d.classList.toggle('scale-125', i === idx);
            d.classList.toggle('bg-white/50', i !== idx);
            d.classList.remove('bg-white/80');
        });

        // thumbs
        thumbs.forEach(function(t, i) {
            t.classList.toggle('border-blue-500', i === idx);
            t.classList.toggle('shadow-md', i === idx);
            t.classList.toggle('border-transparent', i !== idx);
            t.classList.toggle('opacity-60', i !== idx);
        });

        if (counter) counter.textContent = (idx + 1) + ' / ' + total;
        currentIndex = idx;
    }

    window.viewGoTo  = goTo;
    window.viewSlide = function(dir) { goTo(currentIndex + dir); };
}());

// ── Quantity ──────────────────────────────────────────────────────────────────
function viewAdjustQty(delta) {
    var input = document.getElementById('view-qty-input');
    if (!input) return;
    input.value = Math.max(1, Math.min(99, (parseInt(input.value, 10) || 1) + delta));
}

// ── Variant stock hints ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('.variant-radio');
    var hint   = document.getElementById('variant-stock-hint');

    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            if (!hint) return;
            var stock = parseInt(this.dataset.stock, 10);
            var val   = this.dataset.value;
            if (stock <= 0) {
                hint.className = 'flex items-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-sm font-semibold text-red-700 dark:text-red-300';
                hint.innerHTML = '<span class="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0"></span> Ausverkauft';
            } else if (stock <= 5) {
                hint.className = 'flex items-center gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm font-semibold text-amber-700 dark:text-amber-300';
                hint.innerHTML = '<span class="w-2.5 h-2.5 rounded-full bg-amber-500 flex-shrink-0 animate-pulse"></span> Nur noch ' + stock + ' auf Lager';
            } else {
                hint.className = 'flex items-center gap-2 px-4 py-2.5 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl text-sm font-semibold text-emerald-700 dark:text-emerald-300';
                hint.innerHTML = '<span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span> ' + stock + ' auf Lager';
            }
            hint.classList.remove('hidden');
        });
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
