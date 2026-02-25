<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../src/MailService.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$itemId = $_GET['id'] ?? null;
if (!$itemId) {
    header('Location: index.php');
    exit;
}

$item = Inventory::getById($itemId);
if (!$item) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Handle checkout submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    // Determine where to redirect after checkout; constrain to a known safe value.
    $returnTo = ($_POST['return_to'] ?? '') === 'index' ? 'index' : 'view';
    
    $quantity = intval($_POST['quantity'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '') ?: null;
    $expectedReturn = trim($_POST['expected_return_at'] ?? $_POST['expected_return'] ?? '') ?: null;
    
    if ($quantity <= 0) {
        $error = 'Bitte geben Sie eine gültige Menge ein';
        if ($returnTo === 'index') {
            $_SESSION['checkout_error'] = $error;
            header('Location: index.php');
            exit;
        }
    } else {
        $result = Inventory::checkoutItem($itemId, $_SESSION['user_id'], $quantity, $purpose, $destination, $expectedReturn, $startDate);
        
        if ($result['success']) {
            // Send notification email to board
            $borrowerEmail = $_SESSION['user_email'] ?? 'Unbekannt';
            $safeSubject = str_replace(["\r", "\n"], '', $item['name']);
            $startDateRow = $startDate && strtotime($startDate) !== false
                ? '<tr><td>Startdatum</td><td>' . htmlspecialchars(date('d.m.Y', strtotime($startDate))) . '</td></tr>'
                : '';
            $returnRow = $expectedReturn && strtotime($expectedReturn) !== false
                ? '<tr><td>Rückgabe bis</td><td>' . htmlspecialchars(date('d.m.Y', strtotime($expectedReturn))) . '</td></tr>'
                : '';
            $emailBody = MailService::getTemplate(
                'Neue Ausleihe im Inventar',
                '<p class="email-text">Ein Mitglied hat einen Artikel aus dem Inventar ausgeliehen.</p>
                <table class="info-table">
                    <tr><td>Artikel</td><td>' . htmlspecialchars($item['name']) . '</td></tr>
                    <tr><td>Menge</td><td>' . htmlspecialchars($quantity . ' ' . ($item['unit'] ?? 'Stück')) . '</td></tr>
                    <tr><td>Ausgeliehen von</td><td>' . htmlspecialchars($borrowerEmail) . '</td></tr>
                    <tr><td>Verwendungszweck</td><td>' . htmlspecialchars($purpose) . '</td></tr>
                    <tr><td>Zielort</td><td>' . htmlspecialchars($destination ?: '-') . '</td></tr>
                    ' . $startDateRow . '
                    ' . $returnRow . '
                    <tr><td>Datum</td><td>' . date('d.m.Y H:i') . '</td></tr>
                </table>'
            );
            MailService::sendEmail(INVENTORY_BOARD_EMAIL, 'Neue Ausleihe: ' . $safeSubject, $emailBody);

            $_SESSION['checkout_success'] = $result['message'];
            if ($returnTo === 'index') {
                header('Location: index.php');
            } else {
                header('Location: view.php?id=' . $itemId);
            }
            exit;
        } else {
            $error = $result['message'];
            if ($returnTo === 'index') {
                $_SESSION['checkout_error'] = $error;
                header('Location: index.php');
                exit;
            }
        }
    }
}

$title = 'Artikel ausleihen - ' . htmlspecialchars($item['name']);
ob_start();
?>

<div class="mb-6">
    <a href="view.php?id=<?php echo $item['id']; ?>" class="text-purple-600 hover:text-purple-700 inline-flex items-center mb-4">
        <i class="fas fa-arrow-left mr-2"></i>Zurück zum Artikel
    </a>
</div>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<div class="max-w-2xl mx-auto">
    <div class="card p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-hand-holding-box text-purple-600 mr-2"></i>
            Artikel ausleihen
        </h1>

        <!-- Item Info -->
        <div class="bg-gray-50 p-4 rounded-lg mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars($item['name']); ?></h2>
                    <?php if ($item['category_name']): ?>
                    <span class="inline-block px-2 py-1 text-xs rounded-full mt-2 inline-color-badge" style="background-color: <?php echo htmlspecialchars($item['category_color']); ?>20; color: <?php echo htmlspecialchars($item['category_color']); ?>">
                        <?php echo htmlspecialchars($item['category_name']); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Verfügbarer Bestand</p>
                    <p class="text-2xl font-bold <?php echo $item['available_quantity'] <= $item['min_stock'] && $item['min_stock'] > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
                        <?php echo $item['available_quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Checkout Form -->
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="checkout" value="1">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Menge <span class="text-red-500">*</span>
                </label>
                <input 
                    type="number" 
                    name="quantity" 
                    min="1" 
                    max="<?php echo $item['available_quantity']; ?>"
                    required 
                    class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="Anzahl der auszuleihenden Artikel"
                >
                <p class="text-xs text-gray-500 mt-1">
                    Maximal verfügbar: <?php echo $item['available_quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Verwendungszweck <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    name="purpose" 
                    required 
                    class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="z.B. Veranstaltung, Projekt, Workshop"
                >
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Zielort / Verwendungsort
                </label>
                <input 
                    type="text" 
                    name="destination" 
                    class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="z.B. Konferenzraum A, Offsite-Event"
                >
                <p class="text-xs text-gray-500 mt-1">Optional: Wo wird der Artikel verwendet?</p>
            </div>

            <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            <strong>Hinweis:</strong> Der Bestand im Lager wird entsprechend reduziert. 
                            Bitte denken Sie daran, die Artikel nach der Verwendung wieder zurückzugeben.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex space-x-4">
                <a href="view.php?id=<?php echo $item['id']; ?>" class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-center">
                    Abbrechen
                </a>
                <button type="submit" class="flex-1 btn-primary">
                    <i class="fas fa-check mr-2"></i>Ausleihen bestätigen
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
