<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/JobBoard.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user   = Auth::user();
$userId = (int)$user['id'];

// Load the listing and verify ownership
$listingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$listing   = $listingId > 0 ? JobBoard::getById($listingId) : null;

if (!$listing || (int)$listing['user_id'] !== $userId) {
    $_SESSION['error_message'] = 'Das Gesuch wurde nicht gefunden oder du hast keine Berechtigung, es zu bearbeiten.';
    header('Location: index.php');
    exit;
}

$errors      = [];
$title       = $listing['title'];
$searchType  = $listing['search_type'];
$description = $listing['description'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $title       = trim($_POST['title'] ?? '');
    $searchType  = trim($_POST['search_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $removePdf   = isset($_POST['remove_pdf']) && $_POST['remove_pdf'] === '1';

    // Validate required fields
    if (empty($title)) {
        $errors[] = 'Bitte geben Sie einen Titel ein.';
    }

    if (empty($searchType) || !in_array($searchType, JobBoard::SEARCH_TYPES, true)) {
        $errors[] = 'Bitte wählen Sie einen gültigen Typ aus.';
    }

    if (empty($description)) {
        $errors[] = 'Bitte geben Sie eine Beschreibung ein.';
    }

    $newPdfPath  = null;
    $updatePdf   = false;

    // Handle PDF upload (optional but strictly validated)
    if (isset($_FILES['cv_pdf']) && $_FILES['cv_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['cv_pdf'];

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Die hochgeladene Datei ist zu groß. Maximum: 5 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Fehler beim Hochladen der Datei (Code: ' . (int)$file['error'] . ').';
        } else {
            // Size check (5 MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Die Datei überschreitet die maximale Größe von 5 MB.';
            }

            // MIME type check via finfo
            if (empty($errors)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                if ($mime !== 'application/pdf') {
                    $errors[] = 'Die hochgeladene Datei ist keine gültige PDF-Datei.';
                }
            }

            // Magic-bytes check – PDF starts with "%PDF"
            if (empty($errors)) {
                $handle = fopen($file['tmp_name'], 'rb');
                $magic  = fread($handle, 4);
                fclose($handle);
                if ($magic !== '%PDF') {
                    $errors[] = 'Die Datei enthält keine gültigen PDF-Daten.';
                }
            }

            // Move file if all checks passed
            if (empty($errors)) {
                $uploadDir = __DIR__ . '/../../uploads/jobs/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (!is_writable($uploadDir)) {
                    $errors[] = 'Das Upload-Verzeichnis ist nicht beschreibbar.';
                } else {
                    $safeName = bin2hex(random_bytes(16)) . '.pdf';
                    $destPath = $uploadDir . $safeName;
                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        $newPdfPath = 'uploads/jobs/' . $safeName;
                        $updatePdf  = true;
                    } else {
                        $errors[] = 'Die Datei konnte nicht gespeichert werden.';
                    }
                }
            }
        }
    } elseif ($removePdf) {
        $updatePdf = true; // will clear the path
    }

    if (empty($errors)) {
        $data = [
            'title'       => $title,
            'search_type' => $searchType,
            'description' => $description,
        ];

        if ($updatePdf) {
            $data['pdf_path'] = $newPdfPath; // null when removing
        }

        $clearPdf = $updatePdf && $newPdfPath === null;

        $updated = JobBoard::updateByOwner($listingId, $userId, $data, $clearPdf);

        if ($updated) {
            // Delete old PDF file if it was replaced or removed
            if ($updatePdf && !empty($listing['pdf_path'])) {
                $oldFile = __DIR__ . '/../../' . $listing['pdf_path'];
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }

            $_SESSION['success_message'] = 'Dein Gesuch wurde erfolgreich aktualisiert!';
            header('Location: index.php');
            exit;
        } else {
            // DB update failed – clean up the newly uploaded file to avoid orphaned files
            if ($newPdfPath !== null) {
                $uploadedFile = __DIR__ . '/../../' . $newPdfPath;
                if (file_exists($uploadedFile)) {
                    unlink($uploadedFile);
                }
            }
            $errors[] = 'Das Gesuch konnte nicht aktualisiert werden. Bitte versuche es erneut.';
        }
    }
}

$pageTitle = 'Gesuch bearbeiten - IBC Intranet';
ob_start();
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="index.php" class="text-blue-600 hover:text-blue-700 inline-flex items-center mb-4">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zur Job- &amp; Praktikumsbörse
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg space-y-1">
        <?php foreach ($errors as $error): ?>
        <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-edit text-blue-600 mr-2"></i>
                Gesuch bearbeiten
            </h1>
            <p class="text-gray-600 dark:text-gray-300 mt-2">
                Bearbeite dein Gesuch und aktualisiere deine Angaben.
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Titel <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    name="title"
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($title); ?>"
                    placeholder="z.B. Suche Praktikum im Bereich Marketing"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <!-- Search Type -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Gesuchter Typ <span class="text-red-500">*</span>
                </label>
                <select
                    name="search_type"
                    required
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
                    <option value="">-- Typ wählen --</option>
                    <?php foreach (JobBoard::SEARCH_TYPES as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>"
                        <?php echo $searchType === $type ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beschreibung <span class="text-red-500">*</span>
                </label>
                <textarea
                    name="description"
                    required
                    rows="6"
                    placeholder="Beschreibe, wonach du suchst, deine Qualifikationen, Verfügbarkeit usw."
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                    style="resize: vertical; min-height: 120px;"
                ><?php echo htmlspecialchars($description); ?></textarea>
            </div>

            <!-- PDF Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Lebenslauf hochladen (optional)
                </label>
                <?php if (!empty($listing['pdf_path'])): ?>
                <div class="mb-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                        <i class="fas fa-file-pdf text-red-500"></i>
                        Aktueller Lebenslauf vorhanden
                    </span>
                    <label class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 cursor-pointer">
                        <input type="checkbox" name="remove_pdf" value="1" class="rounded">
                        Lebenslauf entfernen
                    </label>
                </div>
                <?php endif; ?>
                <input
                    type="file"
                    name="cv_pdf"
                    accept=".pdf,application/pdf"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    <i class="fas fa-shield-alt mr-1 text-green-500"></i>
                    Ausschließlich <strong>.pdf</strong>-Dateien erlaubt. Maximum: <strong>5 MB</strong>. Alle anderen Formate werden abgelehnt.
                    <?php if (!empty($listing['pdf_path'])): ?>
                    Eine neue Datei ersetzt den bestehenden Lebenslauf.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Submit -->
            <div class="flex justify-end space-x-4 pt-4">
                <a href="index.php"
                   class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </a>
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i>Änderungen speichern
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
