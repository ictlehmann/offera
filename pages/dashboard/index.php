<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/models/Invoice.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/BlogPost.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';

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
$rolesRequiringProfile = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz', 'alumni', 'mitglied', 'ressortleiter', 'anwaerter', 'ehrenmitglied'];
if (in_array($currentUser['role'], $rolesRequiringProfile) && isset($currentUser['profile_complete']) && $currentUser['profile_complete'] == 0) {
    $_SESSION['profile_incomplete_message'] = 'Bitte vervollständige dein Profil (Vorname und Nachname) um fortzufahren.';
    header('Location: ../alumni/edit.php');
    exit;
}

$user = $currentUser;
$userRole = $user['role'] ?? '';

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

// Get upcoming events from database that the current user has registered for
$nextEvents = [];
$events = [];
$currentUserId = (int)Auth::getUserId();
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT DISTINCT e.id, e.title, e.start_time, e.end_time, e.location, e.status, e.image_path, e.is_external
         FROM events e
         WHERE e.status IN ('planned', 'open', 'closed') AND e.start_time >= NOW()
           AND (
               EXISTS (SELECT 1 FROM event_registrations er WHERE er.event_id = e.id AND er.user_id = ? AND er.status = 'confirmed')
               OR EXISTS (SELECT 1 FROM event_signups es WHERE es.event_id = e.id AND es.user_id = ? AND es.status = 'confirmed')
           )
         ORDER BY e.start_time ASC LIMIT 5"
    );
    $stmt->execute([$currentUserId, $currentUserId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nextEvents = array_slice($events, 0, 3);
} catch (Exception $e) {
    error_log('dashboard: upcoming events query failed: ' . $e->getMessage());
}

// Get user's open tasks from inventory_requests and inventory_rentals tables
$openTasksCount = 0;
$userId = (int)Auth::getUserId();
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT COUNT(*) FROM inventory_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')"
    );
    $stmt->execute([$userId]);
    $openTasksCount += (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('dashboard: open tasks count (requests) failed: ' . $e->getMessage());
}
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT COUNT(*) FROM inventory_rentals WHERE user_id = ? AND status IN ('active', 'pending_return')"
    );
    $stmt->execute([$userId]);
    $openTasksCount += (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Legacy table may not exist in deployments running the new inventory_requests schema – silently ignore
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

// Get open invoices count for eligible users
$openInvoicesCount = 0;
$canAccessInvoices = Auth::canAccessPage('invoices');
if ($canAccessInvoices) {
    try {
        $rechDb = Database::getRechDB();
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']))) {
            $iStmt = $rechDb->prepare("SELECT COUNT(*) FROM invoices WHERE status IN ('pending', 'approved')");
            $iStmt->execute();
        } else {
            $iStmt = $rechDb->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status IN ('pending', 'approved')");
            $iStmt->execute([$userId]);
        }
        $openInvoicesCount = (int)$iStmt->fetchColumn();
    } catch (Exception $e) {
        error_log('dashboard: open invoices count failed: ' . $e->getMessage());
    }
}

// Get recent open invoices for status-badge display
$recentOpenInvoices = [];
if ($canAccessInvoices) {
    try {
        $rechDb = Database::getRechDB();
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']))) {
            $iStmt = $rechDb->prepare("SELECT id, description, amount, status, created_at FROM invoices WHERE status IN ('pending', 'approved') ORDER BY created_at DESC LIMIT 5");
            $iStmt->execute();
        } else {
            $iStmt = $rechDb->prepare("SELECT id, description, amount, status, created_at FROM invoices WHERE user_id = ? AND status IN ('pending', 'approved') ORDER BY created_at DESC LIMIT 5");
            $iStmt->execute([$userId]);
        }
        $recentOpenInvoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('dashboard: recent open invoices fetch failed: ' . $e->getMessage());
    }
}

// Get recent blog posts
$recentBlogPosts = [];
try {
    $recentBlogPosts = BlogPost::getAll(3, 0);
} catch (Exception $e) {
    error_log('dashboard: recent blog posts query failed: ' . $e->getMessage());
}

