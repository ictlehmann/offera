<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';

// Update event statuses (pseudo-cron)
require_once __DIR__ . '/../../includes/pseudo_cron.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$currentUser = Auth::user();
if (!$currentUser) {
    Auth::logout();
    header('Location: ../auth/login.php');
    exit;
}

// Check if profile is complete - if not, redirect to profile edit page
// Only enforce for roles that need profiles (not for test/system accounts)
$rolesRequiringProfile = ['board_finance', 'board_internal', 'board_external', 'alumni_board', 'alumni_auditor', 'alumni', 'member', 'head', 'candidate', 'honorary_member'];
if (in_array($currentUser['role'], $rolesRequiringProfile) && isset($currentUser['profile_complete']) && $currentUser['profile_complete'] == 0) {
    $_SESSION['profile_incomplete_message'] = 'Bitte vervollständige dein Profil (Vorname und Nachname) um fortzufahren.';
    header('Location: ../alumni/edit.php');
    exit;
}

$user = $currentUser;
$userRole = $user['role'] ?? '';
$stats = Inventory::getDashboardStats();

// Get user's name for personalized greeting
$displayName = 'Benutzer'; // Default fallback
if (!empty($user['firstname']) && !empty($user['lastname'])) {
    $displayName = $user['firstname'] . ' ' . $user['lastname'];
} elseif (!empty($user['firstname'])) {
    $displayName = $user['firstname'];
} elseif (!empty($user['email']) && strpos($user['email'], '@') !== false) {
    $emailParts = explode('@', $user['email']);
    $displayName = $emailParts[0];
}
// Format name: remove dots and capitalize first letters
if ($displayName !== 'Benutzer') {
    $displayName = ucwords(str_replace('.', ' ', $displayName));
}

// Determine greeting based on time of day (German time)
$timezone = new DateTimeZone('Europe/Berlin');
$now = new DateTime('now', $timezone);
$hour = (int)$now->format('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Guten Morgen';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Guten Tag';
} else {
    $greeting = 'Guten Abend';
}

// Get user's upcoming events
$userUpcomingEvents = Event::getUserSignups($user['id']);
$nextEvent = null;
if (!empty($userUpcomingEvents)) {
    // Filter for upcoming events only and get the next one
    $upcomingEvents = array_filter($userUpcomingEvents, function($signup) {
        return !empty($signup['start_time']) && strtotime($signup['start_time']) > time();
    });
    if (!empty($upcomingEvents)) {
        // Sort by start_time
        usort($upcomingEvents, function($a, $b) {
            return strtotime($a['start_time']) - strtotime($b['start_time']);
        });
        $nextEvent = $upcomingEvents[0];
    }
}

// Get user's open tasks from inventory rentals (inventory_requests table)
$openTasksCount = 0;
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT COUNT(*) FROM inventory_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')"
    );
    $stmt->execute([$user['id']]);
    $openTasksCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('dashboard: inventory_requests count failed: ' . $e->getMessage());
}

