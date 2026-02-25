<?php
/**
 * Ideenbox - Idea Submission Form
 * Access: member, candidate, head, board
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to access ideas page
// According to Auth::canAccessPage, allowed roles are: board roles, member, candidate, head
$hasIdeasAccess = Auth::canAccessPage('ideas');
if (!$hasIdeasAccess) {
    header('Location: ../dashboard/index.php');
    exit;
}

$userRole = $user['role'] ?? '';

$successMessage = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_idea'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($title) || empty($description)) {
        $errorMessage = 'Bitte füllen Sie alle Felder aus.';
    } else {
        try {
            // Get username from email
            $username = explode('@', $user['email'])[0];
            
            // Prepare email content
            $emailBody = '<h2>Neue Idee eingereicht</h2>';
            $emailBody .= '<table class="info-table">';
            $emailBody .= '<tr><td class="info-label">Von:</td><td class="info-value">' . htmlspecialchars($username) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>';
            $emailBody .= '<tr><td class="info-label">Titel:</td><td class="info-value">' . htmlspecialchars($title) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Beschreibung:</td><td class="info-value">' . nl2br(htmlspecialchars($description)) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Datum:</td><td class="info-value">' . date('d.m.Y H:i') . ' Uhr</td></tr>';
            $emailBody .= '</table>';
            
            // Send email
            // NOTE: Email address is hardcoded as per requirements
            // For production, consider moving to config file
            $emailSent = MailService::send(
                defined('SMTP_FROM') && SMTP_FROM !== '' ? SMTP_FROM : 'vorstand@business-consulting.de',
                'Neue Idee von ' . $username,
                $emailBody
            );
            
            if ($emailSent) {
                $successMessage = 'Ihre Idee wurde erfolgreich eingereicht!';
                // Clear form
                $_POST = [];
            } else {
                $errorMessage = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
            }
        } catch (Exception $e) {
            error_log('Error in ideas/index.php: ' . $e->getMessage());
            $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}

$title = 'Ideenbox - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <i class="fas fa-lightbulb mr-3 text-yellow-400 drop-shadow-[0_0_8px_rgba(250,204,21,0.6)]"></i>
            Ideenbox
        </h1>
        <p class="text-gray-600 dark:text-gray-300">Teilen Sie Ihre Ideen und Vorschläge mit uns</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Idea Submission Form -->
    <div class="card p-8">
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-lightbulb text-yellow-500 text-xl"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Ihre Idee einreichen</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Wir freuen uns auf Ihre kreativen Vorschläge!</p>
                </div>
            </div>
        </div>
        
        <form method="POST" class="space-y-6">
            <!-- Title -->
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Titel <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="text" 
                    id="title" 
                    name="title" 
                    required
                    maxlength="200"
                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="Geben Sie Ihrer Idee einen aussagekräftigen Titel..."
                >
            </div>

            <!-- Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beschreibung <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="8"
                    required
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="Beschreiben Sie Ihre Idee so detailliert wie möglich..."
                ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle mr-1"></i>
                    Je detaillierter Ihre Beschreibung, desto besser können wir Ihre Idee bewerten.
                </p>
            </div>

            <!-- Info Box -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                    <div class="text-sm text-blue-800 dark:text-blue-300">
                        <p class="font-semibold mb-1">Hinweis:</p>
                        <p>Deine Idee wird direkt an den IBC-Vorstand sowie an ERW weitergeleitet und dort sorgfältig geprüft. Wir melden uns bei Dir, wenn wir Rückfragen haben oder sobald Deine Idee umgesetzt wird.</p>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex gap-4">
                <button 
                    type="submit"
                    name="submit_idea"
                    class="flex-1 px-6 py-3 bg-gradient-to-r from-yellow-500 to-yellow-600 text-white rounded-lg font-semibold hover:from-yellow-600 hover:to-yellow-700 transition-all shadow-lg"
                >
                    <i class="fas fa-paper-plane mr-2"></i>
                    Idee einreichen
                </button>
                <a 
                    href="<?php echo asset('pages/dashboard/index.php'); ?>"
                    class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all text-center no-underline"
                >
                    Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
