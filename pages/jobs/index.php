<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/JobBoard.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userId = $user['id'];

// Filter
$filterType = isset($_GET['type']) && in_array($_GET['type'], JobBoard::SEARCH_TYPES) ? $_GET['type'] : null;

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

$allListings = JobBoard::getAll($perPage + 1, $offset, $filterType);
$hasNextPage = count($allListings) > $perPage;
$listings = array_slice($allListings, 0, $perPage);

// Fetch author names from User DB
$userDb = Database::getUserDB();
$userIds = array_unique(array_column($listings, 'user_id'));
$authorNames = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $userDb->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $authorNames[$u['id']] = trim($u['first_name'] . ' ' . $u['last_name']);
    }
}

// Success/error flash messages
$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $deleteId = (int)$_POST['delete_id'];
    $listing  = JobBoard::getById($deleteId);

    if ($listing && (int)$listing['user_id'] === $userId) {
        // Delete PDF file if it exists
        if (!empty($listing['pdf_path'])) {
            $pdfFile = __DIR__ . '/../../' . $listing['pdf_path'];
            if (file_exists($pdfFile)) {
                $allowedDir = realpath(__DIR__ . '/../../uploads/jobs');
                $realFile = realpath($pdfFile);
                if ($realFile !== false && $allowedDir !== false && strpos($realFile, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                    unlink($realFile);
                }
            }
        }
        JobBoard::deleteByOwner($deleteId, $userId);
        $_SESSION['success_message'] = 'Gesuch erfolgreich gelöscht.';
    } else {
        $_SESSION['error_message'] = 'Das Gesuch konnte nicht gelöscht werden.';
    }
    header('Location: index.php' . ($filterType ? '?type=' . urlencode($filterType) : ''));
    exit;
}

// Color map for search types
$typeColors = [
    'Festanstellung'         => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    'Werksstudententätigkeit' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    'Praxissemester'         => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    'Praktikum'              => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
];

