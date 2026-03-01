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
$openModal      = false;

if ($editProductId) {
    $editProduct = Shop::getProductById($editProductId);
    $openModal   = true;
}

// ─── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    // Save product (create or update)
    if ($postAction === 'save_product') {
        $pid  = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $data = [
            'name'          => trim($_POST['name'] ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'hints'         => trim($_POST['hints'] ?? ''),
            'base_price'    => (float) ($_POST['base_price'] ?? 0),
            'active'        => isset($_POST['active']) ? 1 : 0,
            'is_bulk_order' => isset($_POST['is_bulk_order']) ? 1 : 0,
            'bulk_end_date' => !empty($_POST['bulk_end_date']) ? $_POST['bulk_end_date'] : null,
            'bulk_min_goal' => !empty($_POST['bulk_min_goal']) ? (int) $_POST['bulk_min_goal'] : null,
            'category'         => trim($_POST['category'] ?? ''),
            'gender'           => trim($_POST['gender'] ?? ''),
            'pickup_location'  => trim($_POST['pickup_location'] ?? ''),
            'variants'         => trim($_POST['variants_text'] ?? ''),
            'sku'              => trim($_POST['sku'] ?? ''),
            'image_path'    => null,
        ];

        $allowedCategories = ['Kleidung', 'Accessoires', 'Bürobedarf', 'Sonstiges'];
        if (!empty($data['category']) && !in_array($data['category'], $allowedCategories, true)) {
            $data['category'] = '';
        }

        if (empty($data['name'])) {
            $errorMessage  = 'Produktname darf nicht leer sein.';
            $openModal     = true;
            $editProductId = $pid;
            if ($pid) {
                $editProduct = Shop::getProductById($pid);
            }
        } else {
            // Keep existing image_path for backwards compatibility
            if ($pid) {
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

                // Save variants and images if product was saved successfully
                if ($ok && $pid) {
                    // Handle multiple image uploads
                    if (!empty($_FILES['product_images']['name'][0])) {
                        $uploadDir     = __DIR__ . '/../../uploads/shop_products/';
                        $files         = $_FILES['product_images'];
                        $existingImgs  = Shop::getProductImages($pid);
                        $nextSortOrder = count($existingImgs);
                        for ($i = 0; $i < count($files['name']); $i++) {
                            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                                continue;
                            }
                            $singleFile = [
                                'name'     => $files['name'][$i],
                                'type'     => $files['type'][$i],
                                'tmp_name' => $files['tmp_name'][$i],
                                'error'    => $files['error'][$i],
                                'size'     => $files['size'][$i],
                            ];
                            $uploadResult = SecureImageUpload::uploadImage($singleFile, $uploadDir, false);
                            if ($uploadResult['success']) {
                                Shop::addProductImage($pid, $uploadResult['path'], $nextSortOrder++);
                            }
                        }
                    }

                    $hasVariants = isset($_POST['has_variants']);
                    if (!$hasVariants) {
                        // No named variants – store a single default variant.
                        // Stock tracking is handled via bulk_min_goal for bulk orders;
                        // stock_quantity is set to 0 as it is not used in this system.
                        $variants = [['type' => '', 'value' => '', 'stock_quantity' => 0]];
                    } else {
                        $variantGroups = $_POST['variants'] ?? [];
                        $variants      = [];
                        foreach ($variantGroups as $groupData) {
                            $typeName = trim($groupData['name'] ?? '');
                            if ($typeName === '') {
                                continue;
                            }
                            foreach ($groupData['values'] ?? [] as $valueData) {
                                $valueName = trim($valueData['value'] ?? '');
                                if ($valueName !== '') {
                                    $variants[] = [
                                        'type'           => $typeName,
                                        'value'          => $valueName,
                                        'stock_quantity' => (int) ($valueData['stock'] ?? 0),
                                    ];
                                }
                            }
                        }
                    }

                    Shop::setVariants($pid, $variants);

                    // If creating a new product and "also create for other gender" was requested
                    if (!isset($_POST['product_id']) || (int) $_POST['product_id'] === 0) {
                        if (isset($_POST['also_create_other_gender'])) {
                            if ($data['gender'] === 'Herren') {
                                $otherGender = 'Damen';
                            } elseif ($data['gender'] === 'Damen') {
                                $otherGender = 'Herren';
                            } else {
                                $otherGender = null;
                            }
                            if ($otherGender) {
                                $dataCopy               = $data;
                                $dataCopy['gender']     = $otherGender;
                                $dataCopy['image_path'] = null; // Images are gender-specific; admin can add them separately
                                $copyId = Shop::createProduct($dataCopy);
                                if ($copyId) {
                                    Shop::setVariants($copyId, $variants);
                                }
                            }
                        }
                    }

                    // Redirect to avoid re-POST
                    header('Location: ' . asset('pages/admin/shop_manage.php?section=products&saved=1'));
                    exit;
                }
            }
        }
    }

    // Delete product
    if ($postAction === 'delete_product') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        if ($pid) {
            $ok = Shop::deleteProduct($pid);
            if ($ok) {
                header('Location: ' . asset('pages/admin/shop_manage.php?section=products&deleted=1'));
                exit;
            } else {
                $errorMessage = 'Fehler beim Löschen des Produkts.';
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
if (isset($_GET['deleted'])) {
    $successMessage = 'Produkt erfolgreich gelöscht.';
}

// ─── Data for view ─────────────────────────────────────────────────────────────

$products = [];
$orders   = [];

if ($section === 'products') {
    $products = Shop::getAllProducts();
    // Pre-process image asset URLs for JS
    foreach ($products as &$p) {
        foreach ($p['images'] as &$img) {
            $img['url'] = !empty($img['image_path']) ? asset($img['image_path']) : '';
        }
    }
    unset($p, $img);

    // Fetch recent sales stats for the dashboard chart
    $recentSalesStats = Shop::getMonthlySalesStats(6);
    $chartLabels   = [];
    $chartCounts   = [];
    $chartRevenues = [];
    foreach ($recentSalesStats as $row) {
        $dt = DateTime::createFromFormat('Y-m', $row['month']);
        $chartLabels[]   = $dt ? $dt->format('M y') : $row['month'];
        $chartCounts[]   = (int)   $row['count'];
        $chartRevenues[] = (float) $row['revenue'];
    }
    $recentTotalOrders  = array_sum($chartCounts);
    $recentTotalRevenue = array_sum($chartRevenues);
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

    <!-- Add New Product button – prominent, top-level -->
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
            <i class="fas fa-list mr-2 text-blue-500"></i>Alle Produkte
        </h2>
        <button type="button" onclick="openProductModal()"
                class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold text-base shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-105">
            <i class="fas fa-plus"></i>Neues Produkt hinzufügen
        </button>
    </div>

    <!-- Dashboard grid: product list + sales chart sidebar -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- Product list (2/3 width on xl) -->
        <div class="xl:col-span-2 card rounded-xl shadow-lg p-6">
            <?php if (empty($products)): ?>
            <div class="flex flex-col items-center justify-center py-16 text-gray-400 dark:text-gray-500">
                <i class="fas fa-box-open text-5xl mb-4 opacity-40"></i>
                <p class="text-lg font-medium mb-4">Noch keine Produkte vorhanden.</p>
                <button type="button" onclick="openProductModal()"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    <i class="fas fa-plus"></i>Erstes Produkt erstellen
                </button>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm card-table">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-left">
                            <th class="pb-3 font-semibold">Bild</th>
                            <th class="pb-3 font-semibold">Name</th>
                            <th class="pb-3 font-semibold hidden md:table-cell">Beschreibung</th>
                            <th class="pb-3 font-semibold text-right">Preis</th>
                            <th class="pb-3 font-semibold text-center hidden sm:table-cell">Status</th>
                            <th class="pb-3 font-semibold text-center hidden sm:table-cell">Varianten</th>
                            <th class="pb-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($products as $product): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                            <td class="py-3 pr-3" data-label="Bild">
                                <?php
                                $thumbSrc = !empty($product['image_path'])
                                    ? asset($product['image_path'])
                                    : (!empty($product['images'][0]['image_path']) ? asset($product['images'][0]['image_path']) : null);
                                ?>
                                <?php if ($thumbSrc): ?>
                                <img src="<?php echo $thumbSrc; ?>"
                                     alt="" class="w-14 h-14 object-cover rounded-lg border border-gray-100 dark:border-gray-700">
                                <?php else: ?>
                                <div class="w-14 h-14 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-box text-gray-400 text-lg"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 font-semibold text-gray-800 dark:text-gray-100" data-label="Name">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </td>
                            <td class="py-3 text-gray-500 dark:text-gray-400 max-w-xs hidden md:table-cell" data-label="Beschreibung">
                                <span class="line-clamp-2"><?php echo htmlspecialchars($product['description'] ?? ''); ?></span>
                            </td>
                            <td class="py-3 text-right font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap" data-label="Preis">
                                <?php echo number_format((float) $product['base_price'], 2, ',', '.'); ?> €
                            </td>
                            <td class="py-3 text-center hidden sm:table-cell" data-label="Status">
                                <?php if ($product['active']): ?>
                                <span class="px-2 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">Aktiv</span>
                                <?php else: ?>
                                <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-full text-xs font-medium">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center hidden sm:table-cell" data-label="Varianten">
                                <?php
                                $variantCount = count($product['variants']);
                                $totalStock   = array_sum(array_column($product['variants'], 'stock_quantity'));
                                ?>
                                <div class="flex flex-col items-center">
                                    <span class="font-medium text-gray-700 dark:text-gray-300"><?php echo $variantCount; ?></span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500"><?php echo $totalStock; ?> Stk.</span>
                                </div>
                            </td>
                            <td class="py-3 text-right" data-label="Aktionen">
                                <button type="button"
                                        onclick="openProductModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-lg text-xs font-medium transition-colors">
                                    <i class="fas fa-edit"></i>Bearbeiten
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                <?php echo count($products); ?> Produkt<?php echo count($products) !== 1 ? 'e' : ''; ?> insgesamt
            </p>
            <?php endif; ?>
        </div>

        <!-- Recent sales sidebar (1/3 width on xl) -->
        <div class="xl:col-span-1 flex flex-col gap-6">

            <!-- KPI mini-cards -->
            <div class="grid grid-cols-2 gap-4">
                <div class="card rounded-xl shadow p-4 border-l-4 border-blue-500">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Bestellungen</p>
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($recentTotalOrders); ?></p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Letzte 6 Monate</p>
                </div>
                <div class="card rounded-xl shadow p-4 border-l-4 border-green-500">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Umsatz</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($recentTotalRevenue, 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Letzte 6 Monate</p>
                </div>
            </div>

            <!-- Recent sales chart -->
            <div class="card rounded-xl shadow-lg p-5 flex-1">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">
                        <i class="fas fa-chart-line mr-1.5 text-blue-500"></i>Aktuelle Verkäufe
                    </h3>
                    <a href="<?php echo asset('pages/admin/shop_stats.php'); ?>"
                       class="text-xs text-blue-500 hover:text-blue-700 font-medium no-underline">
                        Details <i class="fas fa-arrow-right ml-0.5"></i>
                    </a>
                </div>
                <?php if (!empty($recentSalesStats)): ?>
                <div class="relative" style="height:220px">
                    <canvas id="recentSalesChart"></canvas>
                </div>
                <?php else: ?>
                <div class="flex flex-col items-center justify-center py-10 text-gray-400 dark:text-gray-500">
                    <i class="fas fa-chart-bar text-3xl mb-2 opacity-30"></i>
                    <p class="text-sm">Noch keine Verkaufsdaten</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- end dashboard grid -->

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
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden border border-gray-100 dark:border-gray-700">
            <div class="p-6 overflow-y-auto flex-1">
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
                </form>
            </div>
            <div class="flex gap-3 px-6 pb-6 pt-2">
                <button type="submit" form="order-status-form"
                        class="flex-1 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold shadow transition-all">
                    <i class="fas fa-save mr-2"></i>Speichern
                </button>
                <button type="button" onclick="closeOrderModal()"
                        class="px-4 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 font-medium transition-colors">
                    Abbrechen
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     PRODUCT MODAL (create / edit)
══════════════════════════════════════════════════════════════════════════════ -->
<div id="product-modal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden border border-gray-100 dark:border-gray-700">

        <!-- Modal header -->
        <div class="px-6 py-4 bg-gradient-to-r from-blue-600 to-blue-700 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i id="modal-header-icon" class="fas fa-plus text-white"></i>
            </div>
            <div class="flex-1">
                <h2 id="modal-title" class="text-xl font-bold text-white leading-tight">Neues Produkt anlegen</h2>
                <p class="text-blue-100 text-xs mt-0.5">Pflichtfelder sind mit <span class="text-red-300 font-semibold">*</span> markiert</p>
            </div>
            <button type="button" onclick="closeProductModal()"
                    class="text-white/70 hover:text-white transition-colors p-1 ml-2">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal body -->
        <form method="POST" enctype="multipart/form-data" id="product-form" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="post_action" value="save_product">
            <input type="hidden" name="product_id" id="modal-product-id" value="">

            <div class="p-6 overflow-y-auto flex-1">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">

                <!-- ── Left column ── -->
                <div class="space-y-5">

                    <!-- Section: Grunddaten -->
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex items-center gap-2 mb-3">
                            <i class="fas fa-info-circle text-blue-400"></i> Grunddaten
                            <span class="flex-1 border-t border-gray-200 dark:border-gray-700"></span>
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Produktname <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="name" id="modal-name" required
                                       placeholder="z.B. IBC Hoodie, Kugelschreiber, ..."
                                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Beschreibung <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                                </label>
                                <textarea name="description" id="modal-description" rows="4"
                                          placeholder="Kurze Produktbeschreibung, z.B. Material, Verwendungszweck, ..."
                                          class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Kategorie
                                </label>
                                <select name="category" id="modal-category"
                                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="">– bitte wählen –</option>
                                    <option value="Kleidung">Kleidung</option>
                                    <option value="Accessoires">Accessoires</option>
                                    <option value="Bürobedarf">Bürobedarf</option>
                                    <option value="Sonstiges">Sonstiges</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Geschlecht
                                </label>
                                <select name="gender" id="modal-gender"
                                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="Keine">Nicht zutreffend</option>
                                    <option value="Herren">Herren</option>
                                    <option value="Damen">Damen</option>
                                    <option value="Unisex">Unisex</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Hinweise <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                                </label>
                                <textarea name="hints" id="modal-hints" rows="3"
                                          placeholder="z.B. Pflegehinweise, Besonderheiten, ..."
                                          class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Abholort &amp; Zeitpunkt <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                                </label>
                                <input type="text" name="pickup_location" id="modal-pickup-location"
                                       placeholder="z.B. Nächstes Mittwochs-Meeting"
                                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Varianten <span class="font-normal text-gray-400 dark:text-gray-500">(optional, kommagetrennt)</span>
                                </label>
                                <input type="text" name="variants_text" id="modal-variants-text"
                                       placeholder="z.B. S, M, L, XL, 2XL, 3XL"
                                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                        Preis (€) <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 dark:text-gray-500 font-medium pointer-events-none">€</span>
                                        <input type="number" name="base_price" id="modal-base-price"
                                               step="0.01" min="0" required value="0.00"
                                               class="w-full pl-7 pr-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    </div>
                                </div>
                                <div class="flex flex-col justify-end">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Status</label>
                                    <label class="flex items-center gap-2.5 cursor-pointer h-[42px] px-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/40 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                        <input type="checkbox" id="modal-active" name="active" value="1" checked
                                               class="w-4 h-4 text-blue-600 rounded border-gray-300 dark:border-gray-600 focus:ring-blue-500">
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Produkt aktiv</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    SKU <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                                </label>
                                <input type="text" name="sku" id="modal-sku"
                                       placeholder="z.B. MUG-2024-ALUMNI"
                                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            </div>
                        </div>
                    </div>

                    <!-- Section: Sammelbestellung -->
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex items-center gap-2 mb-3">
                            <i class="fas fa-users text-blue-400"></i> Sammelbestellung
                            <span class="flex-1 border-t border-gray-200 dark:border-gray-700"></span>
                        </h3>
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600">
                                <input type="checkbox" id="modal-is-bulk-order" name="is_bulk_order" value="1"
                                       onchange="toggleBulkOrderFields(this.checked)"
                                       class="w-4 h-4 mt-0.5 text-blue-600 rounded border-gray-300 dark:border-gray-600 focus:ring-blue-500">
                                <div>
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Ist Sammelbestellung?</span>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                        Aktivieren für Produkte, die erst bei Erreichen einer Mindestmenge produziert werden.
                                    </p>
                                </div>
                            </label>
                            <div id="modal-bulk-fields" class="hidden space-y-3">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                        Deadline der Bestellung
                                    </label>
                                    <input type="date" name="bulk_end_date" id="modal-bulk-end-date"
                                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                        Ziel-Menge für Produktion
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" name="bulk_min_goal" id="modal-bulk-min-goal"
                                               value="" min="1"
                                               class="w-32 px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 transition">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Stück Minimum</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Right column ── -->
                <div class="space-y-5">

                    <!-- Section: Varianten -->
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex items-center gap-2 mb-3">
                            <i class="fas fa-warehouse text-blue-400"></i> Varianten
                            <span class="flex-1 border-t border-gray-200 dark:border-gray-700"></span>
                        </h3>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600 mb-3">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" id="modal-no-variants" name="no_variants" value="1"
                                       onchange="toggleVariantMode(!this.checked)"
                                       class="w-4 h-4 mt-0.5 text-purple-600 rounded border-gray-300 dark:border-gray-600 focus:ring-purple-500">
                                <div>
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Keine Varianten</span>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                        Aktivieren wenn der Artikel keine verschiedenen Ausführungen (z.B. Größe, Farbe) hat.
                                    </p>
                                </div>
                            </label>
                        </div>
                        <div id="modal-section-variants" class="hidden">
                            <div id="variants-container" class="space-y-3"></div>
                            <button type="button" id="add-variant"
                                    class="mt-3 w-full py-3 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-500 dark:text-gray-400 hover:border-purple-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-plus"></i> Neuen Varianten-Typ hinzufügen
                            </button>
                        </div>
                    </div>

                    <!-- Tips box -->
                    <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-800">
                        <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-2">
                            <i class="fas fa-lightbulb mr-1"></i>Tipps
                        </p>
                        <ul class="text-xs text-blue-600 dark:text-blue-400 space-y-1 list-none m-0 p-0">
                            <li><i class="fas fa-check-circle mr-1 text-blue-400"></i>Gib einen klaren, beschreibenden Produktnamen ein.</li>
                            <li><i class="fas fa-check-circle mr-1 text-blue-400"></i>Verwende ein Produktbild für bessere Übersicht im Shop.</li>
                            <li><i class="fas fa-check-circle mr-1 text-blue-400"></i>Setze „Produkt aktiv" nur, wenn es kaufbar sein soll.</li>
                            <li><i class="fas fa-check-circle mr-1 text-blue-400"></i>Varianten brauchst du nur für Artikel mit Größen / Farben.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ── Full-width: Produktbilder ── -->
            <div class="mt-6 pt-5 border-t border-gray-200 dark:border-gray-700">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex items-center gap-2 mb-3">
                    <i class="fas fa-images text-blue-400"></i> Produktbilder
                    <span class="flex-1 border-t border-gray-200 dark:border-gray-700"></span>
                </h3>

                <!-- Existing images grid (drag-and-drop sortable) -->
                <p id="modal-no-images-hint" class="text-xs text-gray-400 dark:text-gray-500 mb-2 hidden">Noch keine Bilder vorhanden.</p>
                <div id="modal-images-grid" class="grid grid-cols-4 sm:grid-cols-6 gap-2 mb-3"></div>

                <!-- New images upload -->
                <label for="modal-product-images" id="image-drop-zone"
                       class="flex flex-col items-center justify-center w-full h-24 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl cursor-pointer bg-gray-50 dark:bg-gray-700/30 hover:bg-gray-100 dark:hover:bg-gray-700/50 hover:border-blue-400 dark:hover:border-blue-500 transition-colors group">
                    <div class="flex flex-col items-center justify-center py-3">
                        <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 group-hover:text-blue-500 dark:group-hover:text-blue-400 mb-1 transition-colors"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors font-medium">Bilder hierher ziehen oder auswählen</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">JPG, PNG oder WebP · max. 5 MB pro Bild · Mehrfachauswahl möglich</p>
                    </div>
                    <input id="modal-product-images" type="file" name="product_images[]"
                           accept="image/jpeg,image/png,image/webp" multiple class="hidden"
                           onchange="previewNewImages(event)">
                </label>

                <!-- Preview of newly selected (not yet uploaded) images -->
                <div id="new-images-preview" class="mt-2 grid grid-cols-4 sm:grid-cols-6 gap-2 hidden"></div>
            </div>

            </div><!-- end scrollable body -->

            <!-- Modal footer -->
            <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200 dark:border-gray-700 gap-3 flex-wrap">
                <div id="modal-delete-area" class="hidden">
                    <button type="button" onclick="confirmDeleteProduct()"
                            class="inline-flex items-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 font-medium text-sm transition-colors">
                        <i class="fas fa-trash-alt"></i>Produkt löschen
                    </button>
                </div>
                <!-- "Also create for other gender" – only shown when creating a new Herren/Damen product -->
                <div id="modal-also-gender-area" class="hidden">
                    <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 dark:text-gray-300">
                        <input type="checkbox" name="also_create_other_gender" id="modal-also-gender" value="1"
                               class="w-4 h-4 text-blue-600 rounded border-gray-300 dark:border-gray-600 focus:ring-blue-500">
                        <span id="modal-also-gender-label">Auch für Damen anlegen</span>
                    </label>
                </div>
                <div class="flex gap-3 ml-auto">
                    <button type="button" onclick="closeProductModal()"
                            class="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 font-medium transition-colors">
                        Abbrechen
                    </button>
                    <button type="submit"
                            class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold transition-all shadow flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        <span id="modal-submit-label">Produkt erstellen</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Delete confirmation modal -->
<div id="delete-confirm-modal" class="hidden fixed inset-0 bg-black/70 z-[60] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden border border-gray-100 dark:border-gray-700">
        <div class="p-6 overflow-y-auto flex-1">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center shrink-0">
                    <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">Produkt löschen?</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Diese Aktion kann nicht rückgängig gemacht werden.</p>
                </div>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Soll <strong id="delete-product-name" class="text-gray-800 dark:text-gray-100"></strong> wirklich gelöscht werden? Alle Varianten werden ebenfalls entfernt.
            </p>
        </div>
        <form method="POST" id="delete-product-form" class="px-6 pb-6">
            <input type="hidden" name="post_action" value="delete_product">
            <input type="hidden" name="product_id" id="delete-product-id">
            <div class="flex gap-3">
                <button type="button" onclick="closeDeleteConfirm()"
                        class="flex-1 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 font-medium transition-colors">
                    Abbrechen
                </button>
                <button type="submit"
                        class="flex-1 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-trash-alt"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
// ── Constants ─────────────────────────────────────────────────────────────────

const INPUT_CLASS       = 'border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm focus:ring-2';
const IMAGE_ORDER_URL   = '<?php echo asset('api/shop/update_image_order.php'); ?>';

let variantCount       = 0;
let currentProductId   = null;
let currentProductName = '';
let sortableInstance   = null;

// ── Product Modal ─────────────────────────────────────────────────────────────

function openProductModal(product) {
    const isEdit = product && product.id;

    // Reset form
    document.getElementById('product-form').reset();
    document.getElementById('modal-product-id').value  = isEdit ? product.id : '';
    document.getElementById('modal-name').value        = isEdit ? (product.name || '') : '';
    document.getElementById('modal-description').value = isEdit ? (product.description || '') : '';
    document.getElementById('modal-base-price').value  = isEdit ? parseFloat(product.base_price || 0).toFixed(2) : '0.00';
    document.getElementById('modal-active').checked    = isEdit ? (product.active == 1) : true;

    // Bulk order fields
    const isBulk = isEdit && product.is_bulk_order == 1;
    document.getElementById('modal-is-bulk-order').checked = isBulk;
    document.getElementById('modal-bulk-end-date').value   = isEdit ? (product.bulk_end_date || '') : '';
    document.getElementById('modal-bulk-min-goal').value   = isEdit ? (product.bulk_min_goal || '') : '';
    toggleBulkOrderFields(isBulk);

    // New fields
    document.getElementById('modal-category').value        = isEdit ? (product.category || '') : '';
    document.getElementById('modal-gender').value          = isEdit ? (product.gender || 'Keine') : 'Keine';
    document.getElementById('modal-hints').value           = isEdit ? (product.hints || '') : '';
    document.getElementById('modal-pickup-location').value = isEdit ? (product.pickup_location || '') : '';
    document.getElementById('modal-variants-text').value   = isEdit ? (product.variants_csv || '') : '';
    document.getElementById('modal-sku').value             = isEdit ? (product.sku || '') : '';

    // "Also create for other gender" checkbox – only relevant for new products
    updateAlsoGenderArea(isEdit);

    // Header
    document.getElementById('modal-title').textContent        = isEdit ? 'Produkt bearbeiten' : 'Neues Produkt anlegen';
    document.getElementById('modal-header-icon').className    = 'fas ' + (isEdit ? 'fa-edit' : 'fa-plus') + ' text-white';
    document.getElementById('modal-submit-label').textContent = isEdit ? 'Änderungen speichern' : 'Produkt erstellen';

    // Delete area
    const deleteArea = document.getElementById('modal-delete-area');
    if (isEdit) {
        deleteArea.classList.remove('hidden');
        currentProductId   = product.id;
        currentProductName = product.name || '';
    } else {
        deleteArea.classList.add('hidden');
        currentProductId   = null;
        currentProductName = '';
    }

    // Reset new-images preview
    const newPreview = document.getElementById('new-images-preview');
    newPreview.innerHTML = '';
    newPreview.classList.add('hidden');
    document.getElementById('modal-product-images').value = '';

    // Build existing images grid
    buildImagesGrid(isEdit ? (product.images || []) : []);

    // Build variant UI
    buildVariantUI(isEdit ? (product.variants || []) : []);

    document.getElementById('product-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    document.getElementById('modal-name').focus();
}

function closeProductModal() {
    document.getElementById('product-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

// ── "Also create for other gender" toggle ────────────────────────────────────

function updateAlsoGenderArea(isEdit) {
    const area    = document.getElementById('modal-also-gender-area');
    const label   = document.getElementById('modal-also-gender-label');
    const gender  = document.getElementById('modal-gender').value;
    if (!isEdit && (gender === 'Herren' || gender === 'Damen')) {
        label.textContent = gender === 'Herren' ? 'Auch für Damen anlegen' : 'Auch für Herren anlegen';
        area.classList.remove('hidden');
    } else {
        area.classList.add('hidden');
        document.getElementById('modal-also-gender').checked = false;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var genderSelect = document.getElementById('modal-gender');
    if (genderSelect) {
        genderSelect.addEventListener('change', function() {
            var isEdit = !!document.getElementById('modal-product-id').value;
            updateAlsoGenderArea(isEdit);
        });
    }
});

// ── Bulk order fields toggle ──────────────────────────────────────────────────

function toggleBulkOrderFields(show) {
    document.getElementById('modal-bulk-fields').classList.toggle('hidden', !show);
}

// ── Existing images grid ──────────────────────────────────────────────────────

function buildImagesGrid(images) {
    const grid = document.getElementById('modal-images-grid');
    const hint = document.getElementById('modal-no-images-hint');
    grid.innerHTML = '';

    if (sortableInstance) {
        sortableInstance.destroy();
        sortableInstance = null;
    }

    if (!images || images.length === 0) {
        hint.classList.remove('hidden');
        return;
    }
    hint.classList.add('hidden');

    images.forEach(img => {
        const item = document.createElement('div');
        item.className = 'relative group cursor-grab active:cursor-grabbing';
        item.dataset.id = img.id;
        item.innerHTML = `
            <img src="${escapeHtml(img.url || img.image_path)}" alt=""
                 class="w-full aspect-square object-cover rounded-lg border border-gray-200 dark:border-gray-600 shadow-sm">
            <button type="button"
                    onclick="deleteProductImage(${parseInt(img.id)}, this.closest('[data-id]'))"
                    class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center hover:bg-red-600 transition shadow text-xs opacity-0 group-hover:opacity-100">
                <i class="fas fa-times"></i>
            </button>
            <div class="absolute bottom-1 left-1 w-5 h-5 bg-black/30 rounded flex items-center justify-center opacity-0 group-hover:opacity-100 transition pointer-events-none">
                <i class="fas fa-grip-vertical text-white text-xs"></i>
            </div>`;
        grid.appendChild(item);
    });

    sortableInstance = new Sortable(grid, {
        animation: 150,
        ghostClass: 'opacity-40',
        onEnd: saveImageOrder,
    });
}

function saveImageOrder() {
    const grid  = document.getElementById('modal-images-grid');
    const items = grid.querySelectorAll('[data-id]');
    const orders = Array.from(items).map((el, index) => ({
        id:         parseInt(el.dataset.id),
        sort_order: index,
    }));

    fetch(IMAGE_ORDER_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reorder', orders }),
    })
    .then(r => r.json())
    .catch(err => console.error('Reihenfolge konnte nicht gespeichert werden:', err));
}

function deleteProductImage(imageId, element) {
    if (!confirm('Bild löschen?')) return;
    fetch(IMAGE_ORDER_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: imageId }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && element) {
            element.remove();
            const grid = document.getElementById('modal-images-grid');
            if (grid.children.length === 0) {
                document.getElementById('modal-no-images-hint').classList.remove('hidden');
            }
            saveImageOrder();
        } else if (!data.success) {
            alert('Bild konnte nicht gelöscht werden.');
        }
    })
    .catch(err => {
        console.error('Bild-Löschen fehlgeschlagen:', err);
        alert('Bild konnte nicht gelöscht werden.');
    });
}

// ── New images preview ────────────────────────────────────────────────────────

function previewNewImages(event) {
    const files   = event.target.files;
    const preview = document.getElementById('new-images-preview');
    preview.innerHTML = '';

    if (!files || files.length === 0) {
        preview.classList.add('hidden');
        return;
    }

    preview.classList.remove('hidden');
    Array.from(files).forEach(file => {
        const reader = new FileReader();
        reader.onload = function (e) {
            const wrapper = document.createElement('div');
            wrapper.className = 'relative';
            wrapper.innerHTML = `
                <img src="${e.target.result}" alt=""
                     class="w-full aspect-square object-cover rounded-lg border-2 border-blue-300 dark:border-blue-600 shadow-sm">
                <span class="absolute bottom-1 left-1 text-xs bg-blue-500 text-white rounded px-1">Neu</span>`;
            preview.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
    });
}

// ── Drag-and-drop image upload ────────────────────────────────────────────────

(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const dz    = document.getElementById('image-drop-zone');
        const input = document.getElementById('modal-product-images');
        if (!dz || !input) return;

        dz.addEventListener('dragenter', function (e) {
            e.preventDefault();
            dz.classList.add('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
        });

        dz.addEventListener('dragover', function (e) {
            e.preventDefault();
        });

        dz.addEventListener('dragleave', function (e) {
            if (e.relatedTarget === null || !dz.contains(e.relatedTarget)) {
                dz.classList.remove('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
            }
        });

        dz.addEventListener('drop', function (e) {
            e.preventDefault();
            dz.classList.remove('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
            if (e.dataTransfer.files.length > 0) {
                const dt = new DataTransfer();
                Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
                input.files = dt.files;
                previewNewImages({ target: input });
            }
        });
    });
}());

// ── Variant UI ────────────────────────────────────────────────────────────────

function buildVariantUI(variants) {
    variantCount = 0;
    const container = document.getElementById('variants-container');
    container.innerHTML = '';

    const groups = {};
    variants.forEach(v => {
        if (v.type !== '' || v.value !== '') {
            if (!groups[v.type]) groups[v.type] = [];
            groups[v.type].push(v);
        }
    });

    const hasNamedVariants = Object.keys(groups).length > 0;

    if (hasNamedVariants) {
        Object.keys(groups).forEach(typeName => {
            container.appendChild(createVariantBlock(variantCount++, typeName, groups[typeName]));
        });
        document.getElementById('modal-no-variants').checked = false;
        document.getElementById('modal-section-variants').classList.remove('hidden');
    } else {
        document.getElementById('modal-no-variants').checked = true;
        document.getElementById('modal-section-variants').classList.add('hidden');
    }
}

function createVariantBlock(idx, typeName, values) {
    const block = document.createElement('div');
    block.className = 'variant-block border border-gray-200 dark:border-gray-600 rounded-xl p-3 bg-gray-50 dark:bg-gray-700/50';
    block.innerHTML = `
        <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-300 shrink-0">
                <i class="fas fa-tag text-xs"></i>
            </span>
            <input type="text" name="variants[${idx}][name]"
                   value="${escapeHtml(typeName)}"
                   placeholder="Varianten-Typ (z.B. Größe, Farbe)"
                   class="flex-1 px-3 py-1.5 border border-purple-200 dark:border-purple-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 text-sm font-medium focus:ring-2 focus:ring-purple-500 transition">
            <button type="button" onclick="this.closest('.variant-block').remove()"
                    class="text-red-400 hover:text-red-600 p-1.5 ml-1 transition-colors rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20" title="Typ entfernen">
                <i class="fas fa-trash-alt text-xs"></i>
            </button>
        </div>
        <div class="value-rows space-y-2 ml-9">
            ${(values || [{value:'',stock_quantity:0}]).map((v, vi) => valueRowHtml(idx, vi, v.value || '', v.stock_quantity || 0)).join('')}
        </div>
        <button type="button" onclick="addValueRow(this, ${idx})"
                class="mt-2 ml-9 text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
            <i class="fas fa-plus"></i> Ausprägung hinzufügen
        </button>`;
    return block;
}

function valueRowHtml(vIdx, valIdx, value, stock) {
    return `<div class="value-row flex gap-2 items-center">
        <span class="text-xs text-gray-400 dark:text-gray-500 w-12 shrink-0 text-right">Wert</span>
        <input type="text" name="variants[${vIdx}][values][${valIdx}][value]"
               value="${escapeHtml(value)}" placeholder="z.B. Rot, XL ..."
               class="flex-1 min-w-0 px-2 py-1.5 ${INPUT_CLASS} focus:ring-blue-500">
        <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">Stk.</span>
        <input type="number" name="variants[${vIdx}][values][${valIdx}][stock]"
               value="${parseInt(stock) || 0}" min="0"
               class="w-16 px-2 py-1.5 ${INPUT_CLASS} focus:ring-blue-500">
        <button type="button" onclick="this.closest('.value-row').remove()"
                class="text-red-400 hover:text-red-600 p-1 transition-colors rounded hover:bg-red-50 dark:hover:bg-red-900/20" title="Ausprägung entfernen">
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>`;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('add-variant').addEventListener('click', function () {
    const idx   = variantCount++;
    const block = createVariantBlock(idx, '', [{value:'',stock_quantity:0}]);
    document.getElementById('variants-container').appendChild(block);
    block.querySelector('input[type="text"]').focus();
});

function addValueRow(btn, vIdx) {
    const valueContainer = btn.previousElementSibling;
    const nextIdx        = valueContainer.querySelectorAll('.value-row').length;
    valueContainer.insertAdjacentHTML('beforeend', valueRowHtml(vIdx, nextIdx, '', 0));
}

function toggleVariantMode(hasVariants) {
    document.getElementById('modal-section-variants').classList.toggle('hidden', !hasVariants);
    if (hasVariants && document.getElementById('variants-container').children.length === 0) {
        document.getElementById('variants-container').appendChild(
            createVariantBlock(variantCount++, '', [{value:'',stock_quantity:0}])
        );
    }
}

// ── Delete product ────────────────────────────────────────────────────────────

function confirmDeleteProduct() {
    if (!currentProductId) return;
    document.getElementById('delete-product-name').textContent = currentProductName;
    document.getElementById('delete-product-id').value         = currentProductId;
    document.getElementById('delete-confirm-modal').classList.remove('hidden');
}

function closeDeleteConfirm() {
    document.getElementById('delete-confirm-modal').classList.add('hidden');
}

// ── Order modal ───────────────────────────────────────────────────────────────

function openOrderModal(order) {
    document.getElementById('modal-order-id').value          = order.id;
    document.getElementById('modal-payment-status').value    = order.payment_status;
    document.getElementById('modal-shipping-status').value   = order.shipping_status;
    document.getElementById('order-modal').classList.remove('hidden');
}

function closeOrderModal() {
    document.getElementById('order-modal').classList.add('hidden');
}

// ── Backdrop & ESC close ──────────────────────────────────────────────────────

document.getElementById('product-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeProductModal();
});
document.getElementById('order-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeOrderModal();
});
document.getElementById('delete-confirm-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeDeleteConfirm();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeDeleteConfirm();
        closeProductModal();
        closeOrderModal();
    }
});

// ── Auto-open on page load ────────────────────────────────────────────────────

<?php if ($openModal && $editProduct): ?>
openProductModal(<?php
    $imagesForJs = array_map(function($img) {
        return [
            'id'         => $img['id'],
            'sort_order' => $img['sort_order'],
            'url'        => !empty($img['image_path']) ? asset($img['image_path']) : '',
        ];
    }, $editProduct['images'] ?? []);
    echo json_encode([
        'id'            => $editProduct['id'],
        'name'          => $editProduct['name'],
        'description'   => $editProduct['description'],
        'base_price'    => $editProduct['base_price'],
        'active'        => $editProduct['active'],
        'is_bulk_order' => $editProduct['is_bulk_order'],
        'bulk_end_date' => $editProduct['bulk_end_date'],
        'bulk_min_goal' => $editProduct['bulk_min_goal'],
        'category'      => $editProduct['category'],
        'pickup_location' => $editProduct['pickup_location'],
        'sku'           => $editProduct['sku'],
        'image_path'    => !empty($editProduct['image_path']) ? asset($editProduct['image_path']) : '',
        'images'        => $imagesForJs,
        'variants'      => $editProduct['variants'],
    ]);
?>);
<?php elseif ($openModal): ?>
openProductModal();
<?php endif; ?>
</script>

<?php if ($section === 'products' && !empty($recentSalesStats)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const isDark     = document.documentElement.classList.contains('dark-mode');
    const gridColor  = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    const textColor  = isDark ? '#e5e7eb' : '#374151';
    const labels     = <?php echo json_encode($chartLabels); ?>;
    const revenues   = <?php echo json_encode($chartRevenues); ?>;

    new Chart(document.getElementById('recentSalesChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Umsatz (€)',
                data: revenues,
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.parsed.y.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })
                    }
                }
            },
            scales: {
                x: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor } },
                y: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor }, beginAtZero: true }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
