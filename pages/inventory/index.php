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
    <div class="group bg-white dark:bg-slate-800 rounded-2xl shadow-md overflow-hidden hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 border border-gray-100 dark:border-slate-700 flex flex-col">

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
                onclick="openRentalModal(<?php echo htmlspecialchars(json_encode([
                    'id'     => (string)$itemId,
                    'name'   => $itemName,
                    'pieces' => $itemAvailable,
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                class="w-full py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl font-bold text-sm transition-all transform hover:scale-[1.02] shadow-md hover:shadow-lg flex items-center justify-center gap-2"
            >
                <i class="fas fa-calendar-plus"></i>Jetzt anfragen
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

<!-- Rental Request Modal -->
<div id="rentalModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.70); backdrop-filter: blur(4px);" role="dialog" aria-modal="true" aria-labelledby="rentalModalTitle">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden animate-modal-in">

        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-purple-600 to-blue-600 px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fas fa-calendar-plus text-white text-lg"></i>
                </div>
                <div>
                    <h2 id="rentalModalTitle" class="text-xl font-bold text-white leading-tight">Artikel anfragen</h2>
                    <p id="rentalModalItemName" class="text-purple-100 text-xs mt-0.5 truncate max-w-[200px]"></p>
                </div>
            </div>
            <button onclick="closeRentalModal()" class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center text-white transition-colors" aria-label="Schließen">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Form Body (scrollable) -->
        <div class="px-6 py-5 space-y-5 overflow-y-auto flex-1">

            <!-- Availability indicator -->
            <div id="availabilityInfo" class="hidden items-center gap-3 p-3 rounded-xl border text-sm">
                <i class="fas fa-info-circle flex-shrink-0"></i>
                <span id="availabilityText"></span>
            </div>

            <!-- Date Row -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="rentalStartDate" class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                        <i class="fas fa-calendar-day mr-1 text-purple-500"></i>Von <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="date"
                        id="rentalStartDate"
                        required
                        min="<?php echo date('Y-m-d'); ?>"
                        value="<?php echo date('Y-m-d'); ?>"
                        class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 transition-all"
                    >
                </div>
                <div>
                    <label for="rentalEndDate" class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                        <i class="fas fa-calendar-check mr-1 text-blue-500"></i>Bis <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="date"
                        id="rentalEndDate"
                        required
                        min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 transition-all"
                    >
                </div>
            </div>

            <!-- Quantity -->
            <div>
                <label for="rentalQuantity" class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                    <i class="fas fa-cubes mr-1 text-green-500"></i>Stückzahl <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <input
                        type="number"
                        id="rentalQuantity"
                        required
                        min="1"
                        value="1"
                        class="w-full px-4 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 transition-all pr-20"
                        placeholder="Anzahl"
                    >
                    <span id="rentalMaxLabel" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-slate-500 pointer-events-none"></span>
                </div>
            </div>

            <!-- Info hint -->
            <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800 rounded-xl px-4 py-3">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <p class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">
                    Ihre Anfrage wird mit dem Status <strong>Ausstehend</strong> gespeichert und vom Vorstand geprüft.
                </p>
            </div>

            <!-- Error / Success message inside modal -->
            <div id="rentalModalMsg" class="hidden rounded-xl px-4 py-3 text-sm font-medium"></div>

        </div>

        <!-- Footer (fixed buttons) -->
        <div class="px-6 pb-5 pt-3 flex gap-3">
            <button type="button" onclick="closeRentalModal()" class="flex-1 px-4 py-2.5 bg-gray-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors font-semibold text-sm border border-gray-200 dark:border-slate-700">
                <i class="fas fa-times mr-1.5"></i>Abbrechen
            </button>
            <button type="button" id="rentalSubmitBtn" onclick="submitRentalRequest()" class="flex-1 px-4 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl transition-all font-semibold text-sm shadow-md hover:shadow-lg transform hover:scale-[1.02] flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane"></i>Anfrage senden
            </button>
        </div>
    </div>
</div>