// Calculate profile completeness for the gamification widget
$profileCompletenessPercent = 0;
if (in_array($userRole, $rolesRequiringProfile)) {
    try {
        $alumniProfile = Alumni::getProfileByUserId($userId);
        $completenessFields = ['image_path', 'mobile_phone', 'bio', 'study_program'];
        $filledCount = 0;
        if ($alumniProfile) {
            foreach ($completenessFields as $field) {
                if (!empty($alumniProfile[$field])) {
                    $filledCount++;
                }
            }
        }
        $profileCompletenessPercent = (int)round(($filledCount / count($completenessFields)) * 100);
    } catch (Exception $e) {
        error_log('dashboard: profile completeness check failed: ' . $e->getMessage());
    }
}

$title = 'Dashboard - IBC Intranet';
ob_start();
?>

<style>
    /* ── Dashboard Event Cards ──────────────────────────── */
    .dash-event-card {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-radius: 1rem;
        border: 1.5px solid var(--border-color);
        background-color: var(--bg-card);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        text-decoration: none !important;
        color: inherit;
    }
    .dash-event-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: var(--ibc-blue) !important;
        text-decoration: none !important;
    }
    .dash-event-card-accent {
        height: 4px;
        flex-shrink: 0;
        background: var(--ibc-blue);
    }
    .dash-event-card--open    .dash-event-card-accent { background: var(--ibc-green); }
    .dash-event-card--closed  .dash-event-card-accent { background: var(--ibc-warning); }
    .dash-event-card--planned .dash-event-card-accent { background: var(--ibc-blue); }

    .dash-event-date-chip {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: var(--bg-card);
        border: 1.5px solid var(--border-color);
        border-radius: 0.75rem;
        padding: 0.4rem 0.7rem;
        min-width: 48px;
        text-align: center;
        line-height: 1;
        flex-shrink: 0;
    }
    .dash-event-date-month {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--ibc-blue);
        line-height: 1;
    }
    .dash-event-date-day {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-main);
        line-height: 1.1;
    }

    /* ── Invoice Status Badges ──────────────────────────── */
    .invoice-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .invoice-badge-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .invoice-badge--pending  { background: rgba(245,158,11,0.12); color: #92400e; }
    .invoice-badge--approved { background: rgba(234,179,8,0.12);  color: #78350f; }
    .invoice-badge--pending .invoice-badge-dot  { background: #f59e0b; }
    .invoice-badge--approved .invoice-badge-dot { background: #eab308; }
    .dark-mode .invoice-badge--pending  { background: rgba(245,158,11,0.18); color: #fde68a; }
    .dark-mode .invoice-badge--approved { background: rgba(234,179,8,0.18);  color: #fef08a; }

    /* ── Dashboard Hover Cards (generic) ───────────────── */
    .dash-hover-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .dash-hover-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-card-hover);
    }

    /* ── Blog Cards ─────────────────────────────────────── */
    .dash-blog-card {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-radius: 1rem;
        border: 1.5px solid var(--border-color);
        background-color: var(--bg-card);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        text-decoration: none !important;
        color: inherit;
    }
    .dash-blog-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: var(--ibc-blue) !important;
        text-decoration: none !important;
    }
    .dash-blog-img {
        height: 160px;
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%);
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
    }
    .dash-blog-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }
    .dash-blog-card:hover .dash-blog-img img {
        transform: scale(1.05);
    }
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .line-clamp-3 {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<?php if (!empty($user['prompt_profile_review']) && $user['prompt_profile_review'] == 1): ?>
<!-- Profile Review Prompt Modal -->
<div id="profile-review-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
    <div class="rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden transform transition-all" style="background-color: var(--bg-card)">
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
        <div class="px-6 py-6 overflow-y-auto flex-1">
            <p class="text-lg mb-6" style="color: var(--text-main)">
                Bitte überprüfe deine Daten (besonders E-Mail und Job-Daten), damit wir in Kontakt bleiben können.
            </p>
            
            <div class="rounded-lg p-4" style="background-color: var(--bg-body); border: 1px solid var(--border-color)">
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
        },
        body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
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

<?php if (empty($user['has_seen_onboarding'])): ?>
<!-- Onboarding Welcome Modal -->
<div id="onboarding-modal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 p-4">
    <div class="rounded-2xl shadow-2xl w-full max-w-lg flex flex-col overflow-hidden transform transition-all" style="background-color: var(--bg-card)">
        <!-- Slide indicators -->
        <div class="bg-gradient-to-r from-blue-600 via-blue-700 to-emerald-600 px-6 pt-6 pb-4">
            <div class="flex justify-center gap-2 mb-4">
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white transition-all duration-300" data-slide="0"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white bg-opacity-40 transition-all duration-300" data-slide="1"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white bg-opacity-40 transition-all duration-300" data-slide="2"></span>
            </div>
            <div class="flex items-center justify-center">
                <div id="onboarding-icon" class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <i id="onboarding-icon-el" class="fas fa-calendar-alt text-white text-3xl"></i>
                </div>
            </div>
            <h3 id="onboarding-title" class="text-xl font-bold text-white text-center mt-3">Events &amp; Projekte</h3>
        </div>

        <!-- Slide content -->
        <div class="px-6 py-6 flex-1" style="min-height: 160px">
            <!-- Slide 0 -->
            <div class="onboarding-slide" id="slide-0">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Entdecke kommende <strong>Events</strong> und laufende <strong>Projekte</strong> im IBC-Intranet.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Melde dich für Events an oder trag dich als Helfer ein</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Verfolge den Fortschritt laufender Projekte</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Bleib mit deinem Kalender immer auf dem neuesten Stand</li>
                </ul>
            </div>
            <!-- Slide 1 -->
            <div class="onboarding-slide hidden" id="slide-1">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Leih dir Equipment direkt über das <strong>Inventar</strong>-Modul aus – schnell und unkompliziert.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Durchsuche verfügbare Geräte und Materialien</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Stelle eine Ausleih-Anfrage in wenigen Klicks</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Behalte deine aktiven Ausleihen im Blick</li>
                </ul>
            </div>
            <!-- Slide 2 -->
            <div class="onboarding-slide hidden" id="slide-2">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Teile deine Ideen in der <strong>Ideenbox</strong> und stöbere im <strong>IBC-Shop</strong>.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Reiche Ideen ein und stimme über Vorschläge ab</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Bestelle Merchandise direkt im Shop</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Gestalte den IBC aktiv mit!</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 flex justify-between items-center" style="background-color: var(--bg-body); border-top: 1px solid var(--border-color)">
            <span id="onboarding-step-label" class="text-xs font-medium" style="color: var(--text-muted)">Schritt 1 von 3</span>
            <button id="onboarding-next-btn" onclick="onboardingNext()" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-blue-600 to-emerald-600 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-emerald-700 transition-all duration-300 shadow-md">
                Weiter <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var currentSlide = 0;
    var slides = [
        { icon: 'fa-calendar-alt', title: 'Events &amp; Projekte' },
        { icon: 'fa-box-open',     title: 'Inventar-Ausleihe' },
        { icon: 'fa-lightbulb',    title: 'Ideenbox &amp; Shop' }
    ];

    function updateSlide() {
        // Update slides visibility
        document.querySelectorAll('.onboarding-slide').forEach(function (el, i) {
            el.classList.toggle('hidden', i !== currentSlide);
        });
        // Update dots
        document.querySelectorAll('.onboarding-dot').forEach(function (el, i) {
            if (i === currentSlide) {
                el.classList.remove('bg-opacity-40');
            } else {
                el.classList.add('bg-opacity-40');
            }
        });
        // Update header icon & title
        document.getElementById('onboarding-icon-el').className = 'fas ' + slides[currentSlide].icon + ' text-white text-3xl';
        document.getElementById('onboarding-title').innerHTML = slides[currentSlide].title;
        // Update step label
        document.getElementById('onboarding-step-label').textContent = 'Schritt ' + (currentSlide + 1) + ' von 3';
        // Update button
        var btn = document.getElementById('onboarding-next-btn');
        if (currentSlide === slides.length - 1) {
            btn.innerHTML = 'Loslegen <i class="fas fa-rocket ml-2"></i>';
        } else {
            btn.innerHTML = 'Weiter <i class="fas fa-arrow-right ml-2"></i>';
        }
    }

    window.onboardingNext = function () {
        if (currentSlide < slides.length - 1) {
            currentSlide++;
            updateSlide();
        } else {
            // Last slide – save to DB and close
            var modal = document.getElementById('onboarding-modal');
            fetch(window.location.origin + '/api/complete_onboarding.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
            })
            .then(function (r) { return r.json(); })
            .catch(function (e) { console.error('Onboarding save error:', e); return {}; })
            .finally(function () {
                modal.style.display = 'none';
            });
        }
    };
})();
</script>
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
    <div class="grid grid-cols-1 <?php echo $canAccessInvoices ? 'md:grid-cols-3' : 'md:grid-cols-2'; ?> gap-6">
        <!-- My Open Rentals Widget -->
        <a href="/pages/inventory/my_rentals.php" class="block group">
            <div class="card p-6 rounded-2xl hover:shadow-2xl transition-all duration-300 cursor-pointer border-t-4 border-orange-400" style="background-color: var(--bg-card)">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-orange-500 mb-1">Ausleihen</p>
                        <h3 class="text-lg font-bold" style="color: var(--text-main)">Meine Ausleihen</h3>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-orange-400 to-orange-500 rounded-xl flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-box-open text-white"></i>
                    </div>
                </div>
                <div class="flex items-end justify-between">
                    <span class="text-4xl font-extrabold text-orange-500"><?php echo $openTasksCount; ?></span>
                    <span class="inline-flex items-center text-orange-500 font-semibold text-sm group-hover:translate-x-1 transition-transform">
                        <?php echo $openTasksCount > 0 ? 'Verwalten' : 'Zur Ausleihe'; ?> <i class="fas fa-arrow-right ml-1.5"></i>
                    </span>
                </div>
                <p class="text-xs mt-2" style="color: var(--text-muted)">
                    <?php if ($openTasksCount > 0): ?>
                        <i class="fas fa-exclamation-circle text-orange-400 mr-1"></i><?php echo $openTasksCount; ?> offene <?php echo $openTasksCount == 1 ? 'Ausleihe' : 'Ausleihen'; ?>
                    <?php else: ?>
                        <i class="fas fa-check-circle text-green-500 mr-1"></i>Keine offenen Ausleihen
                    <?php endif; ?>
                </p>
            </div>
        </a>

        <!-- Next Event Widget -->
        <div class="card p-6 rounded-2xl hover:shadow-2xl transition-all duration-300 border-t-4 border-blue-500" style="background-color: var(--bg-card)">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-blue-500 mb-1">Events</p>
                    <h3 class="text-lg font-bold" style="color: var(--text-main)">Neuestes Event</h3>
                </div>
                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-md flex-shrink-0">
                    <i class="fas fa-calendar-alt text-white"></i>
                </div>
            </div>
            <?php if (!empty($nextEvents)):
                $nextEvent = $nextEvents[0];
                $ts = strtotime($nextEvent['start_time']);
                $monthAbbrs = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
                $monthAbbr = $monthAbbrs[(int)date('n', $ts) - 1];
            ?>
            <div class="flex items-center gap-3 mb-3">
                <div class="flex-shrink-0 w-12 rounded-lg overflow-hidden shadow text-center select-none">
                    <div class="bg-blue-600 text-white text-xs font-bold uppercase tracking-wider py-0.5"><?php echo $monthAbbr; ?></div>
                    <div class="text-blue-600 text-xl font-extrabold leading-tight py-0.5" style="background-color: var(--bg-card)"><?php echo date('d', $ts); ?></div>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold truncate text-sm" style="color: var(--text-main)"><?php echo htmlspecialchars($nextEvent['title']); ?></p>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)"><i class="fas fa-clock mr-1 text-blue-400"></i><?php echo date('H:i', $ts); ?> Uhr</p>
                </div>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs" style="color: var(--text-muted)">+<?php echo count($nextEvents) - 1; ?> weitere Events</span>
                <a href="../events/view.php?id=<?php echo $nextEvent['id']; ?>" class="inline-flex items-center text-blue-500 font-semibold text-sm hover:translate-x-1 transition-transform">
                    Details <i class="fas fa-arrow-right ml-1.5"></i>
                </a>
            </div>
            <?php else: ?>
            <p class="text-sm mb-3" style="color: var(--text-muted)">Keine anstehenden Events</p>
            <a href="../events/index.php" class="inline-flex items-center text-blue-500 font-semibold text-sm hover:translate-x-1 transition-transform">
                Events durchsuchen <i class="fas fa-arrow-right ml-1.5"></i>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($canAccessInvoices): ?>
        <!-- Open Invoices Widget -->
        <a href="/pages/invoices/index.php" class="block group">
            <div class="card p-6 rounded-2xl hover:shadow-2xl transition-all duration-300 cursor-pointer border-t-4 border-emerald-500" style="background-color: var(--bg-card)">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-600 mb-1">Rechnungen</p>
                        <h3 class="text-lg font-bold" style="color: var(--text-main)">Offene Rechnungen</h3>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-file-invoice-dollar text-white"></i>
                    </div>
                </div>
                <div class="flex items-end justify-between">
                    <span class="text-4xl font-extrabold text-emerald-600"><?php echo $openInvoicesCount; ?></span>
                    <span class="inline-flex items-center text-emerald-600 font-semibold text-sm group-hover:translate-x-1 transition-transform">
                        <?php echo $openInvoicesCount > 0 ? 'Anzeigen' : 'Zur Übersicht'; ?> <i class="fas fa-arrow-right ml-1.5"></i>
                    </span>
                </div>
                <p class="text-xs mt-2" style="color: var(--text-muted)">
                    <?php if ($openInvoicesCount > 0): ?>
                        <i class="fas fa-hourglass-half text-amber-500 mr-1"></i><?php echo $openInvoicesCount; ?> <?php echo $openInvoicesCount == 1 ? 'Rechnung ausstehend' : 'Rechnungen ausstehend'; ?>
                    <?php else: ?>
                        <i class="fas fa-check-circle text-green-500 mr-1"></i>Alle Rechnungen bearbeitet
                    <?php endif; ?>
                </p>
            </div>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($userRole, $rolesRequiringProfile) && $profileCompletenessPercent < 100): ?>
