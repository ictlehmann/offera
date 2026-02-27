<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/services/EasyVereinInventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Parse checkout date and quantity from the EasyVerein note field.
// assignItem writes: "⏳ [dd.mm.YYYY HH:mm] AUSGELIEHEN: Nx an <memberId>. <purpose>"
function parseCheckoutNote(string $note): array {
    $info = ['quantity' => 1, 'date' => null];
    if (preg_match('/⏳ \[(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2})\] AUSGELIEHEN: (\d+)x/', $note, $m)) {
        $info['date']     = $m[1];
        $info['quantity'] = (int)$m[2];
    }
    return $info;
}

try {
    $evi            = new EasyVereinInventory();
    $activeCheckouts = $evi->getMyAssignedItems(Auth::getUserId());
} catch (Exception $e) {
    error_log('EasyVereinInventory::getMyAssignedItems failed: ' . $e->getMessage());
    $activeCheckouts = [];
}

// Check for success messages
$successMessage = $_SESSION['rental_success'] ?? $_SESSION['checkin_success'] ?? null;
unset($_SESSION['rental_success'], $_SESSION['checkin_success']);

$errorMessage = $_SESSION['rental_error'] ?? null;
unset($_SESSION['rental_error']);

$title = 'Meine Ausleihen - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2">
                <i class="fas fa-clipboard-list text-purple-600 mr-2"></i>
                Meine Ausleihen
            </h1>
            <p class="text-gray-600"><?php echo count($activeCheckouts); ?> aktive Ausleihen</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="index.php" class="btn-primary inline-block">
                <i class="fas fa-box mr-2"></i>
                Zum Inventar
            </a>
        </div>
    </div>
</div>

<?php if ($successMessage): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<!-- Active Checkouts -->
<div class="card p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-hourglass-half text-blue-600 mr-2"></i>
        Aktive Ausleihen
    </h2>

    <?php if (empty($activeCheckouts)): ?>
    <div class="text-center py-8">
        <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg mb-4">Keine aktiven Ausleihen</p>
        <a href="index.php" class="btn-primary inline-block">
            <i class="fas fa-search mr-2"></i>Artikel ausleihen
        </a>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full card-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Artikel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Menge</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ausgeliehen am</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aktion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($activeCheckouts as $item): ?>
                <?php
                $noteInfo = parseCheckoutNote($item['note'] ?? $item['description'] ?? '');
                $quantity = $noteInfo['quantity'];
                $rentedAt = $noteInfo['date'];
                $unit     = $item['unit'] ?? 'Stück';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3" data-label="Artikel">
                        <a href="view.php?id=<?php echo (int)$item['id']; ?>" class="font-semibold text-purple-600 hover:text-purple-800">
                            <?php echo htmlspecialchars($item['name'] ?? ''); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Menge">
                        <span class="font-semibold"><?php echo $quantity; ?></span> <?php echo htmlspecialchars($unit); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Ausgeliehen am">
                        <?php echo $rentedAt ? htmlspecialchars($rentedAt) : '-'; ?>
                    </td>
                    <td class="px-4 py-3" data-label="Status">
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                            Aktiv
                        </span>
                    </td>
                    <td class="px-4 py-3" data-label="Aktion">
                        <button onclick="openReturnModal(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['name'] ?? '', ENT_QUOTES); ?>', <?php echo $quantity; ?>, '<?php echo htmlspecialchars($unit, ENT_QUOTES); ?>')"
                                class="inline-flex items-center px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 transition text-sm">
                            <i class="fas fa-undo mr-1"></i>Zurückgeben
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- History note -->
<div class="card p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-history text-gray-600 mr-2"></i>
        Verlauf
    </h2>
    <p class="text-gray-500 text-sm">
        <i class="fas fa-info-circle mr-1"></i>
        Der Verlauf zurückgegebener Artikel wird im EasyVerein-Logbuch der jeweiligen Artikel gespeichert.
    </p>
</div>

<!-- Return Modal -->
<div id="returnModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-undo text-green-600 mr-2"></i>
                Artikel zurückgeben
            </h2>
            <button onclick="closeReturnModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="rental.php" class="space-y-4">
            <input type="hidden" name="rental_id" id="return_rental_id" value="">
            <input type="hidden" name="return_rental" value="1">
            <input type="hidden" name="return_quantity" id="return_quantity" value="">

            <div class="bg-gray-50 p-3 rounded-lg mb-4">
                <p class="font-semibold text-gray-800" id="return_item_name"></p>
                <p class="text-sm text-gray-600">Menge: <span id="return_amount"></span> <span id="return_unit"></span></p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="return_location">
                    Ort der Rückgabe <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    name="return_location"
                    id="return_location"
                    required
                    class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                    placeholder="z.B. Lager, Büro, ..."
                >
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="return_comment">
                    Kommentar
                </label>
                <textarea
                    name="return_comment"
                    id="return_comment"
                    rows="3"
                    class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Optionale Anmerkungen zur Rückgabe..."
                ></textarea>
            </div>

            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeReturnModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-check mr-2"></i>Zurückgeben
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openReturnModal(itemId, itemName, quantity, unit) {
    document.getElementById('return_rental_id').value = itemId;
    document.getElementById('return_quantity').value = quantity;
    document.getElementById('return_item_name').textContent = itemName;
    document.getElementById('return_amount').textContent = quantity;
    document.getElementById('return_unit').textContent = unit;
    document.getElementById('returnModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('return_location').value = '';
    document.getElementById('return_comment').value = '';
    document.getElementById('return_quantity').value = '';
}

document.getElementById('returnModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeReturnModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReturnModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