$title = 'Job- & Praktikumsbörse - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-briefcase mr-3 text-blue-600"></i>
                Job- &amp; Praktikumsbörse
            </h1>
            <p class="text-gray-600 dark:text-gray-300 leading-relaxed">Gesuche von Mitgliedern – Stelle dein Profil vor oder finde Talente</p>
        </div>
        <a href="create.php"
           class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg">
            <i class="fas fa-plus mr-2"></i>
            Gesuch aufgeben
        </a>
    </div>

    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="mb-6 card dark:bg-gray-800 p-4">
        <div class="flex items-center gap-4 flex-wrap">
            <span class="text-gray-700 dark:text-gray-300 font-semibold mr-2">
                <i class="fas fa-filter mr-2"></i>
                Typ:
            </span>
            <a href="index.php"
               class="px-4 py-2 min-h-[44px] inline-flex items-center rounded-lg font-medium transition-all <?php echo $filterType === null ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'; ?>">
                Alle
            </a>
            <?php foreach (JobBoard::SEARCH_TYPES as $type): ?>
            <a href="index.php?type=<?php echo urlencode($type); ?>"
               class="px-4 py-2 min-h-[44px] inline-flex items-center rounded-lg font-medium transition-all <?php echo $filterType === $type ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'; ?>">
                <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Listings Grid -->
    <?php if (empty($listings)): ?>
    <div class="card dark:bg-gray-800 p-8 text-center">
        <i class="fas fa-inbox text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
        <p class="text-xl text-gray-600 dark:text-gray-300">Keine Gesuche gefunden</p>
        <?php if ($filterType): ?>
        <p class="text-gray-500 dark:text-gray-400 mt-2">Versuchen Sie einen anderen Filter</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($listings as $listing): ?>
        <div class="card dark:bg-gray-800 overflow-hidden flex flex-col hover:shadow-xl transition-shadow min-w-0">
            <!-- Top accent bar based on type -->
            <div class="h-1.5 w-full <?php
                $accent = [
                    'Festanstellung'         => 'bg-blue-500',
                    'Werksstudententätigkeit' => 'bg-purple-500',
                    'Praxissemester'         => 'bg-green-500',
                    'Praktikum'              => 'bg-yellow-400',
                ];
                echo $accent[$listing['search_type']] ?? 'bg-gray-400';
            ?>"></div>

            <div class="p-6 flex-1 flex flex-col">
                <!-- Type Badge -->
                <div class="mb-3">
                    <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo $typeColors[$listing['search_type']] ?? 'bg-gray-100 text-gray-800'; ?>">
                        <?php echo htmlspecialchars($listing['search_type'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <!-- Title -->
                <h3 class="text-base sm:text-lg md:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2 break-words leading-snug">
                    <?php echo htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8'); ?>
                </h3>

                <!-- Author & Date -->
                <div class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400 mb-3">
                    <span><i class="fas fa-user-circle mr-1 text-blue-500"></i><?php echo htmlspecialchars($authorNames[$listing['user_id']] ?? 'Unbekannt', ENT_QUOTES, 'UTF-8'); ?></span>
                    <span><i class="fas fa-calendar-alt mr-1"></i><?php echo (new DateTime($listing['created_at']))->format('d.m.Y'); ?></span>
                </div>

                <!-- Description excerpt -->
                <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 flex-1 break-words leading-relaxed">
                    <?php
                        $desc = $listing['description'];
                        echo htmlspecialchars(mb_strlen($desc) > 200 ? mb_substr($desc, 0, 200) . '…' : $desc, ENT_QUOTES, 'UTF-8');
                    ?>
                </p>

                <!-- Footer -->
                <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between gap-2">
                    <?php if (!empty($listing['pdf_path'])): ?>
                    <a href="<?php echo htmlspecialchars(asset($listing['pdf_path']), ENT_QUOTES, 'UTF-8'); ?>"
                       download
                       class="inline-flex items-center px-4 py-2 min-h-[44px] bg-red-50 hover:bg-red-100 text-red-700 dark:bg-red-900/30 dark:hover:bg-red-900/50 dark:text-red-300 rounded-lg text-sm font-medium transition-all">
                        <i class="fas fa-file-download mr-2"></i>Lebenslauf
                    </a>
                    <?php else: ?>
                    <span class="text-xs text-gray-400 dark:text-gray-500 italic">Kein Lebenslauf</span>
                    <?php endif; ?>

                    <?php if ((int)$listing['user_id'] === $userId): ?>
                    <div class="flex items-center gap-4">
                        <a href="edit.php?id=<?php echo (int)$listing['id']; ?>"
                           class="inline-flex items-center px-3 py-2 min-h-[44px] bg-gray-100 hover:bg-blue-100 text-gray-500 hover:text-blue-600 dark:bg-gray-700 dark:hover:bg-blue-900/40 dark:text-gray-400 dark:hover:text-blue-400 rounded-lg text-sm transition-all">
                            <i class="fas fa-edit mr-1"></i>Bearbeiten
                        </a>
                        <form method="POST" action="index.php<?php echo $filterType ? '?type=' . urlencode($filterType) : ''; ?>"
                              onsubmit="return confirm('Gesuch wirklich löschen?');">
                            <input type="hidden" name="csrf_token" value="<?php
                                require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
                                echo CSRFHandler::getToken();
                            ?>">
                            <input type="hidden" name="delete_id" value="<?php echo (int)$listing['id']; ?>">
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-2 min-h-[44px] bg-gray-100 hover:bg-red-100 text-gray-500 hover:text-red-600 dark:bg-gray-700 dark:hover:bg-red-900/40 dark:text-gray-400 dark:hover:text-red-400 rounded-lg text-sm transition-all">
                                <i class="fas fa-trash-alt mr-1"></i>Löschen
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($page > 1 || $hasNextPage): ?>
    <div class="mt-8 flex justify-center gap-4">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?><?php echo $filterType ? '&type=' . urlencode($filterType) : ''; ?>"
           class="px-6 py-3 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-all shadow-md">
            <i class="fas fa-chevron-left mr-2"></i>Zurück
        </a>
        <?php endif; ?>
        <div class="px-6 py-3 bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-300 rounded-lg font-semibold">
            Seite <?php echo $page; ?>
        </div>
        <?php if ($hasNextPage): ?>
        <a href="?page=<?php echo $page + 1; ?><?php echo $filterType ? '&type=' . urlencode($filterType) : ''; ?>"
           class="px-6 py-3 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-all shadow-md">
            Weiter<i class="fas fa-chevron-right ml-2"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
