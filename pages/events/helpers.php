<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'member';

// Initialize empty arrays for alumni
$events = [];
$mySlotIds = [];

// Only fetch events if user is not alumni (they can't access helper system)
if ($userRole !== 'alumni') {
    // Get all events that need helpers
    $filters = [
        'needs_helpers' => true,
        'include_helpers' => true
    ];
    
    // Get events where helpers are needed
    $events = Event::getEvents($filters, $userRole);
    
    // Filter to only show events that have not yet ended (not past)
    $events = array_filter($events, function($event) {
        return in_array($event['status'], ['open', 'planned', 'running', 'closed']);
    });
    
    // Get user's signups to show which slots they're already signed up for
    $userSignups = Event::getUserSignups($user['id']);
    $mySlotIds = array_column($userSignups, 'slot_id');
}

$title = 'Helfersystem - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <i class="fas fa-hands-helping mr-3 text-ibc-green"></i>
            Helfersystem
        </h1>
        <p class="text-gray-600 dark:text-gray-300">Wir suchen Helfer für folgende Events - Unterstütze uns!</p>
    </div>

    <?php if ($userRole === 'alumni'): ?>
        <!-- Alumni message -->
        <div class="card p-8 text-center">
            <i class="fas fa-info-circle text-6xl text-ibc-blue mb-4"></i>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">Information für Alumni</h2>
            <p class="text-xl text-gray-600 dark:text-gray-300">Als Alumni-Mitglied hast Du keinen Zugriff auf das Helfersystem.</p>
        </div>
    <?php elseif (empty($events)): ?>
        <!-- No events message -->
        <div class="card p-8 text-center">
            <i class="fas fa-check-circle text-6xl text-ibc-green mb-4"></i>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">Keine Helfer benötigt</h2>
            <p class="text-xl text-gray-600 dark:text-gray-300">Aktuell werden für keine Events Helfer gesucht.</p>
        </div>
    <?php else: ?>
        <!-- Wir suchen Helfer Section -->
        <div class="bg-gradient-to-r from-ibc-green to-ibc-blue rounded-lg p-8 mb-8 text-white shadow-xl">
            <h2 class="text-3xl font-bold mb-4">
                <i class="fas fa-bullhorn mr-3"></i>
                Wir suchen Helfer!
            </h2>
            <p class="text-lg mb-4">
                Deine Mithilfe ist gefragt! Für die folgenden Events suchen wir noch Unterstützung. 
                Melde Dich für einen Slot an und hilf uns dabei, großartige Events zu organisieren.
            </p>
            <div class="flex items-center gap-4">
                <div class="bg-white/20 px-4 py-2 rounded-lg">
                    <i class="fas fa-calendar-check mr-2"></i>
                    <strong><?php echo count($events); ?></strong> Events
                </div>
                <div class="bg-white/20 px-4 py-2 rounded-lg">
                    <i class="fas fa-users mr-2"></i>
                    Verschiedene Rollen verfügbar
                </div>
            </div>
        </div>

        <!-- Events List -->
        <div class="space-y-8">
            <?php foreach ($events as $event): ?>
                <?php 
                // Get helper types for this event
                $helperTypes = $event['helper_types'] ?? [];
                
                // Calculate total slots needed and filled
                $totalSlotsNeeded = 0;
                $totalSlotsFilled = 0;
                
                foreach ($helperTypes as $helperType) {
                    foreach ($helperType['slots'] as $slot) {
                        $totalSlotsNeeded += $slot['quantity_needed'];
                        $totalSlotsFilled += $slot['signups_count'];
                    }
                }
                
                $slotsAvailable = $totalSlotsNeeded - $totalSlotsFilled;
                
                // Parse event date
                $startDate = new DateTime($event['start_time']);
                $endDate   = new DateTime($event['end_time']);
                $formattedDate = $startDate->format('d.m.Y');
                $formattedTime = $startDate->format('H:i');
                ?>
                
                <div class="card p-6">
                    <!-- Event Header -->
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-6">
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                                <?php echo htmlspecialchars($event['title']); ?>
                            </h2>
                            <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar mr-2 text-ibc-blue"></i>
                                    <?php echo $formattedDate; ?> um <?php echo $formattedTime; ?> Uhr
                                </div>
                                <?php if (!empty($event['location'])): ?>
                                    <div class="flex items-center">
                                        <i class="fas fa-map-marker-alt mr-2 text-ibc-green"></i>
                                        <?php echo htmlspecialchars($event['location']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Slots Summary -->
                        <div class="bg-ibc-green/5 dark:bg-ibc-green/10 px-6 py-4 rounded-lg text-center border border-ibc-green/20 dark:border-ibc-green/30">
                            <div class="text-3xl font-bold text-ibc-green">
                                <?php echo $slotsAvailable; ?>
                            </div>
                            <div class="text-sm text-gray-800 dark:text-gray-300">
                                <?php echo $slotsAvailable === 1 ? 'Platz frei' : 'Plätze frei'; ?>
                            </div>
                            <div class="text-xs text-gray-700 dark:text-gray-400 mt-1">
                                von <?php echo $totalSlotsNeeded; ?> gesamt
                            </div>
                        </div>
                    </div>
                    
                    <!-- Event Description -->
                    <?php if (!empty($event['description'])): ?>
                        <div class="mb-6 text-gray-800 dark:text-gray-300">
                            <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Helper Types & Slots -->
                    <?php if (!empty($helperTypes)): ?>
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                                <i class="fas fa-clipboard-list mr-2 text-ibc-blue"></i>
                                Verfügbare Helfer-Rollen:
                            </h3>
                            
                            <?php foreach ($helperTypes as $helperType): ?>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                    <h4 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">
                                        <?php echo htmlspecialchars($helperType['title']); ?>
                                    </h4>
                                    <?php if (!empty($helperType['description'])): ?>
                                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                                            <?php echo htmlspecialchars($helperType['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <!-- Slots Table -->
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm card-table">
                                            <thead class="bg-gray-200 dark:bg-gray-700">
                                                <tr>
                                                    <th class="px-4 py-2 text-left text-gray-800 dark:text-gray-100">Zeitslot</th>
                                                    <th class="px-4 py-2 text-center text-gray-800 dark:text-gray-100">Benötigt</th>
                                                    <th class="px-4 py-2 text-center text-gray-800 dark:text-gray-100">Besetzt</th>
                                                    <th class="px-4 py-2 text-center text-gray-800 dark:text-gray-100">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-900">
                                                <?php foreach ($helperType['slots'] as $slot): ?>
                                                    <?php
                                                    $slotStart = new DateTime($slot['start_time']);
                                                    $slotEnd = new DateTime($slot['end_time']);
                                                    $eventDate    = $startDate->format('Y-m-d');
                                                    $eventEndDate = $endDate->format('Y-m-d');
                                                    
                                                    // Determine Aufbau/Abbau and whether to show the date
                                                    $isAufbau = $slotStart->format('Y-m-d') < $eventDate;
                                                    $isAbbau  = $slotEnd->format('Y-m-d')   > $eventEndDate;
                                                    $showSlotDate = $slotStart->format('Y-m-d') !== $eventDate || $slotEnd->format('Y-m-d') !== $eventDate;
                                                    
                                                    if ($showSlotDate) {
                                                        $slotTimeRange = $slotStart->format('d.m. H:i') . ' – ' . $slotEnd->format('d.m. H:i');
                                                    } else {
                                                        $slotTimeRange = $slotStart->format('H:i') . ' – ' . $slotEnd->format('H:i');
                                                    }
                                                    
                                                    $isFull = $slot['signups_count'] >= $slot['quantity_needed'];
                                                    $isUserSignedUp = in_array($slot['id'], $mySlotIds);
                                                    ?>
                                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                                        <td class="px-4 py-3 text-gray-800 dark:text-gray-100" data-label="Zeitslot">
                                                            <div class="flex items-center gap-2 flex-wrap">
                                                                <i class="fas fa-clock text-ibc-blue"></i>
                                                                <?php echo htmlspecialchars($slotTimeRange); ?>
                                                                <?php if ($isAufbau): ?>
                                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-ibc-blue/10 text-ibc-blue"><i class="fas fa-tools mr-1"></i>Aufbau</span>
                                                                <?php elseif ($isAbbau): ?>
                                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-ibc-accent/10 text-ibc-accent"><i class="fas fa-box mr-1"></i>Abbau</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-4 py-3 text-center text-gray-800 dark:text-gray-100" data-label="Benötigt">
                                                            <?php echo $slot['quantity_needed']; ?>
                                                        </td>
                                                        <td class="px-4 py-3 text-center text-gray-800 dark:text-gray-100" data-label="Besetzt">
                                                            <?php echo $slot['signups_count']; ?>
                                                        </td>
                                                        <td class="px-4 py-3 text-center" data-label="Status">
                                                            <?php if ($isUserSignedUp): ?>
                                                                <span class="px-3 py-1 bg-ibc-blue/10 text-ibc-blue border border-ibc-blue/20 rounded-full text-xs font-semibold">
                                                                    <i class="fas fa-check mr-1"></i>
                                                                    Angemeldet
                                                                </span>
                                                            <?php elseif ($isFull): ?>
                                                                <span class="px-3 py-1 bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200 rounded-full text-xs font-semibold">
                                                                    <i class="fas fa-times mr-1"></i>
                                                                    Voll
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="px-3 py-1 bg-ibc-green/10 text-ibc-green border border-ibc-green/20 rounded-full text-xs font-semibold">
                                                                    <i class="fas fa-check-circle mr-1"></i>
                                                                    Verfügbar
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Button -->
                    <div class="mt-6 flex justify-end">
                        <a href="view.php?id=<?php echo $event['id']; ?>" 
                           class="px-6 py-3 bg-ibc-green text-white rounded-lg font-semibold hover:bg-ibc-green-dark transition-all shadow-soft hover:shadow-lg">
                            <i class="fas fa-eye mr-2"></i>
                            Event ansehen & anmelden
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
