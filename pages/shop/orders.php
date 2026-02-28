<?php
/**
 * Shop – Bestellübersicht (Admin)
 * Zeigt alle Bestellungen mit Käufer, Produkt, Variante, Abholort,
 * Zahlungsstatus und Lieferstatus. Vorstände dürfen den Lieferstatus
 * umschalten; Ressortleiter/Alumni-Rollen haben nur Leserecht.
 *
 * Berechtigungen:
 *   canEdit = true  → board_finance, board_internal, board_external
 *   canEdit = false → head, alumni_board, alumni_auditor
 *   Kein Zugriff   → alle anderen Rollen
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

// ─── Access control ──────────────────────────────────────────────────────────

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';

$editRoles     = ['board_finance', 'board_internal', 'board_external'];
$readOnlyRoles = ['head', 'alumni_board', 'alumni_auditor'];

if (in_array($userRole, $editRoles, true)) {
    $canEdit = true;
} elseif (in_array($userRole, $readOnlyRoles, true)) {
    $canEdit = false;
} else {
    header('Location: ../dashboard/index.php');
    exit;
}

// ─── Handle POST: toggle delivery status ─────────────────────────────────────

$successMessage = '';
$errorMessage   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;

    if ($orderId > 0) {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("SELECT shipping_status FROM shop_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                $newStatus = ($order['shipping_status'] === 'delivered') ? 'pending' : 'delivered';
                $upd = $db->prepare("UPDATE shop_orders SET shipping_status = ? WHERE id = ?");
                $upd->execute([$newStatus, $orderId]);
                $successMessage = 'Lieferstatus für Bestellung #' . $orderId . ' aktualisiert.';
            } else {
                $errorMessage = 'Bestellung nicht gefunden.';
            }
        } catch (Exception $e) {
            error_log('shop/orders.php toggle status – ' . $e->getMessage());
            $errorMessage = 'Fehler beim Aktualisieren des Status.';
        }
    }
}

// ─── Load orders (content DB) ─────────────────────────────────────────────────

$orders = [];
try {
    $db   = Database::getContentDB();
    $stmt = $db->query("
        SELECT
            o.id                   AS order_id,
            o.user_id,
            o.payment_status,
            o.shipping_status,
            o.created_at,
            oi.id                  AS item_id,
            p.name                 AS product_name,
            p.pickup_location,
            v.type                 AS variant_type,
            v.value                AS variant_value
        FROM shop_orders o
        JOIN shop_order_items oi ON oi.order_id = o.id
        JOIN shop_products    p  ON p.id = oi.product_id
        LEFT JOIN shop_variants v ON v.id = oi.variant_id
        ORDER BY o.created_at DESC, o.id DESC, oi.id ASC
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('shop/orders.php load orders – ' . $e->getMessage());
    $errorMessage = $errorMessage ?: 'Bestellungen konnten nicht geladen werden.';
}

// ─── Load buyer names (user DB) ───────────────────────────────────────────────

$buyerNames = [];
if (!empty($orders)) {
    $userIds = array_unique(array_column($orders, 'user_id'));
    try {
        $userDb = Database::getUserDB();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $uStmt = $userDb->prepare("
            SELECT id,
                   TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS full_name,
                   email
            FROM users
            WHERE id IN ($placeholders)
        ");
        $uStmt->execute($userIds);
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $name = trim($u['full_name']);
            $buyerNames[(int) $u['id']] = $name !== '' ? $name : $u['email'];
        }
    } catch (Exception $e) {
        error_log('shop/orders.php load buyers – ' . $e->getMessage());
    }
}

// ─── Page render ─────────────────────────────────────────────────────────────

$title = 'Bestellübersicht – IBC Intranet';
ob_start();
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-extrabold bg-gradient-to-r from-emerald-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-shopping-bag text-emerald-600 mr-3"></i>
                Bestellübersicht
            </h1>
            <p class="text-slate-600 dark:text-slate-400 text-lg"><?php echo count(array_unique(array_column($orders, 'order_id'))); ?> Bestellung(en) gefunden</p>
        </div>
    </div>
</div>

<?php if ($successMessage): ?>
<div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-300 dark:border-green-700 text-green-800 dark:text-green-300 rounded-xl flex items-center gap-3">
    <i class="fas fa-check-circle text-green-500"></i>
    <span><?php echo htmlspecialchars($successMessage); ?></span>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-800 dark:text-red-300 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-red-500"></i>
    <span><?php echo htmlspecialchars($errorMessage); ?></span>
</div>
<?php endif; ?>

<!-- Orders Table -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
    <?php if (empty($orders)): ?>
    <div class="p-12 text-center text-gray-500 dark:text-gray-400">
        <i class="fas fa-inbox text-4xl mb-4 block"></i>
        <p class="text-lg font-medium">Noch keine Bestellungen vorhanden.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Käufer</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produkt</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Variante</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Abholort</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Zahlungsstatus</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Lieferstatus</th>
                    <?php if ($canEdit): ?>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aktion</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($orders as $row): ?>
                <?php
                    $buyerName     = $buyerNames[(int) $row['user_id']] ?? ('User #' . $row['user_id']);
                    $paymentStatus = $row['payment_status'];
                    $shippingStatus = $row['shipping_status'];

                    // Payment badge
                    if ($paymentStatus === 'paid') {
                        $payBadgeClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                        $payLabel      = 'Bezahlt';
                    } elseif ($paymentStatus === 'failed') {
                        $payBadgeClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
                        $payLabel      = 'Fehlgeschlagen';
                    } else {
                        $payBadgeClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
                        $payLabel      = 'Offen';
                    }

                    // Delivery badge
                    if ($shippingStatus === 'delivered') {
                        $delBadgeClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                        $delLabel      = 'Ausgeliefert';
                    } elseif ($shippingStatus === 'shipped') {
                        $delBadgeClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                        $delLabel      = 'Versendet';
                    } else {
                        $delBadgeClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                        $delLabel      = 'Offen';
                    }

                    $variantText = '';
                    if (!empty($row['variant_type']) && !empty($row['variant_value'])) {
                        $variantText = htmlspecialchars($row['variant_type']) . ': ' . htmlspecialchars($row['variant_value']);
                    }
                ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">
                        <?php echo htmlspecialchars($buyerName); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                        <?php echo htmlspecialchars($row['product_name']); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        <?php echo $variantText !== '' ? $variantText : '–'; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        <?php echo !empty($row['pickup_location']) ? htmlspecialchars($row['pickup_location']) : '–'; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $payBadgeClass; ?>">
                            <?php echo htmlspecialchars($payLabel); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $delBadgeClass; ?>">
                            <?php echo htmlspecialchars($delLabel); ?>
                        </span>
                    </td>
                    <?php if ($canEdit): ?>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <?php echo CSRFHandler::getTokenField(); ?>
                            <input type="hidden" name="order_id" value="<?php echo (int) $row['order_id']; ?>">
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                                        <?php echo $shippingStatus === 'delivered'
                                            ? 'bg-yellow-100 hover:bg-yellow-200 text-yellow-800 dark:bg-yellow-900/30 dark:hover:bg-yellow-900/50 dark:text-yellow-300'
                                            : 'bg-green-100 hover:bg-green-200 text-green-800 dark:bg-green-900/30 dark:hover:bg-green-900/50 dark:text-green-300'; ?>">
                                <i class="fas <?php echo $shippingStatus === 'delivered' ? 'fa-undo' : 'fa-check'; ?>"></i>
                                <?php echo $shippingStatus === 'delivered' ? 'Als offen markieren' : 'Als ausgeliefert markieren'; ?>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