<style>
@keyframes modal-in {
    from { opacity: 0; transform: scale(0.95) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-modal-in { animation: modal-in 0.2s ease-out; }
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
@media (prefers-reduced-motion: reduce) {
    .animate-modal-in { animation: none; }
}
</style>

<script>
(function () {
    'use strict';

    var currentItemId   = '';
    var currentItemName = '';
    var currentPieces   = 0;
    var checkAvailTimer = null;
    var csrfToken       = <?php echo json_encode(CSRFHandler::getToken()); ?>;

    window.openRentalModal = function (item) {
        currentItemId   = item.id;
        currentItemName = item.name;
        currentPieces   = item.pieces;

        document.getElementById('rentalModalItemName').textContent = item.name;
        document.getElementById('rentalMaxLabel').textContent      = 'max: ' + item.pieces;
        document.getElementById('rentalQuantity').max              = item.pieces;
        document.getElementById('rentalQuantity').value            = '1';

        hideModalMsg();
        hideAvailability();

        var modal = document.getElementById('rentalModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        scheduleAvailabilityCheck();
        document.getElementById('rentalStartDate').focus();
    };

    window.closeRentalModal = function () {
        var modal = document.getElementById('rentalModal');
        modal.classList.add('hidden');
        modal.style.display = '';
        document.body.style.overflow = 'auto';
        hideModalMsg();

        var btn = document.getElementById('rentalSubmitBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>Anfrage senden';
    };

    document.getElementById('rentalModal').addEventListener('click', function (e) {
        if (e.target === this) closeRentalModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeRentalModal();
    });

    ['rentalStartDate', 'rentalEndDate'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', scheduleAvailabilityCheck);
    });

    function scheduleAvailabilityCheck() {
        clearTimeout(checkAvailTimer);
        checkAvailTimer = setTimeout(checkAvailability, 400);
    }

    function checkAvailability() {
        if (!currentItemId) return;
        var startDate = document.getElementById('rentalStartDate').value;
        var endDate   = document.getElementById('rentalEndDate').value;
        if (!startDate || !endDate || startDate > endDate) return;

        fetch('/api/inventory_request.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:              'check_availability',
                inventory_object_id: currentItemId,
                start_date:          startDate,
                end_date:            endDate,
                csrf_token:          csrfToken
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success !== undefined) {
                showAvailability(data.success ? data.available : 0, data.success, data.total ?? null);
            }
        })
        .catch(function () {});
    }

    function showAvailability(avail, success, total) {
        var el  = document.getElementById('availabilityInfo');
        var txt = document.getElementById('availabilityText');
        var btn = document.getElementById('rentalSubmitBtn');
        var qty = document.getElementById('rentalQuantity');

        el.className = 'flex items-center gap-3 p-3 rounded-xl border text-sm ';
        var availLabel = total !== null ? avail + ' / ' + total : avail;
        if (success && avail > 0) {
            txt.textContent = 'Verfügbar: ' + availLabel + ' Stück';
            el.className   += 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-700 dark:text-green-300';
            btn.disabled    = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            txt.textContent = 'Für diesen Zeitraum nicht verfügbar' + (total !== null ? ' (Bestand: 0 / ' + total + ')' : '') + '.';
            el.className   += 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-300';
            btn.disabled    = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
        }
        qty.max = avail;
        document.getElementById('rentalMaxLabel').textContent = 'max: ' + avail;
        el.classList.remove('hidden');
        el.style.display = 'flex';
    }

    window.submitRentalRequest = function () {
        var startDate = document.getElementById('rentalStartDate').value;
        var endDate   = document.getElementById('rentalEndDate').value;
        var quantity  = parseInt(document.getElementById('rentalQuantity').value, 10);

        if (!startDate || !endDate) {
            showModalMsg('Bitte Zeitraum auswählen.', 'error');
            return;
        }
        if (startDate > endDate) {
            showModalMsg('Startdatum muss vor dem Enddatum liegen.', 'error');
            return;
        }
        if (!quantity || quantity < 1) {
            showModalMsg('Bitte eine gültige Stückzahl eingeben.', 'error');
            return;
        }

        var btn = document.getElementById('rentalSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>Wird gesendet...';

        fetch('/api/inventory_request.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:              'submit_request',
                inventory_object_id: currentItemId,
                start_date:          startDate,
                end_date:            endDate,
                quantity:            quantity,
                csrf_token:          csrfToken
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showModalMsg(data.message || 'Anfrage erfolgreich eingereicht!', 'success');
                btn.innerHTML = '<i class="fas fa-check mr-1.5"></i>Gesendet';
                setTimeout(closeRentalModal, 2500);
            } else {
                showModalMsg(data.message || 'Unbekannter Fehler.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>Anfrage senden';
            }
        })
        .catch(function () {
            showModalMsg('Netzwerkfehler. Bitte erneut versuchen.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>Anfrage senden';
        });
    };

    function showModalMsg(text, type) {
        var el = document.getElementById('rentalModalMsg');
        el.textContent = text;
        el.className = 'rounded-xl px-4 py-3 text-sm font-medium ' +
            (type === 'success'
                ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 border border-green-300 dark:border-green-700'
                : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-300 dark:border-red-700');
        el.classList.remove('hidden');
    }

    function hideModalMsg() {
        document.getElementById('rentalModalMsg').classList.add('hidden');
    }

    function hideAvailability() {
        var el = document.getElementById('availabilityInfo');
        el.classList.add('hidden');
        el.style.display = '';
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
