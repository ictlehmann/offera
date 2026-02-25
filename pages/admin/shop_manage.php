<?php
/**
 * Shop-Verwaltung (Admin)
 * Produkte erstellen/bearbeiten, Varianten verwalten, Bestellstatus aktualisieren.
 * Access: board_finance, board_internal, board_external, head
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

if (!Auth::hasRole(Shop::MANAGER_ROLES)) {
    header('Location: ../dashboard/index.php');
    exit;
}

$successMessage = '';
$errorMessage   = '';
$section        = $_GET['section'] ?? 'products';  // products | orders
$editProductId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editProduct    = null;

if ($editProductId) {
    $editProduct = Shop::getProductById($editProductId);
}

// ─── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    // Save product (create or update)
    if ($postAction === 'save_product') {
        $pid  = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $data = [
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'base_price'  => (float) ($_POST['base_price'] ?? 0),
            'active'      => isset($_POST['active']) ? 1 : 0,
            'image_path'  => null,
        ];

        if (empty($data['name'])) {
            $errorMessage = 'Produktname darf nicht leer sein.';
        } else {
            // Handle image upload
            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir    = __DIR__ . '/../../uploads/shop_products/';
                $uploadResult = SecureImageUpload::uploadImage($_FILES['product_image'], $uploadDir, false);
                if ($uploadResult['success']) {
                    $data['image_path'] = $uploadResult['path'];
                } else {
                    $errorMessage = 'Bild-Upload fehlgeschlagen: ' . $uploadResult['error'];
                }
            } elseif ($pid) {
                // Keep existing image if no new one was uploaded
                $existing = Shop::getProductById($pid);
                $data['image_path'] = $existing['image_path'] ?? null;
            }

            if (empty($errorMessage)) {
                if ($pid) {
                    $ok = Shop::updateProduct($pid, $data);
                    $successMessage = $ok ? 'Produkt erfolgreich aktualisiert.' : 'Fehler beim Aktualisieren des Produkts.';
                } else {
                    $newId = Shop::createProduct($data);
                    $ok    = $newId !== null;
                    $successMessage = $ok ? 'Produkt erfolgreich erstellt.' : 'Fehler beim Erstellen des Produkts.';
                    if ($ok) {
                        $pid = $newId;
                    }
                }

                // Save variants if product was saved successfully
                if ($ok && $pid) {
                    $colorGroups = $_POST['colors'] ?? [];
                    $variants    = [];
                    foreach ($colorGroups as $colorData) {
                        $colorName = trim($colorData['name'] ?? '');
                        if ($colorName === '') {
                            continue;
                        }
                        foreach ($colorData['sizes'] ?? [] as $sizeData) {
                            $sizeName = trim($sizeData['value'] ?? '');
                            if ($sizeName !== '') {
                                $variants[] = [
                                    'type'           => $colorName,
                                    'value'          => $sizeName,
                                    'stock_quantity' => (int) ($sizeData['stock'] ?? 0),
                                ];
                            }
                        }
                    }

                    Shop::setVariants($pid, $variants);

                    // Redirect to avoid re-POST
                    header('Location: ' . asset('pages/admin/shop_manage.php?section=products&saved=1'));
                    exit;
                }
            }
        }
    }

    // Update order status
    if ($postAction === 'update_order') {
        $orderId        = (int) ($_POST['order_id'] ?? 0);
        $paymentStatus  = $_POST['payment_status']  ?? null;
        $shippingStatus = $_POST['shipping_status'] ?? null;

        $ok = Shop::updateOrderStatus($orderId, $paymentStatus, $shippingStatus);
        if ($ok) {
            header('Location: ' . asset('pages/admin/shop_manage.php?section=orders&saved=1'));
            exit;
        } else {
            $errorMessage = 'Fehler beim Aktualisieren des Bestellstatus.';
        }
    }
}

if (isset($_GET['saved'])) {
    $successMessage = 'Änderungen erfolgreich gespeichert.';
}

// ─── Data for view ─────────────────────────────────────────────────────────────

$products = [];
$orders   = [];

if ($section === 'products') {
    $products = Shop::getAllProducts();
} elseif ($section === 'orders') {
    $orders = Shop::getAllOrders();

    // Enrich with user email from user DB
    if (!empty($orders)) {
        $userDb  = Database::getUserDB();
        $userIds = array_unique(array_column($orders, 'user_id'));
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt    = $userDb->prepare("SELECT id, email, firstname, lastname FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        $userMap = [];
        foreach ($stmt->fetchAll() as $u) {
            $userMap[$u['id']] = $u;
        }
        foreach ($orders as &$order) {
            $u = $userMap[$order['user_id']] ?? null;
            $order['user_email'] = $u ? $u['email'] : 'Unbekannt';
            $order['user_name']  = $u
                ? trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''))
                : '';
        }
    }
}

$title = 'Shop-Verwaltung – IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-store mr-3 text-blue-600 dark:text-blue-400"></i>
                Shop-Verwaltung
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Produkte und Bestellungen verwalten</p>
        </div>
        <!-- Section tabs -->
        <div class="flex gap-2">
            <a href="<?php echo asset('pages/admin/shop_manage.php?section=products'); ?>"
               class="px-5 py-2 rounded-lg font-medium transition-colors no-underline
                      <?php echo $section === 'products' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
                <i class="fas fa-box mr-1"></i>Produkte
            </a>
            <a href="<?php echo asset('pages/admin/shop_manage.php?section=orders'); ?>"
               class="px-5 py-2 rounded-lg font-medium transition-colors no-underline
                      <?php echo $section === 'orders' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
                <i class="fas fa-list-alt mr-1"></i>Bestellungen
            </a>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <?php if ($section === 'products'): ?>
    <!-- ════════════════════════════════════════════════════════════════════════
         PRODUCTS
    ═══════════════════════════════════════════════════════════════════════════ -->

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

        <!-- Product list -->
        <div class="xl:col-span-2">
            <div class="card rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
                        <i class="fas fa-list mr-2 text-blue-500"></i>Alle Produkte
                    </h2>
                    <a href="<?php echo asset('pages/admin/shop_manage.php?section=products'); ?>"
                       class="text-sm text-blue-600 dark:text-blue-400 hover:underline no-underline">
                        <i class="fas fa-plus mr-1"></i>Neues Produkt
                    </a>
                </div>

                <?php if (empty($products)): ?>
                <p class="text-gray-500 dark:text-gray-400 text-center py-8">Noch keine Produkte vorhanden.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-left">
                                <th class="pb-3 font-semibold">Bild</th>
                                <th class="pb-3 font-semibold">Name</th>
                                <th class="pb-3 font-semibold text-right">Preis</th>
                                <th class="pb-3 font-semibold text-center">Status</th>
                                <th class="pb-3 font-semibold text-center">Varianten</th>
                                <th class="pb-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td class="py-3">
                                    <?php if (!empty($product['image_path'])): ?>
                                    <img src="<?php echo asset($product['image_path']); ?>"
                                         alt="" class="w-12 h-12 object-cover rounded-lg">
                                    <?php else: ?>
                                    <div class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-box text-gray-400"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 font-medium text-gray-800 dark:text-gray-100">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </td>
                                <td class="py-3 text-right text-gray-700 dark:text-gray-300">
                                    <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                                </td>
                                <td class="py-3 text-center">
                                    <?php if ($product['active']): ?>
                                    <span class="px-2 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">Aktiv</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-full text-xs font-medium">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-center text-gray-500 dark:text-gray-400">
                                    <?php echo count($product['variants']); ?>
                                </td>
                                <td class="py-3 text-right">
                                    <a href="<?php echo asset('pages/admin/shop_manage.php?section=products&edit=' . $product['id']); ?>"
                                       class="text-blue-600 dark:text-blue-400 hover:underline text-sm no-underline">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product form -->
        <div>
            <div class="card rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                    <i class="fas fa-<?php echo $editProduct ? 'edit' : 'plus-circle'; ?> mr-2 text-blue-500"></i>
                    <?php echo $editProduct ? 'Produkt bearbeiten' : 'Neues Produkt'; ?>
                </h2>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="post_action" value="save_product">
                    <?php if ($editProduct): ?>
                    <input type="hidden" name="product_id" value="<?php echo $editProduct['id']; ?>">
                    <?php endif; ?>

                    <!-- Name -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Produktname <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required
                               value="<?php echo htmlspecialchars($editProduct['name'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Beschreibung</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($editProduct['description'] ?? ''); ?></textarea>
                    </div>

                    <!-- Price -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Grundpreis (€) <span class="text-red-500">*</span></label>
                        <input type="number" name="base_price" step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($editProduct ? number_format((float) $editProduct['base_price'], 2, '.', '') : '0.00'); ?>"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Image -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Produktbild (JPG/PNG/WebP, max. 5 MB)</label>
                        <?php if (!empty($editProduct['image_path'])): ?>
                        <div class="mb-2">
                            <img src="<?php echo asset($editProduct['image_path']); ?>"
                                 alt="Aktuelles Bild" class="w-24 h-24 object-cover rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Neues Bild hochladen um das bestehende zu ersetzen.</p>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp"
                               class="w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-900 dark:file:text-blue-300 hover:file:bg-blue-100">
                    </div>

                    <!-- Active toggle -->
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="active" name="active" value="1"
                               <?php echo (!$editProduct || $editProduct['active']) ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded border-gray-300 dark:border-gray-600 focus:ring-blue-500">
                        <label for="active" class="text-sm font-semibold text-gray-700 dark:text-gray-300">Produkt aktiv</label>
                    </div>

                    <!-- Variants: Color + Sizes -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-palette mr-1 text-purple-500"></i>Varianten (Farbe &amp; Größen)
                        </label>
                        <?php
                        // Group existing variants by color (type)
                        $variantsByColor = [];
                        foreach ($editProduct['variants'] ?? [] as $v) {
                            $variantsByColor[$v['type']][] = $v;
                        }
                        if (empty($variantsByColor)) {
                            $variantsByColor = ['' => [['value' => '', 'stock_quantity' => 0]]];
                        }
                        ?>
                        <div id="colors-container" class="space-y-3">
                            <?php $colorIdx = 0; foreach ($variantsByColor as $colorName => $sizes): ?>
                            <div class="color-block border border-gray-200 dark:border-gray-600 rounded-xl p-3 bg-gray-50 dark:bg-gray-700/50">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-300">
                                        <i class="fas fa-circle text-xs"></i>
                                    </span>
                                    <input type="text" name="colors[<?php echo $colorIdx; ?>][name]"
                                           value="<?php echo htmlspecialchars($colorName); ?>"
                                           placeholder="Farbe (z.B. Blau)"
                                           class="flex-1 px-3 py-1.5 border border-purple-200 dark:border-purple-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm font-medium focus:ring-2 focus:ring-purple-500">
                                    <button type="button" onclick="this.closest('.color-block').remove()"
                                            class="text-red-400 hover:text-red-600 p-1 ml-1 transition-colors" title="Farbe entfernen">
                                        <i class="fas fa-trash-alt text-xs"></i>
                                    </button>
                                </div>
                                <div class="size-rows space-y-2 ml-8">
                                    <?php $sizeIdx = 0; foreach ($sizes as $s): ?>
                                    <div class="size-row flex gap-2 items-center">
                                        <span class="text-xs text-gray-400 dark:text-gray-500 w-12 shrink-0">Größe</span>
                                        <input type="text" name="colors[<?php echo $colorIdx; ?>][sizes][<?php echo $sizeIdx; ?>][value]"
                                               value="<?php echo htmlspecialchars($s['value']); ?>"
                                               placeholder="z.B. M"
                                               class="w-24 px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500">
                                        <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">Bestand</span>
                                        <input type="number" name="colors[<?php echo $colorIdx; ?>][sizes][<?php echo $sizeIdx; ?>][stock]"
                                               value="<?php echo (int) $s['stock_quantity']; ?>"
                                               min="0"
                                               class="w-20 px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500">
                                        <button type="button" onclick="this.closest('.size-row').remove()"
                                                class="text-red-400 hover:text-red-600 p-1 transition-colors" title="Größe entfernen">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                    <?php $sizeIdx++; endforeach; ?>
                                </div>
                                <button type="button" onclick="addSizeRow(this, <?php echo $colorIdx; ?>)"
                                        class="mt-2 ml-8 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                    <i class="fas fa-plus mr-1"></i>+ Größe hinzufügen
                                </button>
                            </div>
                            <?php $colorIdx++; endforeach; ?>
                        </div>
                        <button type="button" id="add-color"
                                class="mt-3 w-full py-2.5 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-500 dark:text-gray-400 hover:border-purple-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors">
                            <i class="fas fa-plus mr-1"></i>+ Neue Farbe hinzufügen
                        </button>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="submit"
                                class="flex-1 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold transition-all shadow">
                            <i class="fas fa-save mr-2"></i>Speichern
                        </button>
                        <?php if ($editProduct): ?>
                        <a href="<?php echo asset('pages/admin/shop_manage.php?section=products'); ?>"
                           class="px-4 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all font-medium text-center no-underline">
                            Abbrechen
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($section === 'orders'): ?>
    <!-- ════════════════════════════════════════════════════════════════════════
         ORDERS
    ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="card rounded-xl shadow-lg p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-shopping-bag mr-2 text-blue-500"></i>Alle Bestellungen
            </h2>
            <?php if (!empty($orders)): ?>
            <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded-full text-sm font-medium">
                <?php echo count($orders); ?> Bestellungen
            </span>
            <?php endif; ?>
        </div>

        <?php if (empty($orders)): ?>
        <div class="flex flex-col items-center justify-center py-16 text-gray-400 dark:text-gray-500">
            <i class="fas fa-box-open text-5xl mb-4 opacity-40"></i>
            <p class="text-lg font-medium">Noch keine Bestellungen vorhanden.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($orders as $order):
                $pBadgeClass = match($order['payment_status']) {
                    'paid'   => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-300 dark:ring-emerald-700',
                    'failed' => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-1 ring-red-300 dark:ring-red-700',
                    default  => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 ring-1 ring-amber-300 dark:ring-amber-700',
                };
                $pIcon = match($order['payment_status']) {
                    'paid'   => 'fa-check-circle',
                    'failed' => 'fa-times-circle',
                    default  => 'fa-clock',
                };
                $pLabel = match($order['payment_status']) {
                    'paid'   => 'Bezahlt',
                    'failed' => 'Fehlgeschlagen',
                    default  => 'Ausstehend',
                };
                $sBadgeClass = match($order['shipping_status']) {
                    'shipped'   => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 ring-1 ring-blue-300 dark:ring-blue-700',
                    'delivered' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-300 dark:ring-emerald-700',
                    default     => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 ring-1 ring-gray-300 dark:ring-gray-600',
                };
                $sIcon = match($order['shipping_status']) {
                    'shipped'   => 'fa-shipping-fast',
                    'delivered' => 'fa-box-open',
                    default     => 'fa-hourglass-half',
                };
                $sLabel = match($order['shipping_status']) {
                    'shipped'   => 'Versendet',
                    'delivered' => 'Geliefert',
                    default     => 'Ausstehend',
                };
            ?>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 bg-gray-50 dark:bg-gray-700/40 rounded-xl border border-gray-100 dark:border-gray-700 hover:border-blue-200 dark:hover:border-blue-700 transition-colors">
                <!-- Order ID + date -->
                <div class="flex items-center gap-3 sm:w-32 shrink-0">
                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center text-blue-600 dark:text-blue-400 shrink-0">
                        <i class="fas fa-receipt text-sm"></i>
                    </div>
                    <div>
                        <p class="font-mono font-semibold text-gray-800 dark:text-gray-100 text-sm">#<?php echo $order['id']; ?></p>
                        <p class="text-xs text-gray-400 dark:text-gray-500"><?php echo date('d.m.Y', strtotime($order['created_at'])); ?></p>
                    </div>
                </div>

                <!-- User info -->
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-800 dark:text-gray-100 text-sm truncate">
                        <?php echo htmlspecialchars($order['user_name'] ?? 'Unbekannt'); ?>
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                        <?php echo htmlspecialchars($order['user_email']); ?>
                    </p>
                </div>

                <!-- Amount + payment method -->
                <div class="text-right sm:text-left shrink-0">
                    <p class="font-bold text-gray-800 dark:text-gray-100">
                        <?php echo number_format((float) $order['total_amount'], 2, ',', '.'); ?> €
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">
                        <?php echo htmlspecialchars($order['payment_method']); ?>
                    </p>
                </div>

                <!-- Status badges -->
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $pBadgeClass; ?>">
                        <i class="fas <?php echo $pIcon; ?>"></i>
                        <?php echo $pLabel; ?>
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $sBadgeClass; ?>">
                        <i class="fas <?php echo $sIcon; ?>"></i>
                        <?php echo $sLabel; ?>
                    </span>
                </div>

                <!-- Edit button -->
                <button type="button"
                        onclick="openOrderModal(<?php echo htmlspecialchars(json_encode($order)); ?>)"
                        class="shrink-0 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:border-blue-300 dark:hover:border-blue-600 transition-colors text-sm font-medium">
                    <i class="fas fa-pen mr-1"></i>Status
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Order status modal -->
    <div id="order-modal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 w-full max-w-md border border-gray-100 dark:border-gray-700">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">
                    <i class="fas fa-edit mr-2 text-blue-500"></i>Bestellstatus aktualisieren
                </h3>
                <button type="button" onclick="closeOrderModal()"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <form method="POST" id="order-status-form" class="space-y-4">
                <input type="hidden" name="post_action" value="update_order">
                <input type="hidden" name="order_id" id="modal-order-id">

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-credit-card mr-1 text-emerald-500"></i>Zahlungsstatus
                    </label>
                    <select name="payment_status" id="modal-payment-status"
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="pending">⏳ Ausstehend</option>
                        <option value="paid">✅ Bezahlt</option>
                        <option value="failed">❌ Fehlgeschlagen</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-truck mr-1 text-blue-500"></i>Versandstatus
                    </label>
                    <select name="shipping_status" id="modal-shipping-status"
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="pending">⏳ Ausstehend</option>
                        <option value="shipped">🚚 Versendet</option>
                        <option value="delivered">📦 Geliefert</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" form="order-status-form"
                            class="flex-1 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold shadow transition-all">
                        <i class="fas fa-save mr-2"></i>Speichern
                    </button>
                    <button type="button" onclick="closeOrderModal()"
                            class="px-4 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 font-medium transition-colors">
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Color + Size variant builder ─────────────────────────────────────────────

let colorCount = document.querySelectorAll('.color-block').length;

const INPUT_CLASS = 'border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm focus:ring-2';

function sizeRowHtml(colorIdx, sizeIdx) {
    return `<div class="size-row flex gap-2 items-center">
        <span class="text-xs text-gray-400 dark:text-gray-500 w-12 shrink-0">Größe</span>
        <input type="text" name="colors[${colorIdx}][sizes][${sizeIdx}][value]" placeholder="z.B. M"
               class="w-24 px-2 py-1.5 ${INPUT_CLASS} focus:ring-blue-500">
        <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">Bestand</span>
        <input type="number" name="colors[${colorIdx}][sizes][${sizeIdx}][stock]" value="0" min="0"
               class="w-20 px-2 py-1.5 ${INPUT_CLASS} focus:ring-blue-500">
        <button type="button" onclick="this.closest('.size-row').remove()"
                class="text-red-400 hover:text-red-600 p-1 transition-colors" title="Größe entfernen">
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>`;
}

document.getElementById('add-color')?.addEventListener('click', function () {
    const idx   = colorCount++;
    const block = document.createElement('div');
    block.className = 'color-block border border-gray-200 dark:border-gray-600 rounded-xl p-3 bg-gray-50 dark:bg-gray-700/50';
    block.innerHTML = `
        <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-300">
                <i class="fas fa-circle text-xs"></i>
            </span>
            <input type="text" name="colors[${idx}][name]" placeholder="Farbe (z.B. Blau)"
                   class="flex-1 px-3 py-1.5 border border-purple-200 dark:border-purple-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm font-medium focus:ring-2 focus:ring-purple-500">
            <button type="button" onclick="this.closest('.color-block').remove()"
                    class="text-red-400 hover:text-red-600 p-1 ml-1 transition-colors" title="Farbe entfernen">
                <i class="fas fa-trash-alt text-xs"></i>
            </button>
        </div>
        <div class="size-rows space-y-2 ml-8">${sizeRowHtml(idx, 0)}</div>
        <button type="button" onclick="addSizeRow(this, ${idx})"
                class="mt-2 ml-8 text-xs text-blue-600 dark:text-blue-400 hover:underline">
            <i class="fas fa-plus mr-1"></i>+ Größe hinzufügen
        </button>`;
    document.getElementById('colors-container').appendChild(block);
});

function addSizeRow(btn, colorIdx) {
    const sizeContainer = btn.previousElementSibling; // .size-rows
    const nextIdx       = sizeContainer.querySelectorAll('.size-row').length;
    sizeContainer.insertAdjacentHTML('beforeend', sizeRowHtml(colorIdx, nextIdx));
}

// ── Order modal ──────────────────────────────────────────────────────────────

function openOrderModal(order) {
    document.getElementById('modal-order-id').value          = order.id;
    document.getElementById('modal-payment-status').value    = order.payment_status;
    document.getElementById('modal-shipping-status').value   = order.shipping_status;
    document.getElementById('order-modal').classList.remove('hidden');
}

function closeOrderModal() {
    document.getElementById('order-modal').classList.add('hidden');
}

document.getElementById('order-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeOrderModal();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
