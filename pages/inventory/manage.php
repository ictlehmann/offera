<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

// Only board and manager can access
if (!Auth::check() || !Auth::hasPermission('manager')) {
    header('Location: ../auth/login.php');
    exit;
}

// Constants
define('DEFAULT_LOW_STOCK_THRESHOLD', 5);

$message = '';
$error = '';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $itemId = intval($_POST['item_id'] ?? 0);
    
    try {
        Inventory::delete($itemId);
        $message = 'Artikel erfolgreich gelöscht';
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Get filters
$filters = [];
if (!empty($_GET['category_id'])) {
    $filters['category_id'] = $_GET['category_id'];
}
if (!empty($_GET['location_id'])) {
    $filters['location_id'] = $_GET['location_id'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}
if (isset($_GET['filter']) && $_GET['filter'] === 'low_stock') {
    $filters['low_stock'] = true;
}

$items = Inventory::getAll($filters);
$categories = Inventory::getCategories();
$locations = Inventory::getLocations();

$title = 'Inventar-Verwaltung - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-cogs text-purple-600 mr-2"></i>
                Inventar-Verwaltung
            </h1>
            <p class="text-gray-600 dark:text-gray-300"><?php echo count($items); ?> Artikel gefunden</p>
        </div>
        <a href="add.php" class="btn-primary">
            <i class="fas fa-plus mr-2"></i>Neuer Artikel
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Quick Links Section -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <a href="add.php" class="card dark:bg-gray-800 p-6 hover:shadow-lg transition text-center">
        <i class="fas fa-plus-circle text-purple-600 text-4xl mb-3"></i>
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Artikel hinzufügen</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">Neuen Artikel erstellen</p>
    </a>
    <a href="../admin/categories.php" class="card dark:bg-gray-800 p-6 hover:shadow-lg transition text-center">
        <i class="fas fa-tags text-blue-600 text-4xl mb-3"></i>
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Kategorien</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">Kategorien verwalten</p>
    </a>
    <a href="../admin/locations.php" class="card dark:bg-gray-800 p-6 hover:shadow-lg transition text-center">
        <i class="fas fa-map-marker-alt text-green-600 text-4xl mb-3"></i>
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Standorte</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">Standorte verwalten</p>
    </a>
    <a href="index.php" class="card dark:bg-gray-800 p-6 hover:shadow-lg transition text-center">
        <i class="fas fa-boxes text-orange-600 text-4xl mb-3"></i>
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Inventar Übersicht</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">Alle Artikel anzeigen</p>
    </a>
</div>

<!-- Filter Section -->
<div class="card dark:bg-gray-800 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-filter text-purple-600 mr-2"></i>Filter
    </h2>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Suche</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Name oder Beschreibung" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Kategorie</label>
            <select name="category_id" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                <option value="">Alle Kategorien</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?php echo $category['id']; ?>" <?php echo (isset($_GET['category_id']) && $_GET['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($category['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Standort</label>
            <select name="location_id" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                <option value="">Alle Standorte</option>
                <?php foreach ($locations as $location): ?>
                <option value="<?php echo $location['id']; ?>" <?php echo (isset($_GET['location_id']) && $_GET['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($location['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bestand</label>
            <select name="filter" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                <option value="">Alle</option>
                <option value="low_stock" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'low_stock') ? 'selected' : ''; ?>>Niedriger Bestand</option>
            </select>
        </div>
        <div class="md:col-span-2 lg:col-span-4 flex justify-end space-x-2">
            <a href="manage.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times mr-2"></i>Zurücksetzen
            </a>
            <button type="submit" class="btn-primary">
                <i class="fas fa-search mr-2"></i>Filtern
            </button>
        </div>
    </form>
</div>

<!-- Items Grid -->
<?php if (empty($items)): ?>
<div class="card dark:bg-gray-800 p-12 text-center">
    <i class="fas fa-box-open text-gray-400 text-6xl mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Artikel gefunden</h3>
    <p class="text-gray-500 dark:text-gray-400 mb-6">Es wurden keine Artikel mit den ausgewählten Filtern gefunden.</p>
    <a href="add.php" class="btn-primary inline-block">
        <i class="fas fa-plus mr-2"></i>Ersten Artikel erstellen
    </a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($items as $item): ?>
    <div class="card dark:bg-gray-800 p-6 hover:shadow-lg transition">
        <!-- Image -->
        <?php if (!empty($item['image_path'])): ?>
        <div class="mb-4 rounded-lg overflow-hidden h-48">
            <?php
            // Check if image is from EasyVerein and needs proxy
            $imageSrc = $item['image_path'];
            if (strpos($imageSrc, 'easyverein.com') !== false) {
                // Use proxy for EasyVerein images
                $imageSrc = '/api/easyverein_image.php?url=' . urlencode($imageSrc);
            } else {
                // Local image - ensure leading slash
                $imageSrc = '/' . ltrim($imageSrc, '/');
            }
            ?>
            <img src="<?php echo htmlspecialchars($imageSrc); ?>" 
                 alt="<?php echo htmlspecialchars($item['name']); ?>" 
                 class="w-full h-full object-cover">
        </div>
        <?php endif; ?>
        
        <!-- Title -->
        <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <?php echo htmlspecialchars($item['name']); ?>
        </h3>

        <!-- Details -->
        <div class="space-y-2 mb-4 text-sm text-gray-600 dark:text-gray-300">
            <?php if (!empty($item['category_name'])): ?>
            <div class="flex items-center">
                <i class="fas fa-tag w-5 text-purple-600"></i>
                <span><?php echo htmlspecialchars($item['category_name']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($item['location_name'])): ?>
            <div class="flex items-center">
                <i class="fas fa-map-marker-alt w-5 text-purple-600"></i>
                <span><?php echo htmlspecialchars($item['location_name']); ?></span>
            </div>
            <?php endif; ?>
            <div class="flex items-center">
                <i class="fas fa-box w-5 text-purple-600"></i>
                <span>Bestand: <?php echo htmlspecialchars($item['quantity']); ?> <?php echo htmlspecialchars($item['unit'] ?? 'Stk'); ?></span>
            </div>
        </div>

        <!-- Stock Warning -->
        <?php 
        $lowStockThreshold = $item['min_stock'] ?? DEFAULT_LOW_STOCK_THRESHOLD;
        // FIX: Nutze quantity für die Warnung
        if ($item['quantity'] <= $lowStockThreshold): 
        ?>
        <div class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
            <div class="flex items-center text-sm text-yellow-800 dark:text-yellow-200">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span>Niedriger Bestand!</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex space-x-2">
            <a href="view.php?id=<?php echo $item['id']; ?>" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-center text-sm">
                <i class="fas fa-eye mr-1"></i>Ansehen
            </a>
            <a href="edit.php?id=<?php echo $item['id']; ?>" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-center text-sm">
                <i class="fas fa-edit mr-1"></i>Bearbeiten
            </a>
            <button 
                class="delete-item-btn px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm"
                data-item-id="<?php echo $item['id']; ?>"
                data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                title="Löschen"
            >
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full">
        <h3 id="deleteModalTitle" class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
            Artikel löschen
        </h3>
        <p class="text-gray-600 dark:text-gray-300 mb-6">
            Möchtest Du den Artikel "<span id="deleteItemName" class="font-semibold"></span>" wirklich löschen? 
            Diese Aktion kann nicht rückgängig gemacht werden.
        </p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="item_id" id="deleteItemId" value="">
            <input type="hidden" name="delete_item" value="1">
            <div class="flex space-x-4">
                <button type="button" id="closeDeleteModalBtn" class="flex-1 px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-trash mr-2"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete button event listeners using data attributes
document.querySelectorAll('.delete-item-btn').forEach(button => {
    button.addEventListener('click', function() {
        const itemId = this.getAttribute('data-item-id');
        const itemName = this.getAttribute('data-item-name');
        confirmDelete(itemId, itemName);
    });
});

function confirmDelete(itemId, itemName) {
    const deleteItemId = document.getElementById('deleteItemId');
    const deleteItemName = document.getElementById('deleteItemName');
    const deleteModal = document.getElementById('deleteModal');
    
    if (deleteItemId) deleteItemId.value = itemId;
    if (deleteItemName) deleteItemName.textContent = itemName;
    if (deleteModal) deleteModal.classList.remove('hidden');
}

function closeDeleteModal() {
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) deleteModal.classList.add('hidden');
}

// Close modal button
document.getElementById('closeDeleteModalBtn')?.addEventListener('click', closeDeleteModal);

// Close modal on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});

// Close modal when clicking outside
document.getElementById('deleteModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'deleteModal') {
        closeDeleteModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
