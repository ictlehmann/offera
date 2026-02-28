<?php
/**
 * Polls - List all active polls
 * Access: All authenticated users (filtered by target_groups)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? '';
// Prefer entra_roles (human-readable Graph group names); fall back to azure_roles for legacy accounts
$userAzureRoles = [];
if (!empty($user['entra_roles'])) {
    $decoded = json_decode($user['entra_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}
if (empty($userAzureRoles) && !empty($user['azure_roles'])) {
    $decoded = json_decode($user['azure_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}

// Get database connection
$db = Database::getContentDB();

// Fetch all active polls with hidden status
$stmt = $db->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
           (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id) as total_votes,
           (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
    FROM polls p
    WHERE p.is_active = 1 AND p.end_date > NOW()
    ORDER BY p.created_at DESC
");
$stmt->execute([$user['id'], $user['id']]);
$polls = $stmt->fetchAll();

// Filter polls using shared helper function
$filteredPolls = filterPollsForUser($polls, $userRole, $userAzureRoles, $user['id']);

$title = 'Umfragen - IBC Intranet';
ob_start();
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-poll mr-3 text-blue-500"></i>
                Umfragen
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Aktive Umfragen für Ihre Rolle</p>
        </div>
        
        <?php if (Auth::canCreatePolls()): ?>
        <a 
            href="<?php echo asset('pages/polls/create.php'); ?>"
            class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg no-underline"
        >
            <i class="fas fa-plus mr-2"></i>
            Neue Umfrage erstellen
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'no_permission'): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i>Keine Berechtigung für diese Umfrage.
    </div>
    <?php endif; ?>

    <?php if (empty($filteredPolls)): ?>
    <!-- No polls message -->
    <div class="card p-8 text-center">
        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-poll text-gray-400 dark:text-gray-500 text-4xl"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Keine aktiven Umfragen</h3>
        <p class="text-gray-600 dark:text-gray-300">Es sind derzeit keine Umfragen für Sie verfügbar.</p>
    </div>
    <?php else: ?>
    
    <!-- Polls Grid -->
    <div class="grid gap-6">
        <?php foreach ($filteredPolls as $poll): ?>
        <div class="card p-6 hover:shadow-lg transition-shadow">
            <div class="flex justify-between items-start mb-4">
                <div class="flex-1">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                        <?php echo htmlspecialchars($poll['title']); ?>
                    </h3>
                    <?php if (!empty($poll['description'])): ?>
                    <p class="text-gray-600 dark:text-gray-300 mb-4">
                        <?php echo nl2br(htmlspecialchars($poll['description'])); ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <!-- Status Badge -->
                <?php if ($poll['user_has_voted'] > 0): ?>
                <span class="px-3 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-sm font-semibold">
                    <i class="fas fa-check-circle mr-1"></i>
                    Abgestimmt
                </span>
                <?php else: ?>
                <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 rounded-full text-sm font-semibold">
                    <i class="fas fa-clock mr-1"></i>
                    Offen
                </span>
                <?php endif; ?>
            </div>
            
            <!-- Meta Information -->
            <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-400 mb-4">
                <div>
                    <i class="fas fa-calendar-alt mr-1"></i>
                    Endet am <?php echo formatDateTime($poll['end_date'], 'd.m.Y H:i'); ?> Uhr
                </div>
                <div>
                    <i class="fas fa-users mr-1"></i>
                    <?php echo $poll['total_votes']; ?> Stimme(n)
                </div>
            </div>
            
            <!-- Action Button -->
            <?php if (!empty($poll['microsoft_forms_url'])): ?>
            <!-- Microsoft Forms Link - Show both buttons -->
            <div class="flex gap-3">
                <a 
                    href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-block px-6 py-2 bg-blue-500 text-white rounded-lg font-semibold hover:bg-blue-600 transition-colors no-underline"
                >
                    <i class="fas fa-external-link-alt mr-2"></i>Zur Umfrage
                </a>
                <button 
                    onclick="hidePoll(<?php echo $poll['id']; ?>)"
                    class="inline-block px-6 py-2 bg-gray-500 text-white rounded-lg font-semibold hover:bg-gray-600 transition-colors"
                >
                    <i class="fas fa-eye-slash mr-2"></i>Erledigt / Ausblenden
                </button>
            </div>
            <?php else: ?>
            <!-- Internal Poll - Regular behavior -->
            <a 
                href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                class="inline-block px-6 py-2 bg-blue-500 text-white rounded-lg font-semibold hover:bg-blue-600 transition-colors no-underline"
            >
                <?php if ($poll['user_has_voted'] > 0): ?>
                    <i class="fas fa-chart-bar mr-2"></i>Ergebnisse ansehen
                <?php else: ?>
                    <i class="fas fa-vote-yea mr-2"></i>Jetzt abstimmen
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function hidePoll(pollId) {
    if (!confirm('Möchten Sie diese Umfrage wirklich ausblenden?')) {
        return;
    }
    
    fetch('<?php echo asset('api/hide_poll.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ poll_id: pollId, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to update the list
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
    });
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
