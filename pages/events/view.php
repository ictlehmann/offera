<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../src/CalendarService.php';

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

<div class="max-w-5xl mx-auto">
    <!-- Back Button -->
    <a href="index.php" class="inline-flex items-center text-ibc-blue hover:text-ibc-blue-dark mb-6 ease-premium">
        <i class="fas fa-arrow-left mr-2"></i>
        Zurück zur Übersicht
    </a>

    <!-- Event Hero Header -->
    <div class="bg-gradient-to-br from-ibc-blue to-ibc-blue-dark shadow-premium rounded-xl p-8 mb-6 text-white">
        <div class="flex flex-col md:flex-row justify-between items-start gap-4 mb-4">
            <div class="flex-1">
                <h1 class="text-3xl md:text-4xl font-bold mb-4">
                    <?php echo htmlspecialchars($event['title']); ?>
                </h1>
                
                <!-- Status Badge -->
                <?php 
                $statusLabels = [
                    'planned' => ['label' => 'Geplant', 'color' => 'bg-white/20 text-white border-white/30'],
                    'open' => ['label' => 'Anmeldung offen', 'color' => 'bg-ibc-green/20 text-white border-ibc-green/30'],
                    'closed' => ['label' => 'Anmeldung geschlossen', 'color' => 'bg-yellow-500/20 text-white border-yellow-500/30'],
                    'running' => ['label' => 'Läuft gerade', 'color' => 'bg-white/30 text-white border-white/50'],
                    'past' => ['label' => 'Beendet', 'color' => 'bg-white/10 text-white/70 border-white/20']
                ];
                $currentStatus = $event['status'] ?? 'planned';
                $statusInfo = $statusLabels[$currentStatus] ?? ['label' => $currentStatus, 'color' => 'bg-white/20 text-white border-white/30'];
                ?>
                <div class="inline-flex items-center px-4 py-2 rounded-xl font-semibold text-sm <?php echo $statusInfo['color']; ?> border mb-4">
                    <i class="fas fa-circle text-xs mr-2"></i>
                    <?php echo $statusInfo['label']; ?>
                </div>
            </div>
            
            <!-- Registration Status -->
            <?php if ($isRegistered): ?>
                <div class="flex-shrink-0">
                    <div class="px-6 py-3 bg-white/20 backdrop-blur-sm text-white rounded-xl font-semibold text-center border border-white/30">
                        <i class="fas fa-check-circle mr-2"></i>
                        Angemeldet
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event Details Card -->
    <div class="glass-card shadow-soft rounded-xl p-8 mb-6">
        <!-- Event Meta -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-600">
            <div class="flex items-start">
                <i class="fas fa-calendar w-6 mt-1 text-ibc-blue"></i>
                <div>
                    <div class="font-semibold">Beginn</div>
                    <div><?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?> Uhr</div>
                </div>
            </div>
            
            <div class="flex items-start">
                <i class="fas fa-clock w-6 mt-1 text-ibc-blue"></i>
                <div>
                    <div class="font-semibold">Ende</div>
                    <div><?php echo date('d.m.Y H:i', strtotime($event['end_time'])); ?> Uhr</div>
                </div>
            </div>
            
            <?php if (!empty($event['location'])): ?>
                <div class="flex items-start">
                    <i class="fas fa-map-marker-alt w-6 mt-1 text-ibc-blue"></i>
                    <div class="flex-1">
                        <div class="font-semibold">Veranstaltungsort</div>
                        <div class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($event['location']); ?></div>
                        <?php if (!empty($event['maps_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['maps_link']); ?>" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               class="inline-flex items-center mt-2 px-4 py-2 bg-ibc-green text-white rounded-xl hover:shadow-glow-green ease-premium font-semibold text-sm">
                                <i class="fas fa-route mr-2"></i>
                                Route planen
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($event['contact_person'])): ?>
                <div class="flex items-start">
                    <i class="fas fa-user w-6 mt-1 text-ibc-blue"></i>
                    <div>
                        <div class="font-semibold">Ansprechpartner</div>
                        <div><?php echo htmlspecialchars($event['contact_person']); ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Description -->
        <?php if (!empty($event['description'])): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h2 class="text-xl font-bold text-gray-800 mb-3">Beschreibung</h2>
                <p class="text-gray-700 whitespace-pre-line"><?php echo htmlspecialchars($event['description']); ?></p>
            </div>
        <?php endif; ?>

        <!-- Event Image -->
        <?php if (!empty($event['image_path'])): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h2 class="text-xl font-bold text-gray-800 mb-3">Event-Bild</h2>
                <?php 
                    // Check if image file exists before displaying
                    $imagePath = $event['image_path'];
                    $fullImagePath = __DIR__ . '/../../' . $imagePath;
                    
                    // Validate path to prevent directory traversal
                    $realPath = realpath($fullImagePath);
                    $baseDir = realpath(__DIR__ . '/../../');
                    $imageExists = $realPath && $baseDir && strpos($realPath, $baseDir) === 0 && file_exists($realPath);
                ?>
                <?php if ($imageExists): ?>
                    <img 
                        src="<?php echo htmlspecialchars(BASE_URL . '/' . $imagePath); ?>" 
                        alt="<?php echo htmlspecialchars($event['title']); ?>"
                        class="w-full max-w-2xl rounded-xl border border-gray-300 shadow-soft"
                    >
                <?php else: ?>
                    <div class="w-full max-w-2xl rounded-xl border border-gray-300 bg-gray-100 p-8 text-center">
                        <i class="fas fa-image text-gray-400 text-6xl mb-4"></i>
                        <p class="text-gray-600">Bild nicht verfügbar</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Participant Counter -->
        <?php if (!$event['is_external']): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <div class="flex items-center justify-center bg-gradient-to-r from-ibc-blue/10 to-ibc-green/10 rounded-xl p-6">
                    <div class="text-center">
                        <div class="text-4xl font-bold text-ibc-blue mb-2">
                            <?php echo $registrationCount; ?>
                        </div>
                        <div class="text-lg font-semibold text-gray-700">
                            Angemeldete Teilnehmer
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Participants List -->
        <?php if (!$event['is_external'] && !empty($participants)): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h2 class="text-xl font-bold text-gray-800 mb-3">Angemeldete Teilnehmer</h2>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($participants as $participant): ?>
                        <li class="py-2 text-gray-700">
                            <?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Calendar Export Buttons -->
        <div class="mt-6 pt-6 border-t border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-calendar-plus mr-2 text-ibc-blue"></i>
                In Kalender eintragen
            </h3>
            <div class="flex flex-wrap gap-3">
                <!-- Google Calendar Button -->
                <a href="<?php echo htmlspecialchars(CalendarService::getGoogleLink($event)); ?>" 
                   target="_blank" 
                   rel="noopener noreferrer"
                   class="inline-flex items-center px-5 py-2.5 bg-white border-2 border-gray-300 text-gray-700 rounded-xl font-semibold hover:border-ibc-blue hover:text-ibc-blue ease-premium shadow-sm">
                    <i class="fab fa-google mr-2 text-lg"></i>
                    Google Kalender
                </a>
                
                <!-- iCal Download Button -->
                <a href="../../api/download_ics.php?event_id=<?php echo htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8'); ?>" 
                   class="inline-flex items-center px-5 py-2.5 bg-white border-2 border-gray-300 text-gray-700 rounded-xl font-semibold hover:border-ibc-blue hover:text-ibc-blue ease-premium shadow-sm">
                    <i class="fas fa-download mr-2"></i>
                    iCal herunterladen
                </a>
            </div>
        </div>

        <!-- Participation Button -->
        <div class="flex gap-4 mt-6 pt-6 border-t border-gray-200">
            <?php if (!empty($event['registration_link'])): ?>
                <!-- External Registration (Microsoft Forms) - Open Link in new tab -->
                <a href="<?php echo htmlspecialchars($event['registration_link']); ?>" 
                   target="_blank" 
                   rel="noopener noreferrer"
                   class="inline-flex items-center px-8 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium">
                    <i class="fas fa-external-link-alt mr-2"></i>
                    Jetzt anmelden
                </a>
            <?php elseif ($event['is_external']): ?>
                <!-- External Event - Open Link -->
                <?php if (!empty($event['external_link'])): ?>
                    <a href="<?php echo htmlspecialchars($event['external_link']); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="inline-flex items-center px-8 py-3 bg-ibc-blue text-white rounded-xl font-semibold hover:bg-ibc-blue-dark ease-premium shadow-soft">
                        <i class="fas fa-external-link-alt mr-2"></i>
                        Zur Anmeldung (extern)
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <!-- Internal Event - AJAX Signup -->
                <?php if (!$isRegistered && !$userSlotId): ?>
                    <button onclick="signupForEvent(<?php echo $eventId; ?>)" 
                            class="inline-flex items-center px-8 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium">
                        <i class="fas fa-user-plus mr-2"></i>
                        Jetzt anmelden
                    </button>
                <?php elseif ($canCancel && $userSignupId && !$userSlotId): ?>
                    <button onclick="cancelSignup(<?php echo $userSignupId; ?>)" 
                            class="inline-flex items-center px-8 py-3 bg-red-600 text-white rounded-xl font-semibold hover:bg-red-700 ease-premium">
                        <i class="fas fa-user-times mr-2"></i>
                        Abmelden
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

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


<!-- Success/Error Message Container -->
<div id="message-container" class="fixed top-4 right-4 z-50 hidden">
    <div id="message-content" class="card px-6 py-4 shadow-2xl"></div>
</div>

<script>
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
            event_id: eventId
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
            slot_end: slotEnd
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
            signup_id: signupId
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