// Get events that need helpers (for all users)
$contentDb = Database::getContentDB();
$helperEvents = [];
try {
    $stmt = $contentDb->query("
        SELECT e.id, e.title, e.description, e.start_time, e.end_time, e.location
        FROM events e
        WHERE e.needs_helpers = 1
        AND e.status IN ('open', 'planned')
        AND e.end_time >= NOW()
        ORDER BY e.start_time ASC
        LIMIT 5
    ");
    $helperEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    // If needs_helpers column doesn't exist yet, gracefully skip this section
    // This can happen if update_database_schema.php hasn't been run yet
    $errorMessage = $e->getMessage();
    
    // Check for column-not-found error using SQLSTATE code (42S22) for reliability
    // Also check error message as fallback for different database systems
    $isColumnError = (isset($e->errorInfo[0]) && $e->errorInfo[0] === '42S22') ||
                     stripos($errorMessage, 'Unknown column') !== false ||
                     stripos($errorMessage, 'Column not found') !== false;
    
    if (!$isColumnError) {
        // For non-column errors, log and re-throw for proper error handling
        error_log("Dashboard: Unexpected database error when fetching helper events: " . $errorMessage);
        throw $e;
    }
    
    // Column not found - continue with empty $helperEvents array
    error_log("Dashboard: needs_helpers column not found in events table. Run update_database_schema.php to add it.");
}

// Security Audit - nur für Board/Head
$securityWarning = '';
if (Auth::isBoard() || Auth::hasRole('head')) {
    require_once __DIR__ . '/../../security_audit.php';
    $securityWarning = SecurityAudit::getDashboardWarning(__DIR__ . '/../..');
}

$title = 'Dashboard - IBC Intranet';
ob_start();
?>

<?php if (!empty($user['prompt_profile_review']) && $user['prompt_profile_review'] == 1): ?>
<!-- Profile Review Prompt Modal -->
<div id="profile-review-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden transform transition-all" style="background-color: var(--bg-card)">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-purple-600 to-blue-600 px-6 py-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-user-edit text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-white">Deine Rolle wurde geändert!</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="px-6 py-6">
            <p class="text-lg mb-6" style="color: var(--text-main)">
                Bitte überprüfe deine Daten (besonders E-Mail und Job-Daten), damit wir in Kontakt bleiben können.
            </p>
            
            <div class="rounded-lg p-4 mb-6" style="background-color: var(--bg-body); border: 1px solid var(--border-color)">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-purple-600 mt-1 mr-3"></i>
                    <p class="text-sm" style="color: var(--text-main)">
                        Es ist wichtig, dass deine Kontaktdaten aktuell sind, damit du alle wichtigen Informationen erhältst.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 flex flex-col sm:flex-row gap-3" style="background-color: var(--bg-body); border-top: 1px solid var(--border-color)">
            <a href="../auth/profile.php" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg font-semibold hover:from-purple-700 hover:to-blue-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                <i class="fas fa-user-circle mr-2"></i>
                Zum Profil
            </a>
            <button onclick="dismissProfileReviewPrompt()" class="flex-1 px-6 py-3 rounded-lg font-semibold transition-all duration-300" style="background-color: var(--border-color); color: var(--text-main)">
                Später
            </button>
        </div>
    </div>
</div>

<script>
// Dismiss profile review prompt and update database
function dismissProfileReviewPrompt() {
    // Construct API path relative to web root
    const baseUrl = window.location.origin;
    const apiPath = baseUrl + '/api/dismiss_profile_review.php';
    
    // Make AJAX call to update database
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Hide modal
            document.getElementById('profile-review-modal').style.display = 'none';
        } else {
            console.error('Failed to dismiss prompt:', data.message);
            // Hide modal anyway to prevent blocking user
            document.getElementById('profile-review-modal').style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Hide modal anyway to prevent blocking user
        document.getElementById('profile-review-modal').style.display = 'none';
    });
}
</script>
<?php endif; ?>

<?php if (!empty($securityWarning)): ?>
<?php echo $securityWarning; ?>
<?php endif; ?>

<!-- Hero Section with Personalized Greeting -->
<div class="mb-10">
    <div class="max-w-4xl mx-auto">
        <div class="hero-gradient relative overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 via-blue-700 to-emerald-600 p-8 md:p-12 text-white shadow-xl">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSI+PHBhdGggZD0iTTM2IDM0djZoNnYtNmgtNnptMCAwdi02aC02djZoNnoiLz48L2c+PC9nPjwvc3ZnPg==')] opacity-50"></div>
            <div class="relative z-10">
                <p class="text-blue-100 text-sm font-medium uppercase tracking-wider mb-2 hero-date">
                    <i class="fas fa-sun mr-1"></i> <?php
                        $germanMonths = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
                        $monthNum = (int)date('n');
                        echo date('d') . '. ' . ($germanMonths[$monthNum] ?? '') . ' ' . date('Y');
                    ?>
                </p>
                <h1 class="text-3xl md:text-5xl font-extrabold mb-3 tracking-tight hero-title">
                    <?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($displayName); ?>! 👋
                </h1>
                <p class="text-lg text-blue-100 font-medium hero-subtitle">
                    Willkommen zurück im IBC Intranet
                </p>
            </div>
            <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
            <div class="absolute -top-6 -left-6 w-24 h-24 bg-emerald-400/20 rounded-full blur-xl"></div>
        </div>
    </div>
</div>