<!-- Profile Completeness Widget -->
<div class="max-w-6xl mx-auto mb-10">
    <div class="card p-6 rounded-2xl shadow-lg" style="background-color: var(--bg-card); border-left: 4px solid #a855f7">
        <div class="flex flex-col sm:flex-row items-start gap-4">
            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center flex-shrink-0 shadow-md">
                <i class="fas fa-user-circle text-white text-xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-bold mb-1" style="color: var(--text-main)">
                    🎯 Vervollständige dein Profil!
                </h3>
                <p class="text-sm mb-4" style="color: var(--text-muted)">
                    Ein vollständiges Profil hilft deinen Kolleginnen und Kollegen, dich besser kennenzulernen. Du bist schon zu <strong><?php echo $profileCompletenessPercent; ?>%</strong> fertig – fast geschafft!
                </p>
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs font-semibold" style="color: var(--text-muted)">Profil-Fortschritt</span>
                        <span class="text-xs font-bold" style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</span>
                    </div>
                    <div class="w-full rounded-full h-3 overflow-hidden" style="background-color: var(--border-color)">
                        <div class="h-3 rounded-full transition-all duration-500" style="width: <?php echo $profileCompletenessPercent; ?>%; background: linear-gradient(90deg, #a855f7, #ec4899)"></div>
                    </div>
                </div>
                <a href="../alumni/edit.php" class="inline-flex items-center px-4 py-2 text-white rounded-lg font-semibold text-sm transition-all duration-300 shadow-md hover:opacity-90" style="background: linear-gradient(90deg, #a855f7, #ec4899)">
                    <i class="fas fa-user-edit mr-2"></i>Profil vervollständigen
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Meine nächsten Events Section -->
<?php if (!empty($events)): ?>
<div class="max-w-6xl mx-auto mb-10">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mr-3 shadow-md shrink-0">
                <i class="fas fa-calendar-alt text-white text-sm"></i>
            </div>
            <h2 class="text-2xl font-bold" style="color: var(--text-main)">Meine nächsten Events</h2>
        </div>
        <a href="../events/index.php" class="text-blue-600 hover:text-blue-700 font-semibold text-sm shrink-0">
            Alle Events <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    <?php
        $monthAbbrs = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        $eventStatusLabels = [
            'open'    => ['label' => 'Anmeldung offen',    'color' => 'text-green-700 bg-green-100 dark:bg-green-900/40 dark:text-green-300'],
            'planned' => ['label' => 'Geplant',            'color' => 'text-blue-700 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'],
            'closed'  => ['label' => 'Anmeldung geschlossen', 'color' => 'text-amber-700 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'],
        ];
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($events as $event): ?>
        <?php
            $ts = strtotime($event['start_time']);
            $monthAbbr = $monthAbbrs[(int)date('n', $ts) - 1];
            $eventStatus = $event['status'] ?? 'planned';
            $statusInfo = $eventStatusLabels[$eventStatus] ?? $eventStatusLabels['planned'];
            // Countdown
            $diffSecs = $ts - time();
            $countdown = '';
            if ($diffSecs > 0) {
                $days = floor($diffSecs / 86400);
                $hours = floor(($diffSecs % 86400) / 3600);
                $countdown = $days > 0 ? "Noch {$days} Tag" . ($days != 1 ? 'e' : '') . ", {$hours} Std" : "Noch {$hours} Std";
            }
        ?>
        <a href="../events/view.php?id=<?php echo (int)$event['id']; ?>" class="dash-event-card dash-event-card--<?php echo htmlspecialchars($eventStatus); ?>">
            <!-- Status accent strip -->
            <div class="dash-event-card-accent"></div>
            <!-- Card header: gradient background with date chip -->
            <div class="relative flex items-start gap-4 p-5 pb-4">
                <!-- Date chip -->
                <div class="dash-event-date-chip">
                    <span class="dash-event-date-month"><?php echo $monthAbbr; ?></span>
                    <span class="dash-event-date-day"><?php echo date('d', $ts); ?></span>
                </div>
                <!-- Title & meta -->
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-base leading-snug line-clamp-2 mb-1.5" style="color: var(--text-main)">
                        <?php echo htmlspecialchars($event['title']); ?>
                    </h3>
                    <div class="space-y-1 text-xs" style="color: var(--text-muted)">
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-clock text-blue-400 w-3 text-center"></i>
                            <span><?php echo date('H:i', $ts); ?> Uhr</span>
                        </div>
                        <?php if (!empty($event['location'])): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-map-marker-alt text-blue-400 w-3 text-center"></i>
                            <span class="truncate"><?php echo htmlspecialchars($event['location']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Footer: status badge + countdown -->
            <div class="px-5 pb-4 flex items-center justify-between gap-2" style="border-top: 1px solid var(--border-color)">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $statusInfo['color']; ?>">
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($countdown): ?>
                <span class="text-xs font-medium" style="color: var(--text-muted)">
                    <i class="fas fa-hourglass-half mr-1 text-amber-400"></i><?php echo $countdown; ?>
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 text-blue-600 font-semibold text-xs">
                    Details <i class="fas fa-arrow-right"></i>
                </span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Offene Rechnungen Section -->
<?php if ($canAccessInvoices && !empty($recentOpenInvoices)): ?>
<div class="max-w-6xl mx-auto mb-10">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center mr-3 shadow-md shrink-0">
                <i class="fas fa-file-invoice-dollar text-white text-sm"></i>
            </div>
            <h2 class="text-2xl font-bold" style="color: var(--text-main)">Offene Rechnungen</h2>
        </div>
        <a href="/pages/invoices/index.php" class="text-emerald-600 hover:text-emerald-700 font-semibold text-sm shrink-0">
            Alle Rechnungen <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    <?php
        $invStatusLabels = [
            'pending'  => 'In Prüfung',
            'approved' => 'Offen',
        ];
    ?>
    <div class="card rounded-2xl shadow-lg overflow-hidden" style="background-color: var(--bg-card)">
        <div class="divide-y" style="border-color: var(--border-color)">
            <?php foreach ($recentOpenInvoices as $inv): ?>
            <?php
                $invStatus = $inv['status'];
                $badgeLbl  = $invStatusLabels[$invStatus] ?? ucfirst($invStatus);
            ?>
            <a href="/pages/invoices/index.php" class="flex items-center gap-4 px-5 py-4 dash-hover-card" style="color: inherit; text-decoration: none;">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center flex-shrink-0 shadow-sm">
                    <i class="fas fa-receipt text-white text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm truncate" style="color: var(--text-main)">
                        <?php echo htmlspecialchars($inv['description'] ?: 'Keine Beschreibung'); ?>
                    </p>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)">
                        <i class="fas fa-calendar-alt mr-1 text-emerald-500"></i>
                        <?php echo date('d.m.Y', strtotime($inv['created_at'])); ?>
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="font-bold text-sm text-emerald-700 dark:text-emerald-400">
                        <?php echo number_format((float)$inv['amount'], 2, ',', '.'); ?>&nbsp;€
                    </span>
                    <span class="invoice-badge invoice-badge--<?php echo $invStatus; ?>">
                        <span class="invoice-badge-dot"></span>
                        <?php echo $badgeLbl; ?>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

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

