<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../src/CalendarService.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'member';

// Get event ID
$eventId = $_GET['id'] ?? null;
if (!$eventId) {
    header('Location: index.php');
    exit;
}

// Get event details
$event = Event::getById($eventId, true);
if (!$event) {
    header('Location: index.php');
    exit;
}

// Check if user has permission to view this event
$allowedRoles = $event['allowed_roles'] ?? [];
if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
    header('Location: index.php');
    exit;
}

// Get user's signups
$userSignups = Event::getUserSignups($user['id']);
$isRegistered = false;
$userSignupId = null;
$userSlotId = null;
foreach ($userSignups as $signup) {
    if ($signup['event_id'] == $eventId) {
        $isRegistered = true;
        $userSignupId = $signup['id'];
        $userSlotId = $signup['slot_id'];
        break;
    }
}

// Get registration count
$registrationCount = Event::getRegistrationCount($eventId);

// Get participants list (visible to all logged-in users)
$participants = Event::getEventAttendees($eventId);

// Get helper types and slots if needed
$helperTypes = [];
if ($event['needs_helpers'] && $userRole !== 'alumni') {
    $helperTypes = Event::getHelperTypes($eventId);
    
    // For each helper type, get slots with signup counts
    foreach ($helperTypes as &$helperType) {
        $slots = Event::getSlots($helperType['id']);
        
        // Add signup counts to each slot
        foreach ($slots as &$slot) {
            $signups = Event::getSignups($eventId);
            $confirmedCount = 0;
            $userInSlot = false;
            
            foreach ($signups as $signup) {
                if ($signup['slot_id'] == $slot['id'] && $signup['status'] == 'confirmed') {
                    $confirmedCount++;
                    if ($signup['user_id'] == $user['id']) {
                        $userInSlot = true;
                    }
                }
            }
            
            $slot['signups_count'] = $confirmedCount;
            $slot['user_in_slot'] = $userInSlot;
            $slot['is_full'] = $confirmedCount >= $slot['quantity_needed'];
        }
        
        $helperType['slots'] = $slots;
    }
}

// Check if event signup has a deadline
$signupDeadline = $event['start_time']; // Default to event start time
$canCancel = strtotime($signupDeadline) > time();

$title = htmlspecialchars($event['title']) . ' - Events';
ob_start();
?>

<?php
// Validate image existence once for reuse
$imagePath = $event['image_path'] ?? '';
$imageExists = false;
if (!empty($imagePath)) {
    $fullImagePath = __DIR__ . '/../../' . $imagePath;
    $realPath = realpath($fullImagePath);
    $baseDir = realpath(__DIR__ . '/../../');
    $imageExists = $realPath && $baseDir && strpos($realPath, $baseDir) === 0 && file_exists($realPath);
}

// Status badge config
$statusLabels = [
    'planned' => ['label' => 'Geplant',                'icon' => 'fa-clock',          'color' => 'bg-white/20 border-white/30 text-white'],
    'open'    => ['label' => 'Anmeldung offen',         'icon' => 'fa-door-open',      'color' => 'bg-ibc-green/30 border-ibc-green/50 text-white'],
    'closed'  => ['label' => 'Anmeldung geschlossen',   'icon' => 'fa-door-closed',    'color' => 'bg-yellow-500/30 border-yellow-400/50 text-white'],
    'running' => ['label' => 'Läuft gerade',            'icon' => 'fa-play-circle',    'color' => 'bg-white/30 border-white/50 text-white'],
    'past'    => ['label' => 'Beendet',                 'icon' => 'fa-flag-checkered', 'color' => 'bg-white/10 border-white/20 text-white/70'],
];
$currentStatus = $event['status'] ?? 'planned';
$statusInfo = $statusLabels[$currentStatus] ?? ['label' => $currentStatus, 'icon' => 'fa-circle', 'color' => 'bg-white/20 border-white/30 text-white'];
?>

