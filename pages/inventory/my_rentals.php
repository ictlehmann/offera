<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/database.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)Auth::getUserId();

// Load rental requests for the current user from inventory_requests (new flow).
$rentals = [];
try {
    $db   = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT id, inventory_object_id AS easyverein_item_id, quantity, start_date AS rented_at, end_date, status, created_at
           FROM inventory_requests
          WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')
          ORDER BY created_at DESC"
    );
    $stmt->execute([$userId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('my_rentals: inventory_requests query failed: ' . $e->getMessage());
}

// Also load from legacy inventory_rentals table if it exists.
try {
    $db   = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT id, easyverein_item_id, quantity, rented_at, NULL AS end_date, status, rented_at AS created_at
           FROM inventory_rentals
          WHERE user_id = ? AND status IN ('active', 'pending_return')
          ORDER BY rented_at DESC"
    );
    $stmt->execute([$userId]);
    $legacyRentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rentals = array_merge($rentals, $legacyRentals);
} catch (Exception $e) {
    // Table may not exist in the new schema – silently ignore
}

// Batch-resolve item names from EasyVerein to avoid N+1 API calls.
$itemNames = [];
if (!empty($rentals)) {
    try {
        foreach (Inventory::getAll() as $evItem) {
            $eid = (string)($evItem['easyverein_id'] ?? $evItem['id'] ?? '');
            if ($eid !== '') {
                $itemNames[$eid] = $evItem['name'] ?? '';
            }
        }
    } catch (Exception $e) {
        error_log('my_rentals: EasyVerein fetch failed: ' . $e->getMessage());
    }
}

// Check for success messages
$successMessage = $_SESSION['rental_success'] ?? null;
unset($_SESSION['rental_success']);

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
            <p class="text-gray-600"><?php echo count($rentals); ?> aktive Ausleihen</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="index.php" class="btn-primary inline-block">
                <i class="fas fa-plus-circle mr-2"></i>
                Neuen Gegenstand ausleihen
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

<!-- Active Rentals -->
<div class="card p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-hourglass-half text-blue-600 mr-2"></i>
        Aktive Ausleihen
    </h2>
    
    <?php if (empty($rentals)): ?>
    <div class="text-center py-8">
        <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg mb-4">Keine aktiven Ausleihen</p>
        <a href="index.php" class="btn-primary inline-block">
            <i class="fas fa-search mr-2"></i>Artikel ausleihen
        </a>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
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
                <?php foreach ($rentals as $rental): ?>
                <?php
                $easyvereinId = (string)$rental['easyverein_item_id'];
                $itemName     = $itemNames[$easyvereinId] ?? ('Artikel #' . $easyvereinId);
                $quantity     = (int)$rental['quantity'];
                $rentedAt     = $rental['rented_at']
                    ? date('d.m.Y', strtotime($rental['rented_at']))
                    : '-';
                $endDate      = !empty($rental['end_date'])
                    ? date('d.m.Y', strtotime($rental['end_date']))
                    : null;
                $status       = $rental['status'];
                $isAwaitingApproval = $status === 'pending';
                $isAwaitingReturn   = $status === 'pending_return';
                // Legacy rentals use 'active'; new requests use 'approved'
                $isActive     = ($status === 'active' || $status === 'approved');
                $isEarlyReturn = $isActive && !empty($rental['end_date']) && strtotime($rental['end_date']) > strtotime('today');
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <span class="font-semibold text-gray-800">
                            <?php echo htmlspecialchars($itemName); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        <span class="font-semibold"><?php echo $quantity; ?></span> Stück
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        <?php echo htmlspecialchars($rentedAt); ?>
                        <?php if ($endDate): ?>
                        <span class="text-gray-400"> – <?php echo htmlspecialchars($endDate); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($isAwaitingApproval): ?>
                        <span class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded-full">
                            Ausstehend
                        </span>
                        <?php elseif ($isAwaitingReturn): ?>
                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-full">
                            Rückgabe ausstehend
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-full">
                            Aktiv
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($isAwaitingApproval): ?>
                        <span class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">
                            <i class="fas fa-clock mr-1"></i>Wartet auf Genehmigung
                        </span>
                        <?php elseif ($isAwaitingReturn): ?>
                        <span class="inline-flex items-center px-3 py-1 bg-yellow-100 text-yellow-800 rounded text-sm font-medium">
                            <i class="fas fa-clock mr-1"></i>Wartet auf Bestätigung durch den Vorstand
                        </span>
                        <?php elseif ($isActive && $status === 'active'): ?>
                        <?php $confirmMsg = $isEarlyReturn ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.' : 'Rückgabe für diesen Artikel melden?'; ?>
                        <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                            <input type="hidden" name="request_return" value="1">
                            <input type="hidden" name="rental_id" value="<?php echo (int)$rental['id']; ?>">
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-1 <?php echo $isEarlyReturn ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white rounded transition text-sm">
                                <i class="fas fa-undo mr-1"></i><?php echo $isEarlyReturn ? 'Frühere Rückgabe' : 'Zurückgeben'; ?>
                            </button>
                        </form>
                        <?php elseif ($isActive && $status === 'approved'): ?>
                        <?php $confirmMsg = $isEarlyReturn ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.' : 'Rückgabe jetzt melden? Der Vorstand wird benachrichtigt.'; ?>
                        <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                            <input type="hidden" name="request_return_approved" value="1">
                            <input type="hidden" name="request_id" value="<?php echo (int)$rental['id']; ?>">
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-1 <?php echo $isEarlyReturn ? 'bg-red-600 hover:bg-red-700' : 'bg-orange-600 hover:bg-orange-700'; ?> text-white rounded transition text-sm">
                                <i class="fas fa-undo mr-1"></i><?php echo $isEarlyReturn ? 'Frühere Rückgabe' : 'Zurückgeben'; ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 bg-green-100 text-green-800 rounded text-sm font-medium">
                            <i class="fas fa-check mr-1"></i>Genehmigt
                        </span>
                        <?php endif; ?>
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
