<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

// Update event statuses (pseudo-cron)
require_once __DIR__ . '/../../includes/pseudo_cron.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'member';

// Get filter from query parameters
$filter = $_GET['filter'] ?? 'current';

$filters = [];
$now = date('Y-m-d H:i:s');

// Filter logic
if ($filter === 'current') {
    // Show only future and current events
    $filters['start_date'] = $now;
} elseif ($filter === 'my_registrations') {
    // We'll filter this separately after getting events
}

// Get all events visible to user
$events = Event::getEvents($filters, $userRole, $user['id']);

// Get user's registrations if needed
if ($filter === 'my_registrations') {
    $userSignups = Event::getUserSignups($user['id']);
    $myEventIds = array_column($userSignups, 'event_id');
    $events = array_filter($events, function($event) use ($myEventIds) {
        return in_array($event['id'], $myEventIds);
    });
} else {
    // Hide past events for normal users (non-board, non-manager)
    // Board members, alumni_board, alumni_auditor, and managers can see past events
    $canViewPastEvents = Auth::isBoard() || Auth::hasRole(['alumni_board', 'alumni_auditor', 'manager']);
    if (!$canViewPastEvents) {
        $events = array_filter($events, function($event) use ($now) {
            return $event['end_time'] >= $now;
        });
    }
}

// Get user's signups for display
$userSignups = Event::getUserSignups($user['id']);
$myEventIds = array_column($userSignups, 'event_id');