<div class="max-w-5xl mx-auto">

    <!-- Back Button -->
    <a href="index.php" class="inline-flex items-center text-ibc-blue hover:text-ibc-blue-dark mb-6 ease-premium font-medium">
        <i class="fas fa-arrow-left mr-2"></i>
        Zurück zur Übersicht
    </a>

    <!-- ═══════════════════════════════════════════════
         HERO SECTION  (image + title overlay)
    ════════════════════════════════════════════════ -->
    <div class="event-hero rounded-2xl overflow-hidden shadow-premium mb-6">
        <!-- Image / Fallback gradient -->
        <div class="event-hero-image">
            <?php if ($imageExists): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $imagePath); ?>"
                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                     class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full bg-gradient-to-br from-ibc-blue to-ibc-blue-dark flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-white/20 text-8xl"></i>
                </div>
            <?php endif; ?>
            <!-- Dark gradient overlay for legibility -->
            <div class="event-hero-overlay"></div>
        </div>

        <!-- Title + badges on top of image -->
        <div class="event-hero-content">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border backdrop-blur-sm <?php echo $statusInfo['color']; ?>">
                    <i class="fas <?php echo $statusInfo['icon']; ?> mr-1.5 text-xs"></i>
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($event['is_external']): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border border-white/30 bg-ibc-accent/80 backdrop-blur-sm text-white">
                        <i class="fas fa-external-link-alt mr-1.5 text-xs"></i>Extern
                    </span>
                <?php endif; ?>
                <?php if ($isRegistered): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border border-ibc-green/50 bg-ibc-green/70 backdrop-blur-sm text-white">
                        <i class="fas fa-check-circle mr-1.5 text-xs"></i>Angemeldet
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="text-3xl md:text-4xl font-bold text-white drop-shadow-lg leading-tight">
                <?php echo htmlspecialchars($event['title']); ?>
            </h1>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         MAIN CONTENT  (two-column on md+)
    ════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        <!-- LEFT: Description + Participants -->
        <div class="lg:col-span-2 space-y-6">

            <?php if (!empty($event['description'])): ?>
            <!-- Description Card -->
            <div class="glass-card shadow-soft rounded-2xl p-6">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-align-left text-ibc-blue text-sm"></i>
                    </span>
                    Beschreibung
                </h2>
                <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed"><?php echo htmlspecialchars($event['description']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!$event['is_external']): ?>
            <!-- Participants Card -->
            <div class="glass-card shadow-soft rounded-2xl p-6">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-ibc-green/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-users text-ibc-green text-sm"></i>
                    </span>
                    Teilnehmer
                    <span class="ml-auto inline-flex items-center justify-center min-w-[2rem] h-8 px-2.5 rounded-full bg-ibc-blue text-white text-sm font-bold">
                        <?php echo $registrationCount; ?>
                    </span>
                </h2>
                <?php if (!empty($participants)): ?>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($participants as $participant): ?>
                            <li class="py-2.5 flex items-center gap-3 text-gray-700 dark:text-gray-300">
                                <span class="w-7 h-7 rounded-full bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-user text-ibc-blue text-xs"></i>
                                </span>
                                <?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Noch keine Anmeldungen.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Info Sidebar -->
        <div class="space-y-4">

            <!-- Date & Time Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Datum & Uhrzeit</h3>
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-calendar-day text-ibc-blue"></i>
                        </span>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Beginn</div>
                            <div class="font-semibold text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y', strtotime($event['start_time'])); ?></div>
                            <div class="text-sm text-gray-600 dark:text-gray-300"><?php echo date('H:i', strtotime($event['start_time'])); ?> Uhr</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-clock text-ibc-blue"></i>
                        </span>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Ende</div>
                            <div class="font-semibold text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y', strtotime($event['end_time'])); ?></div>
                            <div class="text-sm text-gray-600 dark:text-gray-300"><?php echo date('H:i', strtotime($event['end_time'])); ?> Uhr</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($event['location'])): ?>
            <!-- Location Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Veranstaltungsort</h3>
                <div class="flex items-start gap-3">
                    <span class="w-9 h-9 rounded-xl bg-ibc-green/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-map-marker-alt text-ibc-green"></i>
                    </span>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($event['location']); ?></div>
                        <?php if (!empty($event['maps_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['maps_link']); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center mt-2 px-3 py-1.5 bg-ibc-green text-white rounded-lg font-semibold text-xs hover:shadow-glow-green ease-premium">
                                <i class="fas fa-route mr-1.5"></i>Route planen
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($event['contact_person'])): ?>
            <!-- Contact Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Ansprechpartner</h3>
                <div class="flex items-center gap-3">
                    <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-ibc-blue"></i>
                    </span>
                    <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($event['contact_person']); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Registration / CTA Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Anmeldung</h3>
                <div class="flex flex-col gap-3">
                    <?php if (!empty($event['registration_link'])): ?>
                        <a href="<?php echo htmlspecialchars($event['registration_link']); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center justify-center px-5 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium w-full">
                            <i class="fas fa-external-link-alt mr-2"></i>
                            Jetzt anmelden
                        </a>
                    <?php elseif ($event['is_external']): ?>
                        <?php if (!empty($event['external_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['external_link']); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center justify-center px-5 py-3 bg-ibc-blue text-white rounded-xl font-semibold hover:bg-ibc-blue-dark ease-premium shadow-soft w-full">
                                <i class="fas fa-external-link-alt mr-2"></i>
                                Zur Anmeldung (extern)
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$isRegistered && !$userSlotId): ?>
                            <button onclick="signupForEvent(<?php echo $eventId; ?>)"
                                    class="inline-flex items-center justify-center px-5 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium w-full">
                                <i class="fas fa-user-plus mr-2"></i>
                                Jetzt anmelden
                            </button>
                        <?php elseif ($canCancel && $userSignupId && !$userSlotId): ?>
                            <button onclick="cancelSignup(<?php echo $userSignupId; ?>)"
                                    class="inline-flex items-center justify-center px-5 py-3 bg-red-600 text-white rounded-xl font-semibold hover:bg-red-700 ease-premium w-full">
                                <i class="fas fa-user-times mr-2"></i>
                                Abmelden
                            </button>
                        <?php elseif ($isRegistered): ?>
                            <div class="flex items-center justify-center gap-2 py-3 rounded-xl bg-ibc-green/10 text-ibc-green font-semibold border border-ibc-green/20">
                                <i class="fas fa-check-circle"></i>
                                Du bist angemeldet
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Calendar Export -->
                    <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">In Kalender eintragen</p>
                        <div class="flex gap-2">
                            <a href="<?php echo htmlspecialchars(CalendarService::getGoogleLink($event)); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-semibold hover:border-ibc-blue hover:text-ibc-blue ease-premium shadow-sm">
                                <i class="fab fa-google mr-1.5"></i>Google
                            </a>
                            <a href="../../api/download_ics.php?event_id=<?php echo htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8'); ?>"
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-semibold hover:border-ibc-blue hover:text-ibc-blue ease-premium shadow-sm">
                                <i class="fas fa-download mr-1.5"></i>iCal
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /sidebar -->
    </div><!-- /grid -->

    <!-- Helper Slots Section (Only for non-alumni and if event needs helpers) -->
    <?php if ($event['needs_helpers'] && $userRole !== 'alumni' && !empty($helperTypes)): ?>
        <div class="glass-card shadow-soft rounded-xl p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">
                <i class="fas fa-hands-helping mr-2 text-ibc-green"></i>
                Helfer-Bereich
            </h2>
            
            <p class="text-gray-600 dark:text-gray-300 mb-6">Unterstütze uns als Helfer! Wähle einen freien Slot aus.</p>
            
            <?php foreach ($helperTypes as $helperType): ?>
                <div class="mb-6 last:mb-0">
                    <h3 class="text-xl font-bold text-gray-800 mb-2">
                        <?php echo htmlspecialchars($helperType['title']); ?>
                    </h3>
                    
                    <?php if (!empty($helperType['description'])): ?>
                        <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($helperType['description']); ?></p>
                    <?php endif; ?>
                    
                    <!-- Slots -->
                    <div class="space-y-3">
                        <?php foreach ($helperType['slots'] as $slot): ?>
                            <?php
                                $slotStart = new DateTime($slot['start_time']);
                                $slotEnd = new DateTime($slot['end_time']);
                                $occupancy = $slot['signups_count'] . '/' . $slot['quantity_needed'];
                                $canSignup = !$slot['is_full'] && !$slot['user_in_slot'];
                                $onWaitlist = $slot['is_full'] && !$slot['user_in_slot'];
                                
                                // Prepare slot parameters for onclick handlers
                                $slotStartFormatted = htmlspecialchars($slotStart->format('Y-m-d H:i:s'), ENT_QUOTES);
                                $slotEndFormatted = htmlspecialchars($slotEnd->format('Y-m-d H:i:s'), ENT_QUOTES);
                                $slotSignupHandler = "signupForSlot({$eventId}, {$slot['id']}, '{$slotStartFormatted}', '{$slotEndFormatted}')";
                            ?>
                            
                            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 p-4 bg-gray-50 rounded-xl border border-gray-200">
                                <div class="flex-1">
                                    <div class="font-semibold text-gray-800">
                                        <i class="fas fa-clock mr-2 text-ibc-blue"></i>
                                        <?php echo $slotStart->format('H:i'); ?> - <?php echo $slotEnd->format('H:i'); ?> Uhr
                                    </div>
                                    <div class="text-sm text-gray-600 mt-1">
                                        <span class="font-semibold"><?php echo $occupancy; ?> belegt</span>
                                    </div>
                                </div>
                                
                                <div class="flex-shrink-0">
                                    <?php if ($slot['user_in_slot']): ?>
                                        <div class="flex items-center gap-3">
                                            <span class="px-4 py-2 bg-ibc-green/10 text-ibc-green border border-ibc-green/20 rounded-xl font-semibold text-sm">
                                                <i class="fas fa-check mr-1"></i>
                                                Eingetragen
                                            </span>
                                            <?php if ($canCancel): ?>
                                                <button onclick="cancelHelperSlot(<?php echo $userSignupId; ?>)" 
                                                        class="px-4 py-2 bg-red-100 text-red-800 rounded-xl font-semibold text-sm hover:bg-red-200 ease-premium">
                                                    <i class="fas fa-times mr-1"></i>
                                                    Austragen
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($canSignup): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>" 
                                                class="rounded-xl bg-ibc-green text-white px-4 py-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all">
                                            <i class="fas fa-user-plus mr-2"></i>
                                            Als Helfer eintragen
                                        </button>
                                    <?php elseif ($onWaitlist): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>" 
                                                class="px-6 py-2 bg-yellow-600 text-white rounded-xl font-semibold hover:bg-yellow-700 ease-premium">
                                            <i class="fas fa-list mr-2"></i>
                                            Warteliste
                                        </button>
                                    <?php else: ?>
                                        <span class="px-4 py-2 bg-gray-100 text-gray-600 rounded-xl font-semibold text-sm">
                                            Belegt
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Hero & Card Styles -->
<style>
    .event-hero {
        position: relative;
        background: #1f2937;
    }
    .event-hero-image {
        width: 100%;
        height: 340px;
        position: relative;
        overflow: hidden;
    }
    @media (max-width: 640px) {
        .event-hero-image { height: 220px; }
    }
    .event-hero-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .event-hero-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.35) 50%, rgba(0,0,0,0.15) 100%);
    }
    .event-hero-content {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 1.5rem 2rem;
    }
    @media (max-width: 640px) {
        .event-hero-content { padding: 1rem 1.25rem; }
    }
</style>
<div id="message-container" class="fixed top-4 right-4 z-50 hidden">
    <div id="message-content" class="card px-6 py-4 shadow-2xl"></div>
</div>

<script>
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

// Show message helper
function showMessage(message, type = 'success') {
    const container = document.getElementById('message-container');
    const content = document.getElementById('message-content');
    
    const bgColor = type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    
    content.className = `card px-6 py-4 shadow-2xl ${bgColor}`;
    content.innerHTML = `<i class="fas ${icon} mr-2"></i>${message}`;
    
    container.classList.remove('hidden');
    
    setTimeout(() => {
        container.classList.add('hidden');
    }, 5000);
}

// Signup for event (general participation)
function signupForEvent(eventId) {
    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'signup',
            event_id: eventId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('Erfolgreich angemeldet!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Anmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Signup for helper slot
function signupForSlot(eventId, slotId, slotStart, slotEnd) {
    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'signup',
            event_id: eventId,
            slot_id: slotId,
            slot_start: slotStart,
            slot_end: slotEnd,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.status === 'waitlist') {
                showMessage('Sie wurden auf die Warteliste gesetzt', 'success');
            } else {
                showMessage('Erfolgreich eingetragen!', 'success');
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Anmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Cancel signup (general or helper slot)
function cancelSignup(signupId, message = 'Möchtest Du Deine Anmeldung wirklich stornieren?', successMessage = 'Abmeldung erfolgreich') {
    if (!confirm(message)) {
        return;
    }
    
    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'cancel',
            signup_id: signupId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(successMessage, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Abmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Cancel helper slot (wrapper for consistency)
function cancelHelperSlot(signupId) {
    cancelSignup(signupId, 'Möchtest Du Dich wirklich austragen?', 'Erfolgreich ausgetragen');
}

</script>


<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