<!-- Neuigkeiten aus dem Blog Section -->
<?php if (!empty($recentBlogPosts)): ?>
<div class="max-w-6xl mx-auto mb-10">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center mr-3 shadow-md shrink-0">
                <i class="fas fa-newspaper text-white text-sm"></i>
            </div>
            <h2 class="text-2xl font-bold" style="color: var(--text-main)">Neuigkeiten aus dem Blog</h2>
        </div>
        <a href="../blog/index.php" class="text-indigo-600 hover:text-indigo-700 font-semibold text-sm shrink-0">
            Alle Artikel <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    <?php
        $blogCategoryColors = [
            'Allgemein'          => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            'IT'                 => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            'Marketing'          => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
            'Human Resources'    => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
            'Qualitätsmanagement'=> 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
            'Akquise'            => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        ];
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($recentBlogPosts as $post): ?>
        <?php
            $catColor = $blogCategoryColors[$post['category'] ?? ''] ?? $blogCategoryColors['Allgemein'];
            $postDate = new DateTime($post['created_at']);
            $excerpt  = strip_tags($post['content'] ?? '');
            $excerpt  = strlen($excerpt) > 120 ? substr($excerpt, 0, 120) . '…' : $excerpt;
        ?>
        <a href="../blog/view.php?id=<?php echo (int)$post['id']; ?>" class="dash-blog-card">
            <!-- Image / placeholder -->
            <div class="dash-blog-img">
                <?php if (!empty($post['image_path']) && $post['image_path'] !== BlogPost::DEFAULT_IMAGE): ?>
                    <img src="/<?php echo htmlspecialchars(ltrim($post['image_path'], '/')); ?>"
                         alt="<?php echo htmlspecialchars($post['title']); ?>"
                         loading="lazy">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-indigo-500 to-purple-600">
                        <i class="fas fa-newspaper text-white/30 text-4xl"></i>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Content -->
            <div class="p-4 flex-1 flex flex-col">
                <div class="mb-2">
                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full <?php echo $catColor; ?>">
                        <?php echo htmlspecialchars($post['category'] ?? 'Allgemein'); ?>
                    </span>
                </div>
                <h3 class="font-bold text-base leading-snug line-clamp-2 mb-1.5" style="color: var(--text-main)">
                    <?php echo htmlspecialchars($post['title']); ?>
                </h3>
                <p class="text-xs mb-2" style="color: var(--text-muted)">
                    <i class="fas fa-calendar-alt mr-1 text-indigo-400"></i>
                    <?php echo $postDate->format('d.m.Y'); ?>
                </p>
                <p class="text-sm flex-1 line-clamp-3" style="color: var(--text-muted)">
                    <?php echo htmlspecialchars($excerpt); ?>
                </p>
                <div class="mt-3 pt-3 flex items-center justify-between text-xs" style="border-top: 1px solid var(--border-color); color: var(--text-muted)">
                    <span class="truncate"><i class="fas fa-user-circle mr-1 text-indigo-400"></i><?php echo htmlspecialchars(explode('@', $post['author_email'])[0]); ?></span>
                    <span class="inline-flex items-center gap-1 text-indigo-600 font-semibold flex-shrink-0">
                        Lesen <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

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
        body: JSON.stringify({ poll_id: pollId, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
