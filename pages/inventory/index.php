<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get sync results from session and clear them
$syncResult = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);

// Get search / filter parameters
$search = trim($_GET['search'] ?? '');

// Load inventory objects via Inventory model (includes SUM-based rental quantities)
$inventoryObjects = [];
$loadError = null;
try {
    $filters = [];
    if ($search !== '') {
        $filters['search'] = $search;
    }
    $inventoryObjects = Inventory::getAll($filters);
} catch (Exception $e) {
    $loadError = $e->getMessage();
    error_log('Inventory index: fetch failed: ' . $e->getMessage());
}

// Flash messages from checkout redirects
$checkoutSuccess = $_SESSION['checkout_success'] ?? null;
$checkoutError   = $_SESSION['checkout_error']   ?? null;
unset($_SESSION['checkout_success'], $_SESSION['checkout_error']);


$title = 'Inventar - IBC Intranet';
ob_start();
?>

<?php if ($checkoutSuccess): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($checkoutSuccess); ?>
</div>
<?php endif; ?>

<?php if ($checkoutError): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($checkoutError); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-boxes text-purple-600 mr-3"></i>
                Inventar
            </h1>
            <p class="text-slate-600 dark:text-slate-400 text-lg"><?php echo count($inventoryObjects); ?> Artikel verfügbar</p>
        </div>
        <!-- Action Buttons -->
        <div class="flex gap-2 flex-wrap">
            <button type="button" onclick="window.location.reload()" class="px-4 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-xl transition-all transform hover:scale-105 shadow-lg font-semibold flex items-center" title="Ansicht aktualisieren" aria-label="Aktualisieren">
                <i class="fas fa-rotate-right"></i>
            </button>
            <?php if (AuthHandler::isAdmin()): ?>
            <a href="sync.php" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-5 py-3 rounded-xl flex items-center shadow-lg font-semibold transition-all transform hover:scale-105">
                <i class="fas fa-sync-alt mr-2"></i> EasyVerein Sync
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sync Results -->
<?php if ($syncResult): ?>
<div class="mb-6 p-4 rounded-lg bg-blue-100 border border-blue-400 text-blue-700">
    <div class="flex items-start">
        <i class="fas fa-sync-alt mr-3 mt-1"></i>
        <div class="flex-1">
            <p class="font-semibold">EasyVerein Synchronisierung abgeschlossen</p>
            <ul class="mt-2 text-sm">
                <li>&#10003; Erstellt: <?php echo htmlspecialchars($syncResult['created']); ?> Artikel</li>
                <li>&#10003; Aktualisiert: <?php echo htmlspecialchars($syncResult['updated']); ?> Artikel</li>
                <li>&#10003; Archiviert: <?php echo htmlspecialchars($syncResult['archived']); ?> Artikel</li>
            </ul>
            <?php if (!empty($syncResult['errors'])): ?>
            <details class="mt-2">
                <summary class="cursor-pointer text-sm underline">Fehler anzeigen (<?php echo count($syncResult['errors']); ?>)</summary>
                <ul class="mt-2 list-disc list-inside text-sm">
                    <?php foreach ($syncResult['errors'] as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search Bar -->
<div class="card p-5 mb-8 shadow-lg border border-gray-200 dark:border-slate-700">
    <form method="GET" class="flex gap-3">
        <div class="flex-1">
            <label class="block text-sm font-semibold text-slate-900 dark:text-slate-100 mb-2 flex items-center">
                <i class="fas fa-search mr-2 text-purple-600"></i>Suche
            </label>
            <input
                type="text"
                name="search"
                placeholder="Artikelname oder Beschreibung..."
                value="<?php echo htmlspecialchars($search); ?>"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all"
            >
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-lg transition-all transform hover:scale-105 shadow-md font-semibold">
                <i class="fas fa-search mr-2"></i>Suchen
            </button>
            <?php if ($search !== ''): ?>
            <a href="index.php" class="px-4 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                <i class="fas fa-times"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- API Load Error -->
<?php if ($loadError): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-triangle mr-2"></i>
    <strong>Fehler beim Laden der Inventardaten:</strong> <?php echo htmlspecialchars($loadError); ?>
</div>
<?php endif; ?>

<!-- Inventory Grid -->
<?php if (empty($inventoryObjects) && !$loadError): ?>
<div class="card p-12 text-center">
    <i class="fas fa-inbox text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
    <p class="text-slate-900 dark:text-slate-100 text-lg">Keine Artikel gefunden</p>
    <?php if ($search !== ''): ?>
    <a href="index.php" class="mt-4 inline-block text-purple-600 hover:underline">Alle Artikel anzeigen</a>
    <?php elseif (AuthHandler::isAdmin()): ?>
    <a href="sync.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center mt-4">
        <i class="fas fa-sync-alt mr-2"></i> EasyVerein Sync
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <?php foreach ($inventoryObjects as $item):
        $itemId        = $item['id'] ?? '';
        $itemName      = $item['name'] ?? '';
        $itemDesc      = $item['description'] ?? '';
        $itemPieces    = (int)($item['quantity'] ?? 0);
        $itemLoaned    = $itemPieces - (int)$item['available_quantity'];
        $itemAvailable = (int)$item['available_quantity'];
        $rawImage      = $item['image_path'] ?? null;
        if ($rawImage && strpos($rawImage, 'easyverein.com') !== false) {
            $imageSrc = '/api/easyverein_image.php?url=' . urlencode($rawImage);
        } elseif ($rawImage) {
            $imageSrc = '/' . ltrim($rawImage, '/');
        } else {
            $imageSrc = null;
        }
        $hasStock = $itemAvailable > 0;
    ?>
    <div class="group inventory-item-card bg-white dark:bg-slate-800 rounded-2xl shadow-md overflow-hidden border border-gray-100 dark:border-slate-700 flex flex-col">

        <!-- Image Area -->
        <div class="relative h-48 bg-gradient-to-br from-purple-50 via-blue-50 to-indigo-50 dark:from-purple-900/30 dark:via-blue-900/30 dark:to-indigo-900/30 flex items-center justify-center overflow-hidden">
            <?php if ($imageSrc): ?>
            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($itemName); ?>" class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-500" loading="lazy">
            <?php else: ?>
            <div class="relative">
                <div class="absolute inset-0 bg-gradient-to-br from-purple-200/20 to-blue-200/20 dark:from-purple-800/20 dark:to-blue-800/20 rounded-full blur-2xl"></div>
                <i class="fas fa-box-open text-gray-300 dark:text-gray-600 text-6xl relative z-10" aria-label="Kein Bild verfügbar"></i>
            </div>
            <?php endif; ?>

            <!-- Availability Badge (top-right) -->
            <div class="absolute top-3 right-3">
                <?php if ($hasStock): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-full bg-green-500 text-white shadow-lg">
                    <i class="fas fa-check-circle"></i><?php echo $itemAvailable; ?> verfügbar
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-full bg-red-500 text-white shadow-lg">
                    <i class="fas fa-times-circle"></i>Vergriffen
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Content -->
        <div class="p-5 flex flex-col flex-1">
            <h3 class="font-bold text-slate-900 dark:text-white text-lg mb-2 line-clamp-2 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors" title="<?php echo htmlspecialchars($itemName); ?>">
                <?php echo htmlspecialchars($itemName); ?>
            </h3>

            <?php if ($itemDesc !== ''): ?>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4 line-clamp-3 flex-1" title="<?php echo htmlspecialchars($itemDesc); ?>">
                <?php echo htmlspecialchars($itemDesc); ?>
            </p>
            <?php else: ?>
            <div class="flex-1"></div>
            <?php endif; ?>

            <!-- Stock Info (Bestand | Ausgeliehen | Verfügbar) -->
            <div class="flex items-center justify-between mb-4 p-3 rounded-xl <?php echo $hasStock ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'; ?>">
                <span class="text-xs font-semibold <?php echo $hasStock ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'; ?> flex items-center gap-1.5">
                    <i class="fas fa-cubes"></i>
                    Bestand: <?php echo $itemPieces; ?> | Ausgeliehen: <?php echo $itemLoaned; ?> | Verfügbar: <?php echo $itemAvailable; ?>
                </span>
            </div>

            <!-- Action Button -->
            <?php if ($hasStock): ?>
            <button
                type="button"
                id="cartBtn-<?php echo htmlspecialchars($itemId); ?>"
                onclick="toggleCartItem(<?php echo htmlspecialchars(json_encode([
                    'id'       => (string)$itemId,
                    'name'     => $itemName,
                    'imageSrc' => $imageSrc ?? '',
                    'pieces'   => $itemAvailable,
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                class="w-full py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl font-bold text-sm transition-all transform hover:scale-[1.02] shadow-md hover:shadow-lg flex items-center justify-center gap-2"
            >
                <i class="fas fa-cart-plus"></i>In den Warenkorb
            </button>
            <?php else: ?>
            <button
                type="button"
                disabled
                class="w-full py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-400 dark:text-slate-500 rounded-xl font-bold text-sm cursor-not-allowed flex items-center justify-center gap-2"
            >
                <i class="fas fa-ban"></i>Aktuell vergriffen
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ─── Floating Cart Button ─── -->
<button id="cartFloatingBtn"
        onclick="openCartPanel()"
        style="display:none"
        class="fixed bottom-6 right-6 z-40 w-16 h-16 bg-gradient-to-br from-purple-600 to-blue-600 text-white rounded-full shadow-2xl hover:shadow-purple-500/30 flex items-center justify-center transition-all hover:scale-110 focus:outline-none focus:ring-4 focus:ring-purple-300"
        aria-label="Warenkorb öffnen">
    <i class="fas fa-shopping-cart text-xl"></i>
    <span id="cartBadge"
          class="absolute -top-2 -right-2 min-w-[1.4rem] h-[1.4rem] bg-red-500 text-white text-xs font-extrabold rounded-full flex items-center justify-center px-1 shadow-lg ring-2 ring-white">
        0
    </span>
</button>

<!-- ─── Cart Overlay ─── -->
<div id="cartOverlay"
     class="fixed inset-0 z-40 hidden"
     style="background: rgba(15,23,42,0.55); backdrop-filter: blur(3px);"
     onclick="closeCartPanel()"></div>

<!-- ─── Cart Panel (right drawer) ─── -->
<div id="cartPanel"
     class="fixed top-0 right-0 h-full w-full max-w-sm z-50 flex flex-col bg-white dark:bg-slate-900 shadow-2xl"
     style="transform: translateX(100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);"
     role="dialog" aria-modal="true" aria-label="Ausleih-Warenkorb">

    <!-- Panel Header -->
    <div class="bg-gradient-to-r from-purple-600 to-blue-600 px-5 py-4 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                <i class="fas fa-shopping-cart text-white"></i>
            </div>
            <div>
                <h2 class="text-base font-bold text-white leading-tight">Ausleih-Warenkorb</h2>
                <p id="cartPanelCount" class="text-purple-100 text-xs mt-0.5">0 Artikel</p>
            </div>
        </div>
        <button onclick="closeCartPanel()"
                class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center text-white transition-colors"
                aria-label="Schließen">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Date Range + Purpose -->
    <div class="px-5 py-4 bg-purple-50 dark:bg-purple-900/20 border-b border-purple-100 dark:border-purple-800 flex-shrink-0 space-y-3">
        <p class="text-xs font-semibold text-purple-700 dark:text-purple-300 uppercase tracking-wide">
            <i class="fas fa-calendar-alt mr-1.5"></i>Ausleihzeitraum (gilt für alle Artikel)
        </p>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="cartStartDate" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">
                    Von <span class="text-red-500">*</span>
                </label>
                <input type="date" id="cartStartDate"
                       min="<?php echo date('Y-m-d'); ?>"
                       value="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-3 py-2 border border-purple-200 dark:border-purple-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-purple-400 bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
            </div>
            <div>
                <label for="cartEndDate" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">
                    Bis <span class="text-red-500">*</span>
                </label>
                <input type="date" id="cartEndDate"
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                       value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                       class="w-full px-3 py-2 border border-purple-200 dark:border-purple-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
            </div>
        </div>
        <div>
            <label for="cartPurpose" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">
                <i class="fas fa-tag mr-1"></i>Verwendungszweck <span class="text-red-500">*</span>
            </label>
            <input type="text" id="cartPurpose"
                   placeholder="z. B. Vereinsveranstaltung, Projekt…"
                   maxlength="200"
                   class="w-full px-3 py-2 border border-purple-200 dark:border-purple-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-purple-400 bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 placeholder-slate-400">
        </div>
    </div>

    <!-- Cart Items (scrollable) -->
    <div id="cartItemsList" class="flex-1 overflow-y-auto px-5 py-4 space-y-3" style="display:none"></div>

    <!-- Empty State -->
    <div id="cartEmpty" class="flex-1 flex flex-col items-center justify-center px-5 py-12 text-center">
        <div class="w-20 h-20 bg-purple-50 dark:bg-purple-900/30 rounded-full flex items-center justify-center mb-4">
            <i class="fas fa-shopping-cart text-3xl text-purple-300 dark:text-purple-600"></i>
        </div>
        <p class="text-slate-500 dark:text-slate-400 font-medium">Ihr Warenkorb ist leer</p>
        <p class="text-slate-400 dark:text-slate-500 text-sm mt-1">Klicken Sie auf „In den Warenkorb"</p>
    </div>

    <!-- Panel Footer -->
    <div class="px-5 pb-6 pt-4 flex-shrink-0 border-t border-gray-100 dark:border-slate-700 space-y-3">
        <!-- Status messages -->
        <div id="cartMsg" class="hidden rounded-xl px-4 py-3 text-sm font-medium"></div>
        <!-- Info hint -->
        <div class="flex items-start gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800 rounded-xl px-3 py-2.5">
            <i class="fas fa-info-circle text-amber-500 text-xs mt-0.5 flex-shrink-0"></i>
            <p class="text-xs text-amber-700 dark:text-amber-300">
                Anfragen werden mit Status <strong>Ausstehend</strong> gespeichert und vom Vorstand geprüft.
            </p>
        </div>
        <!-- Submit (full-width, large CTA) -->
        <button type="button" id="cartSubmitBtn" onclick="submitCartRequests()"
                class="w-full py-4 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 disabled:from-gray-400 disabled:to-gray-400 disabled:cursor-not-allowed text-white rounded-2xl font-extrabold text-lg transition-all shadow-xl hover:shadow-2xl transform hover:scale-[1.02] disabled:scale-100 disabled:shadow-none flex items-center justify-center gap-3">
            <i class="fas fa-paper-plane text-xl"></i>
            <span id="cartSubmitLabel">Anfrage senden</span>
        </button>
        <!-- Clear -->
        <button type="button" onclick="clearCart()"
                class="w-full py-2.5 bg-gray-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500 rounded-xl transition-colors text-sm font-semibold border border-gray-200 dark:border-slate-700 flex items-center justify-center gap-2"
                title="Warenkorb leeren">
            <i class="fas fa-trash-alt text-xs"></i> Warenkorb leeren
        </button>
    </div>
</div>

<style>
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
@keyframes cart-pop {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.2); }
    100% { transform: scale(1); }
}
.cart-pop { animation: cart-pop 0.3s ease; }
/* Badge pop when item is added */
@keyframes badge-pop {
    0%   { transform: scale(1); }
    30%  { transform: scale(1.55); }
    65%  { transform: scale(0.88); }
    100% { transform: scale(1); }
}
.badge-pop { animation: badge-pop 0.38s cubic-bezier(0.36,0.07,0.19,0.97); }
/* Pulsing glow on submit button */
@keyframes submit-glow {
    0%, 100% { box-shadow: 0 6px 24px rgba(124,58,237,0.25), 0 2px 6px rgba(37,99,235,0.15); }
    50%       { box-shadow: 0 10px 36px rgba(124,58,237,0.45), 0 3px 14px rgba(37,99,235,0.3); }
}
#cartSubmitBtn:not([disabled]) { animation: submit-glow 3s ease-in-out infinite; }
/* Cart item slide-in + hover highlight */
@keyframes cart-slide-in {
    from { opacity: 0; transform: translateX(10px); }
    to   { opacity: 1; transform: translateX(0); }
}
.cart-item-card { animation: cart-slide-in 0.2s ease both; }
.cart-item-card:hover { background: #f5f3ff !important; }
.dark .cart-item-card:hover { background: rgba(109,40,217,0.12) !important; }
@media (prefers-reduced-motion: reduce) {
    .cart-pop, .badge-pop, .cart-item-card { animation: none; }
    #cartPanel { transition: none !important; }
    #cartSubmitBtn { animation: none !important; }
}
</style>

<script>
(function () {
    'use strict';

    var cart      = [];
    var panelOpen = false;
    var csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

    // ── Cart item toggle ─────────────────────────────────────────────────────
    window.toggleCartItem = function (item) {
        var idx = cart.findIndex(function (c) { return c.id === item.id; });
        if (idx === -1) {
            cart.push({ id: item.id, name: item.name, imageSrc: item.imageSrc || '', pieces: item.pieces, quantity: 1 });
            animateBadge();
        } else {
            cart.splice(idx, 1);
        }
        updateCartUI();
        updateCardButton(item.id);
    };

    window.removeFromCart = function (id) {
        cart = cart.filter(function (c) { return c.id !== id; });
        updateCartUI();
        updateCardButton(id);
    };

    window.updateCartQty = function (id, delta) {
        var item = cart.find(function (c) { return c.id === id; });
        if (!item) return;
        var newQty = item.quantity + delta;
        if (newQty < 1) { window.removeFromCart(id); return; }
        if (newQty > item.pieces) newQty = item.pieces;
        item.quantity = newQty;
        if (panelOpen) renderCartItems();
    };

    window.clearCart = function () {
        var ids = cart.map(function (c) { return c.id; });
        cart = [];
        ids.forEach(updateCardButton);
        updateCartUI();
    };

    // ── Panel open / close ───────────────────────────────────────────────────
    window.openCartPanel = function () {
        panelOpen = true;
        document.getElementById('cartOverlay').classList.remove('hidden');
        document.getElementById('cartPanel').style.transform = 'translateX(0)';
        document.body.style.overflow = 'hidden';
        renderCartItems();
        hideCartMsg();
    };

    window.closeCartPanel = function () {
        panelOpen = false;
        document.getElementById('cartOverlay').classList.add('hidden');
        document.getElementById('cartPanel').style.transform = 'translateX(100%)';
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panelOpen) closeCartPanel();
    });

    // ── UI helpers ───────────────────────────────────────────────────────────
    function updateCartUI() {
        var count      = cart.length;
        var badge      = document.getElementById('cartBadge');
        var floatBtn   = document.getElementById('cartFloatingBtn');
        var panelCount = document.getElementById('cartPanelCount');
        var submitLbl  = document.getElementById('cartSubmitLabel');
        var submitBtn  = document.getElementById('cartSubmitBtn');

        badge.textContent         = count;
        floatBtn.style.display    = count > 0 ? 'flex' : 'none';
        if (panelCount) panelCount.textContent = count + ' Artikel';
        if (submitLbl)  submitLbl.textContent  = count > 1 ? count + ' Anfragen senden' : 'Anfrage senden';
        if (submitBtn)  submitBtn.disabled     = count === 0;
        if (panelOpen)  renderCartItems();
    }

    function renderCartItems() {
        var list  = document.getElementById('cartItemsList');
        var empty = document.getElementById('cartEmpty');
        if (!list) return;

        if (cart.length === 0) {
            list.style.display = 'none';
            if (empty) empty.style.display = 'flex';
            list.innerHTML = '';
            return;
        }

        list.style.display = '';
        if (empty) empty.style.display = 'none';

        list.innerHTML = cart.map(function (item) {
            var safeSrc = isSafeImageSrc(item.imageSrc) ? item.imageSrc : '';
            var thumbInner = safeSrc
                ? '<img src="' + escHtml(safeSrc) + '" alt="' + escHtml(item.name) + '" '
                  + 'class="w-full h-full object-contain" loading="lazy">'
                : '<span class="flex w-full h-full items-center justify-center">'
                  + '<i class="fas fa-box-open text-gray-300 dark:text-gray-600 text-base"></i></span>';

            return '<div class="cart-item-card bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm overflow-hidden">'
                + '<div class="flex items-center gap-3 px-3 py-3">'
                // Tiny thumbnail with quantity badge overlay
                + '<div class="relative flex-shrink-0">'
                + '<div class="w-12 h-12 rounded-xl overflow-hidden bg-gradient-to-br from-purple-50 to-blue-50 dark:from-purple-900/30 dark:to-blue-900/30 border border-purple-100 dark:border-purple-800/50 flex items-center justify-center shadow-sm">'
                + thumbInner + '</div>'
                + '<span class="absolute -top-2 -right-2 min-w-[1.35rem] h-[1.35rem] bg-gradient-to-br from-purple-600 to-blue-500 text-white text-[10px] font-extrabold rounded-full flex items-center justify-center px-1 shadow ring-2 ring-white dark:ring-slate-800">'
                + item.quantity + '</span>'
                + '</div>'
                // Info + quantity stepper
                + '<div class="flex-1 min-w-0">'
                + '<p class="font-semibold text-slate-900 dark:text-white text-sm leading-snug mb-2 truncate" title="' + escHtml(item.name) + '">' + escHtml(item.name) + '</p>'
                + '<div class="flex items-center gap-1.5">'
                + '<button data-action="dec" data-id="' + escHtml(item.id) + '" '
                + 'class="w-7 h-7 rounded-lg bg-gray-100 dark:bg-slate-700 hover:bg-purple-100 dark:hover:bg-purple-900/50 text-slate-600 dark:text-slate-300 flex items-center justify-center transition-colors">'
                + '<i class="fas fa-minus text-[10px]"></i></button>'
                + '<span class="min-w-[2rem] text-center text-sm font-extrabold text-slate-900 dark:text-white bg-gray-50 dark:bg-slate-700 rounded-lg py-0.5 px-1.5 border border-gray-200 dark:border-slate-600">' + item.quantity + '</span>'
                + '<button data-action="inc" data-id="' + escHtml(item.id) + '" '
                + 'class="w-7 h-7 rounded-lg bg-gray-100 dark:bg-slate-700 hover:bg-purple-100 dark:hover:bg-purple-900/50 text-slate-600 dark:text-slate-300 flex items-center justify-center transition-colors">'
                + '<i class="fas fa-plus text-[10px]"></i></button>'
                + '<span class="text-[11px] text-slate-400 dark:text-slate-500">von ' + escHtml(String(item.pieces)) + '</span>'
                + '</div>'
                + '</div>'
                // Remove button
                + '<button data-action="remove" data-id="' + escHtml(item.id) + '" '
                + 'class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-xl text-gray-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors" '
                + 'aria-label="Entfernen"><i class="fas fa-trash-alt text-xs"></i></button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    // Event delegation for cart item controls (avoids inline onclick XSS risk)
    document.getElementById('cartItemsList').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;
        var id     = btn.dataset.id;
        if (action === 'remove') window.removeFromCart(id);
        if (action === 'dec')    window.updateCartQty(id, -1);
        if (action === 'inc')    window.updateCartQty(id,  1);
    });

    function updateCardButton(id) {
        var btn = document.getElementById('cartBtn-' + id);
        if (!btn) return;
        var inCart   = cart.some(function (c) { return c.id === id; });
        var baseClass = 'w-full py-2.5 text-white rounded-xl font-bold text-sm transition-all transform hover:scale-[1.02] shadow-md hover:shadow-lg flex items-center justify-center gap-2';
        if (inCart) {
            btn.className = baseClass + ' bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700';
            btn.innerHTML = '<i class="fas fa-check"></i>Im Warenkorb';
        } else {
            btn.className = baseClass + ' bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700';
            btn.innerHTML = '<i class="fas fa-cart-plus"></i>In den Warenkorb';
        }
    }

    function animateBadge() {
        var btn   = document.getElementById('cartFloatingBtn');
        var badge = document.getElementById('cartBadge');
        if (!btn) return;
        btn.classList.remove('cart-pop');
        btn.offsetWidth; // reflow to restart animation
        btn.classList.add('cart-pop');
        if (badge) {
            badge.classList.remove('badge-pop');
            badge.offsetWidth;
            badge.classList.add('badge-pop');
        }
    }

    function isSafeImageSrc(src) {
        return typeof src === 'string' && /^(https?:\/\/|\/).+/i.test(src);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Submit all cart requests ─────────────────────────────────────────────
    window.submitCartRequests = function () {
        if (cart.length === 0) return;

        var startDate = document.getElementById('cartStartDate').value;
        var endDate   = document.getElementById('cartEndDate').value;
        var purpose   = (document.getElementById('cartPurpose').value || '').trim();

        if (!startDate || !endDate) {
            showCartMsg('Bitte Zeitraum auswählen.', 'error');
            return;
        }
        if (startDate > endDate) {
            showCartMsg('Startdatum muss vor dem Enddatum liegen.', 'error');
            return;
        }
        if (!purpose) {
            showCartMsg('Bitte Verwendungszweck angeben.', 'error');
            document.getElementById('cartPurpose').focus();
            return;
        }

        var btn = document.getElementById('cartSubmitBtn');
        btn.disabled  = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xl mr-2"></i>Wird gesendet...';
        hideCartMsg();

        var promises = cart.map(function (item) {
            return fetch('/api/inventory_request.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    action:              'submit_request',
                    inventory_object_id: item.id,
                    start_date:          startDate,
                    end_date:            endDate,
                    quantity:            item.quantity,
                    purpose:             purpose,
                    csrf_token:          csrfToken
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) { return { item: item, data: data }; })
            .catch(function (err) {
                console.error('Cart request failed for item ' + item.id + ':', err);
                return { item: item, data: { success: false, message: 'Netzwerkfehler' } };
            });
        });

        Promise.all(promises).then(function (results) {
            var failed = results.filter(function (r) { return !r.data.success; });
            if (failed.length === 0) {
                btn.innerHTML = '<i class="fas fa-check text-xl mr-2"></i>Gesendet!';
                showCartMsg('Alle Anfragen erfolgreich eingereicht!', 'success');
                setTimeout(function () {
                    clearCart();
                    closeCartPanel();
                    btn.disabled  = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane text-xl mr-2"></i><span id="cartSubmitLabel">Anfrage senden</span>';
                }, 2200);
            } else {
                var errDetails = failed.map(function (r) {
                    return r.item.name + (r.data.message ? ': ' + r.data.message : '');
                }).join('; ');
                showCartMsg('Fehler: ' + errDetails, 'error');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-paper-plane text-xl mr-2"></i><span id="cartSubmitLabel">Erneut versuchen</span>';
            }
        });
    };

    function showCartMsg(text, type) {
        var el = document.getElementById('cartMsg');
        el.textContent = text;
        el.className = 'rounded-xl px-4 py-3 text-sm font-medium ' +
            (type === 'success'
                ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 border border-green-300 dark:border-green-700'
                : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-300 dark:border-red-700');
        el.classList.remove('hidden');
    }

    function hideCartMsg() {
        var el = document.getElementById('cartMsg');
        if (el) el.classList.add('hidden');
    }

}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
