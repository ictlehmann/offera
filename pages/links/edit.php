<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Link.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';
if (!Link::canManage($userRole)) {
    header('Location: index.php');
    exit;
}

$linkId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$link = null;
$isEdit = false;

if ($linkId) {
    $link = Link::getById($linkId);
    if (!$link) {
        $_SESSION['error_message'] = 'Link nicht gefunden.';
        header('Location: index.php');
        exit;
    }
    $isEdit = true;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $title      = trim($_POST['title'] ?? '');
    $url        = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon       = trim($_POST['icon'] ?? 'fas fa-link');
    $sortOrder  = 0;

    if (empty($title)) {
        $errors[] = 'Bitte geben Sie einen Titel ein.';
    }
    if (empty($url)) {
        $errors[] = 'Bitte geben Sie eine URL ein.';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte geben Sie eine gültige URL ein (z.B. https://beispiel.de).';
    } else {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'])) {
            $errors[] = 'Nur http:// und https:// URLs sind erlaubt.';
        }
    }
    if (empty($icon)) {
        $icon = 'fas fa-link';
    }

    if (empty($errors)) {
        $data = [
            'title'       => $title,
            'url'         => $url,
            'description' => $description ?: null,
            'icon'        => $icon,
            'sort_order'  => $sortOrder,
        ];

        try {
            if ($isEdit) {
                Link::update($linkId, $data);
                $_SESSION['success_message'] = 'Link erfolgreich aktualisiert!';
            } else {
                $data['created_by'] = $_SESSION['user_id'];
                Link::create($data);
                $_SESSION['success_message'] = 'Link erfolgreich erstellt!';
            }
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Pre-fill form values
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $url         = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon        = trim($_POST['icon'] ?? 'fas fa-link');
    $sortOrder   = 0;
} else {
    $title       = $link['title'] ?? '';
    $url         = $link['url'] ?? '';
    $description = $link['description'] ?? '';
    $icon        = $link['icon'] ?? 'fas fa-link';
    $sortOrder   = 0;
}

$title_page = $isEdit ? 'Link bearbeiten - IBC Intranet' : 'Neuen Link erstellen - IBC Intranet';
ob_start();
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="index.php" class="text-ibc-green hover:text-ibc-green-dark inline-flex items-center mb-4">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zu Nützliche Links
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <?php foreach ($errors as $error): ?>
            <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?> text-ibc-green mr-2"></i>
                <?php echo $isEdit ? 'Link bearbeiten' : 'Neuen Link erstellen'; ?>
            </h1>
        </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Titel *</label>
                <input
                    type="text"
                    name="title"
                    required
                    value="<?php echo htmlspecialchars($title); ?>"
                    placeholder="z.B. IBC Website"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-ibc-green dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <!-- URL -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">URL *</label>
                <input
                    type="url"
                    name="url"
                    required
                    value="<?php echo htmlspecialchars($url); ?>"
                    placeholder="https://beispiel.de"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-ibc-green dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung (optional)</label>
                <input
                    type="text"
                    name="description"
                    value="<?php echo htmlspecialchars($description); ?>"
                    placeholder="Kurze Beschreibung des Links"
                    maxlength="500"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-ibc-green dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <!-- Icon -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Icon</label>
                <input
                    type="hidden"
                    name="icon"
                    id="icon_input"
                    value="<?php echo htmlspecialchars($icon); ?>"
                >
                <!-- Icon Picker -->
                <div class="grid grid-cols-6 sm:grid-cols-8 gap-3 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700" id="icon_picker">
                    <?php
                    $iconList = [
                        'fas fa-globe', 'fas fa-envelope', 'fas fa-file', 'fas fa-chart-bar',
                        'fas fa-users', 'fas fa-video', 'fas fa-folder', 'fas fa-book',
                        'fas fa-link', 'fas fa-home', 'fas fa-cog', 'fas fa-search',
                        'fas fa-calendar', 'fas fa-clock', 'fas fa-phone', 'fas fa-map-marker-alt',
                        'fas fa-download', 'fas fa-upload', 'fas fa-print', 'fas fa-edit',
                        'fas fa-trash', 'fas fa-star', 'fas fa-heart', 'fas fa-bell',
                        'fas fa-lock', 'fas fa-key', 'fas fa-shield-alt', 'fas fa-info-circle',
                        'fas fa-question-circle', 'fas fa-laptop', 'fas fa-database', 'fas fa-server',
                    ];
                    foreach ($iconList as $ic):
                        $isSelected = ($ic === $icon);
                    ?>
                    <button
                        type="button"
                        data-icon="<?php echo htmlspecialchars($ic); ?>"
                        title="<?php echo htmlspecialchars($ic); ?>"
                        aria-label="<?php echo htmlspecialchars($ic); ?>"
                        class="icon-picker-btn flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:border-ibc-green hover:text-ibc-green transition <?php echo $isSelected ? 'ring-2 ring-ibc-green text-ibc-green border-ibc-green' : ''; ?>"
                    ><i class="<?php echo htmlspecialchars($ic); ?>" aria-hidden="true"></i></button>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Aktuell gewählt: <span id="icon_label" class="font-mono"><?php echo htmlspecialchars($icon); ?></span>
                </p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end space-x-4 pt-4">
                <a href="index.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition">
                    Abbrechen
                </a>
                <button type="submit" class="px-6 py-2 bg-gradient-to-r from-ibc-green to-ibc-green-dark text-white rounded-lg font-semibold hover:opacity-90 transition-all shadow-lg">
                    <i class="fas fa-save mr-2"></i><?php echo $isEdit ? 'Änderungen speichern' : 'Link erstellen'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Icon Picker
document.querySelectorAll('.icon-picker-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var icon = this.dataset.icon;
        document.getElementById('icon_input').value = icon;
        document.getElementById('icon_label').textContent = icon;
        document.querySelectorAll('.icon-picker-btn').forEach(function(b) {
            b.classList.remove('ring-2', 'ring-ibc-green', 'text-ibc-green', 'border-ibc-green');
        });
        this.classList.add('ring-2', 'ring-ibc-green', 'text-ibc-green', 'border-ibc-green');
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