$title = 'Events - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-calendar-alt mr-3 text-purple-600"></i>
                Events
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Entdecke kommende Events und melde Dich an</p>
        </div>
        
        <div class="flex gap-3">
            <!-- Statistiken Button - Board/Alumni Board only -->
            <?php if (Auth::isBoard() || Auth::hasRole(['alumni_board'])): ?>
            <a href="statistics.php" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
                <i class="fas fa-chart-bar mr-2"></i>
                Statistiken
            </a>
            <?php endif; ?>
            
            <!-- Neues Event Button - Board/Head/Manager only -->
            <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['head', 'alumni_board'])): ?>
            <a href="edit.php?new=1" class="btn-primary">
                <i class="fas fa-plus mr-2"></i>Neues Event
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="mb-6 flex gap-2 flex-wrap">
        <a href="?filter=current" 
           class="px-6 py-3 rounded-lg font-semibold transition-all <?php echo $filter === 'current' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white shadow-lg' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'; ?>">
            <i class="fas fa-calendar-day mr-2"></i>
            Aktuell
        </a>
        <a href="?filter=my_registrations" 
           class="px-6 py-3 rounded-lg font-semibold transition-all <?php echo $filter === 'my_registrations' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white shadow-lg' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'; ?>">
            <i class="fas fa-user-check mr-2"></i>
            Meine Anmeldungen
        </a>
    </div>

    <!-- Events Grid -->
    <?php if (empty($events)): ?>
        <div class="card p-8 text-center">
            <i class="fas fa-calendar-times text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
            <p class="text-xl text-gray-600 dark:text-gray-300">Keine Events gefunden</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($events as $event): ?>
                <?php
                    // Calculate countdown for upcoming events
                    $startTimestamp = strtotime($event['start_time']);
                    $nowTimestamp = time();
                    $isUpcoming = $startTimestamp > $nowTimestamp;
                    $isPast = strtotime($event['end_time']) < $nowTimestamp;
                    $isRegistered = in_array($event['id'], $myEventIds);

                    // Validate image path
                    $hasImage = false;
                    if (!empty($event['image_path'])) {
                        $fullImagePath = __DIR__ . '/../../' . $event['image_path'];
                        $realPath = realpath($fullImagePath);
                        $baseDir = realpath(__DIR__ . '/../../');
                        $hasImage = $realPath && $baseDir && strpos($realPath, $baseDir) === 0 && file_exists($realPath);
                    }
                    
                    $countdown = '';
                    if ($isUpcoming) {
                        $diff = $startTimestamp - $nowTimestamp;
                        $days = floor($diff / 86400);
                        $hours = floor(($diff % 86400) / 3600);
                        
                        if ($days > 0) {
                            $countdown = "Noch {$days} Tag" . ($days != 1 ? 'e' : '') . ", {$hours} Std";
                        } else {
                            $countdown = "Noch {$hours} Std";
                        }
                    }
                ?>
                
                <a href="view.php?id=<?php echo $event['id']; ?>" class="event-card card flex flex-col overflow-hidden group no-underline" style="text-decoration:none;">
                    <!-- Event Image -->
                    <div class="event-card-image relative overflow-hidden">
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $event['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($event['title']); ?>"
                                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-br from-ibc-blue to-ibc-blue-dark">
                                <i class="fas fa-calendar-alt text-white/40 text-5xl"></i>
                            </div>
                        <?php endif; ?>

                        <!-- Overlay badges -->
                        <div class="absolute top-3 left-3 flex flex-col gap-1">
                            <?php if ($event['status'] === 'draft'): ?>
                                <span class="px-2.5 py-1 bg-gray-800/80 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-pencil-alt mr-1"></i>Entwurf
                                </span>
                            <?php elseif ($event['status'] === 'open'): ?>
                                <span class="px-2.5 py-1 bg-ibc-green/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-door-open mr-1"></i>Anmeldung offen
                                </span>
                            <?php elseif ($event['status'] === 'running'): ?>
                                <span class="px-2.5 py-1 bg-ibc-blue/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-play mr-1"></i>Läuft gerade
                                </span>
                            <?php elseif ($event['status'] === 'past'): ?>
                                <span class="px-2.5 py-1 bg-gray-600/80 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-flag-checkered mr-1"></i>Beendet
                                </span>
                            <?php endif; ?>

                            <?php if ($event['is_external']): ?>
                                <span class="px-2.5 py-1 bg-ibc-accent/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-external-link-alt mr-1"></i>Extern
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isRegistered): ?>
                            <div class="absolute top-3 right-3">
                                <span class="px-2.5 py-1 bg-ibc-green/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-check mr-1"></i>Angemeldet
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($countdown): ?>
                            <div class="absolute bottom-3 left-3">
                                <span class="inline-flex items-center px-3 py-1 bg-black/60 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-hourglass-half mr-1.5"></i>
                                    <?php echo $countdown; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Body -->
                    <div class="flex flex-col flex-1 p-5">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 leading-snug line-clamp-2">
                            <?php echo htmlspecialchars($event['title']); ?>
                        </h3>

                        <!-- Meta Info -->
                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400 mb-4">
                            <div class="flex items-center gap-2">
                                <span class="event-meta-icon"><i class="fas fa-calendar text-ibc-blue"></i></span>
                                <span>
                                    <?php
                                        $startDate = new DateTime($event['start_time']);
                                        $endDate   = new DateTime($event['end_time']);
                                        if ($startDate->format('d.m.Y') === $endDate->format('d.m.Y')) {
                                            echo $startDate->format('d.m.Y, H:i') . ' – ' . $endDate->format('H:i') . ' Uhr';
                                        } else {
                                            echo $startDate->format('d.m.Y, H:i') . ' – ' . $endDate->format('d.m.Y, H:i') . ' Uhr';
                                        }
                                    ?>
                                </span>
                            </div>
                            <?php if (!empty($event['location'])): ?>
                                <div class="flex items-center gap-2">
                                    <span class="event-meta-icon"><i class="fas fa-map-marker-alt text-ibc-blue"></i></span>
                                    <span class="truncate"><?php echo htmlspecialchars($event['location']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($event['needs_helpers'] && $userRole !== 'alumni'): ?>
                                <div class="flex items-center gap-2">
                                    <span class="event-meta-icon"><i class="fas fa-hands-helping text-ibc-accent"></i></span>
                                    <span class="text-ibc-accent font-medium">Helfer benötigt</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Description Preview -->
                        <?php if (!empty($event['description'])): ?>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 flex-1 mb-4">
                                <?php echo htmlspecialchars(substr($event['description'], 0, 120)); ?><?php echo strlen($event['description']) > 120 ? '…' : ''; ?>
                            </p>
                        <?php else: ?>
                            <div class="flex-1"></div>
                        <?php endif; ?>

                        <!-- CTA -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700">
                            <span class="text-sm font-semibold text-ibc-blue group-hover:text-ibc-blue-dark transition-colors">
                                Details ansehen
                            </span>
                            <span class="w-8 h-8 rounded-full bg-ibc-blue/10 flex items-center justify-center group-hover:bg-ibc-blue group-hover:text-white transition-all">
                                <i class="fas fa-arrow-right text-xs"></i>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .event-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        color: inherit;
    }
    .event-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-card-hover);
    }
    .event-card-image {
        height: 200px;
        background: #e5e7eb;
        flex-shrink: 0;
    }
    .event-meta-icon {
        width: 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