<!-- Quick Stats Widgets -->
<div class="max-w-6xl mx-auto mb-10">
    <div class="flex items-center mb-6">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center mr-3 shadow-md">
            <i class="fas fa-tachometer-alt text-white text-sm"></i>
        </div>
        <h2 class="text-2xl font-bold" style="color: var(--text-main)">Schnellübersicht</h2>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- My Open Tasks Widget -->
        <a href="/pages/inventory/my_rentals.php" class="block group">
            <div class="card p-7 rounded-2xl hover:shadow-2xl transition-all duration-300 cursor-pointer border border-orange-100/50" style="background-color: var(--bg-card)">
                <div class="mb-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-orange-500 mb-3">Ausleihen</p>
                    <h3 class="text-xl font-bold mb-4" style="color: var(--text-main)">Meine offenen Ausleihen</h3>
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-20 h-20 bg-gradient-to-br from-orange-400 to-orange-500 rounded-2xl flex items-center justify-center shadow-lg shadow-orange-200 group-hover:scale-110 transition-transform duration-300">
                            <span class="text-4xl font-bold text-white"><?php echo $openTasksCount; ?></span>
                        </div>
                    </div>
                </div>
                <?php if ($openTasksCount > 0): ?>
                <div class="text-center">
                    <p class="font-medium mb-4" style="color: var(--text-main)"><?php echo $openTasksCount; ?> offene <?php echo $openTasksCount == 1 ? 'Ausleihe' : 'Ausleihen'; ?></p>
                    <span class="inline-flex items-center text-orange-600 font-semibold text-sm group-hover:translate-x-1 transition-transform">
                        Ausleihen verwalten <i class="fas fa-arrow-right ml-2"></i>
                    </span>
                </div>
                <?php else: ?>
                <div class="text-center space-y-3">
                    <p class="font-medium text-base" style="color: var(--text-main)">Keine offenen Ausleihen</p>
                    <div class="pt-3 border-t border-orange-100">
                        <p class="text-sm flex items-center justify-center" style="color: var(--text-muted)">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>
                            Alle Artikel wurden zurückgegeben
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </a>

        <!-- Next Event Widget -->
        <div class="card p-7 rounded-2xl hover:shadow-2xl transition-all duration-300 border border-blue-100/50" style="background-color: var(--bg-card)">
            <div class="mb-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-blue-500 mb-3">Events</p>
                <h3 class="text-xl font-bold mb-4" style="color: var(--text-main)">Nächstes Event</h3>
            </div>
            <?php if ($nextEvent): ?>
            <div class="space-y-3">
                <h4 class="font-semibold text-lg" style="color: var(--text-main)"><?php echo htmlspecialchars($nextEvent['title']); ?></h4>
                <p style="color: var(--text-muted)">
                    <i class="fas fa-clock mr-2 text-blue-400"></i>
                    <?php echo date('d.m.Y H:i', strtotime($nextEvent['start_time'])); ?> Uhr
                </p>
                <div class="pt-3">
                    <a href="../events/view.php?id=<?php echo $nextEvent['event_id']; ?>" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm hover:translate-x-1 transition-transform">
                        Details ansehen <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="space-y-4">
                <p class="font-medium text-base" style="color: var(--text-main)">Keine anstehenden Events</p>
                <div class="pt-3">
                    <a href="../events/index.php" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm hover:translate-x-1 transition-transform">
                        Events durchsuchen <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Dashboard Section - Wir suchen Helfer -->
<div class="max-w-6xl mx-auto mb-12">
    <div class="flex items-center mb-6">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center mr-3 shadow-md">
            <i class="fas fa-hands-helping text-white text-sm"></i>
        </div>
        <h2 class="text-2xl font-bold" style="color: var(--text-main)">Wir suchen Helfer</h2>
    </div>
    
    <?php if (!empty($helperEvents)): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($helperEvents as $event): ?>
        <div class="card p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 border-l-4 border-green-500" style="background-color: var(--bg-card)">
            <div class="mb-4">
                <h3 class="text-lg font-bold mb-2" style="color: var(--text-main)">
                    <i class="fas fa-calendar-alt text-green-600 mr-2"></i>
                    <?php echo htmlspecialchars($event['title']); ?>
                </h3>
                <?php if (!empty($event['description'])): ?>
                <p class="text-sm mb-2" style="color: var(--text-muted)">
                    <?php echo htmlspecialchars(substr($event['description'], 0, 100)) . (strlen($event['description']) > 100 ? '...' : ''); ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="text-sm mb-3" style="color: var(--text-muted)">
                <div class="flex items-center mb-1">
                    <i class="fas fa-clock mr-2 text-green-600"></i>
                    <?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?> Uhr
                </div>
                <?php if (!empty($event['location'])): ?>
                <div class="flex items-center">
                    <i class="fas fa-map-marker-alt mr-2 text-green-600"></i>
                    <?php echo htmlspecialchars($event['location']); ?>
                </div>
                <?php endif; ?>
            </div>
            <a href="../events/view.php?id=<?php echo $event['id']; ?>" 
               class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 transition-all font-semibold">
                Mehr erfahren <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card p-8 rounded-xl shadow-lg text-center" style="background-color: var(--bg-card)">
        <i class="fas fa-hands-helping text-4xl mb-3 text-gray-400"></i>
        <p class="text-lg" style="color: var(--text-muted)">Aktuell werden keine Helfer gesucht</p>
        <a href="../events/index.php" class="inline-flex items-center mt-4 text-green-600 hover:text-green-700 font-semibold">
            Alle Events ansehen <i class="fas fa-arrow-right ml-2"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Polls Widget Section -->
<div class="max-w-6xl mx-auto mb-12">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center mr-3 shadow-md shrink-0">
                <i class="fas fa-poll text-white text-sm"></i>
            </div>
            <h2 class="text-2xl font-bold" style="color: var(--text-main)">Aktuelle Umfragen</h2>
        </div>
        <a href="../polls/index.php" class="text-orange-600 hover:text-orange-700 font-semibold text-sm shrink-0">
            Alle Umfragen <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    
    <?php
    // Fetch active polls for the user
    $userAzureRoles = isset($user['azure_roles']) ? json_decode($user['azure_roles'], true) : [];
    
    $pollStmt = $contentDb->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
               (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
        FROM polls p
        WHERE p.is_active = 1 AND p.end_date > NOW()
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $pollStmt->execute([$user['id'], $user['id']]);
    $allPolls = $pollStmt->fetchAll();
    
    // Filter polls using shared helper function
    $visiblePolls = filterPollsForUser($allPolls, $userRole, $userAzureRoles);
    
    if (!empty($visiblePolls)): 
    ?>
    <div class="grid grid-cols-1 gap-4">
        <?php foreach ($visiblePolls as $poll): ?>
        <div class="card p-5 rounded-xl shadow-md hover:shadow-lg transition-all" style="background-color: var(--bg-card)">
            <div class="flex flex-col sm:flex-row items-start gap-3">
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-lg mb-2" style="color: var(--text-main)">
                        <i class="fas fa-poll-h text-orange-500 mr-2"></i>
                        <?php echo htmlspecialchars($poll['title']); ?>
                    </h3>
                    <?php if (!empty($poll['description'])): ?>
                    <p class="text-sm mb-3" style="color: var(--text-muted)">
                        <?php echo htmlspecialchars(substr($poll['description'], 0, 150)) . (strlen($poll['description']) > 150 ? '...' : ''); ?>
                    </p>
                    <?php endif; ?>
                    <p class="text-xs" style="color: var(--text-muted)">
                        <i class="fas fa-clock mr-1"></i>
                        Endet am <?php echo date('d.m.Y', strtotime($poll['end_date'])); ?>
                    </p>
                </div>
                <div class="flex sm:flex-col gap-2 shrink-0">
                    <?php if (!empty($poll['microsoft_forms_url'])): ?>
                    <!-- Microsoft Forms Link -->
                    <a 
                        href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-all text-sm font-semibold"
                    >
                        <i class="fas fa-external-link-alt mr-1"></i>Zur Umfrage
                    </a>
                    <button 
                        onclick="hidePollFromDashboard(<?php echo $poll['id']; ?>)"
                        class="inline-flex items-center px-4 py-2 bg-gray-400 text-white rounded-lg hover:bg-gray-500 transition-all text-xs font-semibold"
                    >
                        <i class="fas fa-eye-slash mr-1"></i>Ausblenden
                    </button>
                    <?php else: ?>
                    <!-- Internal Poll -->
                    <a 
                        href="../polls/view.php?id=<?php echo $poll['id']; ?>"
                        class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-all text-sm font-semibold"
                    >
                        <?php if ($poll['user_has_voted'] > 0): ?>
                            <i class="fas fa-chart-bar mr-1"></i>Ergebnisse
                        <?php else: ?>
                            <i class="fas fa-vote-yea mr-1"></i>Abstimmen
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card p-6 rounded-xl shadow-md text-center" style="background-color: var(--bg-card)">
        <i class="fas fa-poll text-3xl mb-2 text-gray-400"></i>
        <p style="color: var(--text-muted)">Keine aktiven Umfragen für Sie verfügbar</p>
    </div>
    <?php endif; ?>
</div>

<script>
function hidePollFromDashboard(pollId) {
    if (!confirm('Möchten Sie diese Umfrage wirklich ausblenden?')) {
        return;
    }
    
    fetch('<?php echo asset('api/hide_poll.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ poll_id: pollId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to update the dashboard
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

<!-- Upcoming Events Section - Visible to All Users -->
<div class="max-w-6xl mx-auto mb-12">
    <div class="flex items-center justify-center mb-6">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center mr-3 shadow-md">
            <i class="fas fa-calendar-alt text-white text-sm"></i>
        </div>
        <h2 class="text-2xl font-bold" style="color: var(--text-main)">Anstehende Events</h2>
    </div>
    
    <div class="grid grid-cols-1 gap-6">
        <?php 
        // Get upcoming events (upcoming status, ordered by start time)
        $upcomingEventsForAllUsers = Event::getEvents([
            'status' => ['upcoming', 'registration_open'],
            'start_date' => date('Y-m-d H:i:s')
        ], $user['role']);
        
        // Limit to 5 events
        $upcomingEventsForAllUsers = array_slice($upcomingEventsForAllUsers, 0, 5);
        
        if (!empty($upcomingEventsForAllUsers)): 
        ?>
        <div class="card p-6 rounded-xl shadow-lg" style="background-color: var(--bg-card)">
            <div class="space-y-4">
                <?php foreach ($upcomingEventsForAllUsers as $event): ?>
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 rounded-lg shadow-sm hover:shadow-md transition-all" style="background-color: var(--bg-card); border: 1px solid var(--border-color)">
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold mb-1 truncate" style="color: var(--text-main)"><?php echo htmlspecialchars($event['title']); ?></h3>
                        <p class="text-sm" style="color: var(--text-muted)">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?> Uhr
                        </p>
                        <?php if (!empty($event['location'])): ?>
                        <p class="text-sm mt-1" style="color: var(--text-muted)">
                            <i class="fas fa-map-marker-alt mr-1"></i>
                            <?php echo htmlspecialchars($event['location']); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <a href="../events/view.php?id=<?php echo $event['id']; ?>" class="shrink-0 inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all">
                        Details
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card p-8 rounded-xl shadow-lg text-center" style="background-color: var(--bg-card)">
            <i class="fas fa-calendar-times text-4xl mb-3 text-gray-400"></i>
            <p class="text-lg" style="color: var(--text-muted)">Keine anstehenden Events</p>
            <a href="../events/index.php" class="inline-flex items-center mt-4 text-blue-600 hover:text-blue-700 font-semibold">
                Alle Events ansehen <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::isBoard()): ?>
<?php
require_once __DIR__ . '/../../includes/models/EventDocumentation.php';
$financialSummary = [];
try {
    $financialSummary = EventDocumentation::getFinancialSummary(10);
} catch (PDOException $e) {
    $isColumnError = (isset($e->errorInfo[0]) && $e->errorInfo[0] === '42S22') ||
                     stripos($e->getMessage(), 'Unknown column') !== false ||
                     stripos($e->getMessage(), 'Column not found') !== false;
    if (!$isColumnError) {
        error_log("Dashboard: Unexpected database error in getFinancialSummary: " . $e->getMessage());
        throw $e;
    }
    error_log("Dashboard: total_costs column not found in event_documentation. Run update_database_schema.php to add it.");
}
?>
<!-- Kosten & Verkäufe Section - Board Only -->
<div class="max-w-6xl mx-auto mb-12">
    <div class="flex items-center mb-6">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center mr-3 shadow-md">
            <i class="fas fa-balance-scale text-white text-sm"></i>
        </div>
        <h2 class="text-2xl font-bold" style="color: var(--text-main)">Kosten &amp; Verkäufe</h2>
        <span class="ml-3 text-sm font-normal" style="color: var(--text-muted)">(Nur für Vorstand sichtbar)</span>
    </div>

    <div class="card rounded-2xl shadow-lg overflow-hidden" style="background-color: var(--bg-card)">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background-color: var(--bg-body); border-bottom: 2px solid var(--border-color)">
                        <th class="px-5 py-3 text-left font-semibold" style="color: var(--text-muted)">Event</th>
                        <th class="px-5 py-3 text-left font-semibold" style="color: var(--text-muted)">Datum</th>
                        <th class="px-5 py-3 text-right font-semibold" style="color: var(--text-muted)">Kosten (€)</th>
                        <th class="px-5 py-3 text-right font-semibold" style="color: var(--text-muted)">Verkäufe (€)</th>
                        <th class="px-5 py-3 text-right font-semibold" style="color: var(--text-muted)">Differenz (€)</th>
                        <th class="px-5 py-3 text-center font-semibold" style="color: var(--text-muted)">Kalkulation</th>
                        <th class="px-5 py-3 text-center font-semibold" style="color: var(--text-muted)">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($financialSummary as $row): ?>
                    <?php
                        $costs = $row['total_costs'] !== null ? floatval($row['total_costs']) : null;
                        $sales = floatval($row['sales_total']);
                        $diff = ($costs !== null) ? ($sales - $costs) : null;
                    ?>
                    <tr class="border-b" style="border-color: var(--border-color)" data-event-id="<?php echo $row['id']; ?>">
                        <td class="px-5 py-3 font-medium" style="color: var(--text-main)">
                            <a href="../events/view.php?id=<?php echo $row['id']; ?>" class="hover:text-teal-600 transition-colors">
                                <?php echo htmlspecialchars($row['title']); ?>
                            </a>
                        </td>
                        <td class="px-5 py-3" style="color: var(--text-muted)">
                            <?php echo date('d.m.Y', strtotime($row['start_time'])); ?>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value="<?php echo $costs !== null ? number_format($costs, 2, '.', '') : ''; ?>"
                                placeholder="0.00"
                                class="w-28 px-2 py-1 text-right border rounded text-sm costs-input"
                                style="border-color: var(--border-color); background-color: var(--bg-body); color: var(--text-main)"
                                data-event-id="<?php echo $row['id']; ?>"
                            >
                        </td>
                        <td class="px-5 py-3 text-right font-medium" style="color: var(--text-main)">
                            <?php echo number_format($sales, 2, ',', '.'); ?> €
                        </td>
                        <td class="px-5 py-3 text-right font-semibold">
                            <?php if ($diff !== null): ?>
                                <span class="<?php echo $diff >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo ($diff >= 0 ? '+' : '') . number_format($diff, 2, ',', '.'); ?> €
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-muted)">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <?php if (!empty($row['calculation_link'])): ?>
                            <a href="<?php echo htmlspecialchars($row['calculation_link']); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                <i class="fas fa-external-link-alt mr-1"></i>Link
                            </a>
                            <?php else: ?>
                            <span class="text-sm" style="color: var(--text-muted)">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <button
                                class="save-costs-btn px-3 py-1 bg-teal-600 text-white rounded text-xs font-semibold hover:bg-teal-700 transition-all"
                                data-event-id="<?php echo $row['id']; ?>"
                            >
                                <i class="fas fa-save mr-1"></i>Speichern
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($financialSummary)): ?>
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center" style="color: var(--text-muted)">
                            Noch keine Events vorhanden.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Success/error message for costs saving -->
    <div id="costs-message" class="hidden mt-3 px-4 py-2 rounded-lg text-sm font-medium"></div>
</div>

<script>
document.querySelectorAll('.save-costs-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const eventId = this.dataset.eventId;
        const input = document.querySelector('.costs-input[data-event-id="' + eventId + '"]');
        const val = input ? input.value.trim() : '';
        const amount = val === '' ? null : parseFloat(val);

        if (amount !== null && (isNaN(amount) || amount < 0)) {
            showCostsMessage('Ungültiger Betrag', 'error');
            return;
        }

        this.disabled = true;
        const origText = this.innerHTML;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>';

        const baseUrl = window.location.origin;
        fetch(baseUrl + '/api/save_event_documentation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: parseInt(eventId), total_costs: amount })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showCostsMessage('Kosten gespeichert', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showCostsMessage(data.message || 'Fehler beim Speichern', 'error');
            }
        })
        .catch(() => showCostsMessage('Netzwerkfehler', 'error'))
        .finally(() => {
            this.disabled = false;
            this.innerHTML = origText;
        });
    });
});

function showCostsMessage(msg, type) {
    const el = document.getElementById('costs-message');
    el.textContent = msg;
    el.className = 'mt-3 px-4 py-2 rounded-lg text-sm font-medium ' + (type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
    setTimeout(() => { el.className = 'hidden'; }, 4000);
}
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
