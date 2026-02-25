<?php
/**
 * Mass Invitations - Send bulk email invites using JSON templates
 * Access: Board members with manage_users permission
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/database.php';

if (!Auth::check() || !Auth::canManageUsers()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error   = '';

const MAIL_BATCH_SIZE = 200;

// Entra role names for quick-select groups
// Alumni group: users whose Microsoft Entra roles include one of these
const ALUMNI_ENTRA_ROLES = ['Alumni', 'Alumni-Finanzprüfer', 'Ehrenmitglied', 'Alumni Vorstand'];
// Mitglieder group: all users NOT in the alumni group (complementary selection)

/**
 * Apply placeholder substitution to a mail body for one recipient.
 *
 * @param array|null $event Optional event data for event-specific placeholders.
 */
function applyMailPlaceholders(string $body, string $firstName, string $lastName, string $eventName, ?array $event = null): string {
    $anrede = $firstName !== '' ? "Hallo $firstName" : 'Hallo';
    $body = str_replace(
        ['{Anrede}', '{Vorname}', '{Nachname}', '{Event_Name}'],
        [$anrede, $firstName, $lastName, $eventName],
        $body
    );

    if ($event !== null) {
        $startTime = !empty($event['start_time']) ? strtotime($event['start_time']) : null;
        $germanDays   = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        $germanMonths = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
        $dayName  = $startTime ? $germanDays[(int)date('w', $startTime)] : '';
        $dayOf    = $startTime ? (string)(int)date('j', $startTime) : '';
        $month    = $startTime ? $germanMonths[(int)date('n', $startTime) - 1] : '';
        $hour     = $startTime ? date('H:i', $startTime) : '';

        $body = str_replace(
            ['{eventDateDay}', '{eventDateDayOf}', '{eventDateMonth}', '{EventDateHour}', '{location}', '{trainingLink}'],
            [
                $dayName,
                $dayOf,
                $month,
                $hour,
                $event['location'] ?? '',
                $event['registration_link'] ?? '',
            ],
            $body
        );
    }

    return $body;
}

/**
 * Send one personalised email and return true on success.
 */
function sendPersonalisedMail(array $r, string $subject, string $rawBody, string $eventName, ?array $event = null): bool {
    $firstName = $r['first_name'] ?? '';
    $lastName  = $r['last_name']  ?? '';
    $personalBody = applyMailPlaceholders($rawBody, $firstName, $lastName, $eventName, $event);
    $sanitized = nl2br(htmlspecialchars($personalBody, ENT_QUOTES, 'UTF-8'));
    $entraNote = '<p style="margin-top:20px;padding:12px;background:#f0f4ff;border-left:4px solid #4f46e5;border-radius:4px;font-size:14px;">'
        . '<strong>Hinweis:</strong> Der Login ins IBC Intranet erfolgt ausschließlich über deinen Microsoft-Account (Entra ID). '
        . 'Bitte nutze die Schaltfläche „Mit Microsoft anmelden" auf der Login-Seite.</p>';
    $htmlBody = MailService::getTemplate(
        htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
        '<p class="email-text">' . $sanitized . '</p>' . $entraNote
    );
    return MailService::sendEmail($r['email'], $subject, $htmlBody);
}

// Load all events for the Event-Name dropdown (upcoming events first)
$allEvents = array_reverse(Event::getEvents());

// Load available JSON templates
$templateDir  = realpath(__DIR__ . '/../../assets/mail_vorlage');
$templateFiles = [];
if ($templateDir && is_dir($templateDir)) {
    foreach (glob($templateDir . '/*.json') as $file) {
        $name = basename($file, '.json');
        // Only allow safe names (alphanumeric, underscores, hyphens, spaces)
        if (preg_match('/^[a-zA-Z0-9_\- ]+$/', $name)) {
            $templateFiles[] = $name;
        }
    }
    sort($templateFiles);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_bulk_invite'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    CSRFHandler::verifyToken($csrfToken);
    $subject   = trim($_POST['bulk_subject'] ?? '');
    $body      = trim($_POST['bulk_body']    ?? '');
    $eventId   = (int)($_POST['event_id']    ?? 0);
    $selectedEvent = $eventId > 0 ? Event::getById($eventId, false) : null;
    $eventName = $selectedEvent ? ($selectedEvent['title'] ?? '') : '';

    if (empty($subject) || empty($body)) {
        $error = 'Betreff und Nachrichtentext dürfen nicht leer sein.';
    } else {
        $recipients = [];

        // Option A: CSV upload
        if (!empty($_FILES['bulk_csv']['tmp_name'])) {
            $csvPath = $_FILES['bulk_csv']['tmp_name'];
            if (($handle = fopen($csvPath, 'r')) !== false) {
                while (($row = fgetcsv($handle)) !== false) {
                    $email = trim($row[0]);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = [
                            'email'      => $email,
                            'first_name' => isset($row[1]) ? trim($row[1]) : '',
                            'last_name'  => isset($row[2]) ? trim($row[2]) : '',
                        ];
                    }
                }
                fclose($handle);
            }
        }

        // Option B: selected system users
        $selectedIds = $_POST['bulk_user_ids'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $allUsers = User::getAll();
            $idSet    = array_flip(array_map('intval', $selectedIds));
            foreach ($allUsers as $u) {
                if (isset($idSet[(int)$u['id']])) {
                    $recipients[] = [
                        'email'      => $u['email'],
                        'first_name' => $u['first_name'] ?? '',
                        'last_name'  => $u['last_name']  ?? '',
                    ];
                }
            }
        }

        if (empty($recipients)) {
            $error = 'Keine Empfänger ausgewählt. Bitte CSV hochladen oder Benutzer auswählen.';
        } else {
            $total = count($recipients);

            if ($total > MAIL_BATCH_SIZE) {
                // Queue all recipients, send the first batch immediately
                try {
                    $db    = Database::getContentDB();
                    $jobId = null;

                    $db->beginTransaction();
                    $stmt = $db->prepare("
                        INSERT INTO mass_mail_jobs
                            (subject, body_template, event_name, event_id, status, next_run_at, created_by, total_recipients)
                        VALUES (?, ?, ?, ?, 'paused', DATE_ADD(NOW(), INTERVAL 1 HOUR), ?, ?)
                    ");
                    $stmt->execute([$subject, $body, $eventName, $eventId > 0 ? $eventId : null, Auth::user()['id'], $total]);
                    $jobId = (int)$db->lastInsertId();

                    $insRecip = $db->prepare("
                        INSERT INTO mass_mail_recipients (job_id, email, first_name, last_name)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($recipients as $r) {
                        $insRecip->execute([$jobId, $r['email'], $r['first_name'], $r['last_name']]);
                    }
                    $db->commit();

                    // Send first batch
                    $sent   = 0;
                    $failed = 0;
                    $batch  = array_slice($recipients, 0, MAIL_BATCH_SIZE);
                    $updStatus = $db->prepare("
                        UPDATE mass_mail_recipients SET status = ?, processed_at = NOW()
                        WHERE job_id = ? AND email = ? AND status = 'pending'
                    ");
                    foreach ($batch as $r) {
                        if (sendPersonalisedMail($r, $subject, $body, $eventName, $selectedEvent)) {
                            $sent++;
                            $updStatus->execute(['sent', $jobId, $r['email']]);
                        } else {
                            $failed++;
                            $updStatus->execute(['failed', $jobId, $r['email']]);
                            error_log("Bulk invite: failed to send to " . $r['email']);
                        }
                    }

                    $db->prepare("
                        UPDATE mass_mail_jobs SET sent_count = sent_count + ?, failed_count = failed_count + ?
                        WHERE id = ?
                    ")->execute([$sent, $failed, $jobId]);

                    $remaining = $total - MAIL_BATCH_SIZE;
                    $message   = "Erste " . MAIL_BATCH_SIZE . " E-Mail(s) versendet (Versandt: {$sent}, Fehlgeschlagen: {$failed}). "
                        . "{$remaining} weitere wurden in die Warteschlange gestellt und werden automatisch nach 1 Stunde fortgesetzt.";
                } catch (Exception $e) {
                    if (isset($db) && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log("Batch mail queue error: " . $e->getMessage());
                    $error = 'Fehler beim Erstellen der Warteschlange. Bitte versuchen Sie es erneut.';
                }
            } else {
                // Small batch – send all directly
                $sent   = 0;
                $failed = 0;
                foreach ($recipients as $r) {
                    if (sendPersonalisedMail($r, $subject, $body, $eventName, $selectedEvent)) {
                        $sent++;
                    } else {
                        $failed++;
                        error_log("Bulk invite: failed to send to " . $r['email']);
                    }
                }

                if ($failed === 0) {
                    $message = "Einladungen erfolgreich versendet: {$sent} E-Mail(s).";
                } else {
                    $message = "Versandt: {$sent}, Fehlgeschlagen: {$failed}.";
                }
            }
        }
    }
}

// Handle "Continue queue" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['continue_queue'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    CSRFHandler::verifyToken($csrfToken);
    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId > 0) {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("SELECT * FROM mass_mail_jobs WHERE id = ? AND status = 'paused'");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if ($job) {
                $jobEvent = !empty($job['event_id']) ? Event::getById((int)$job['event_id'], false) : null;
                // MAIL_BATCH_SIZE is a trusted integer constant – safe to interpolate as LIMIT
                $pendingStmt = $db->prepare("
                    SELECT * FROM mass_mail_recipients WHERE job_id = ? AND status = 'pending'
                    LIMIT " . MAIL_BATCH_SIZE
                );
                $pendingStmt->execute([$jobId]);
                $batch = $pendingStmt->fetchAll();

                $sent   = 0;
                $failed = 0;
                $updStatus = $db->prepare("
                    UPDATE mass_mail_recipients SET status = ?, processed_at = NOW()
                    WHERE id = ?
                ");
                foreach ($batch as $r) {
                    if (sendPersonalisedMail($r, $job['subject'], $job['body_template'], $job['event_name'] ?? '', $jobEvent)) {
                        $sent++;
                        $updStatus->execute(['sent', $r['id']]);
                    } else {
                        $failed++;
                        $updStatus->execute(['failed', $r['id']]);
                    }
                }

                $db->prepare("
                    UPDATE mass_mail_jobs SET sent_count = sent_count + ?, failed_count = failed_count + ?,
                        next_run_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                    WHERE id = ?
                ")->execute([$sent, $failed, $jobId]);

                // Check if all done
                $cntStmt = $db->prepare("
                    SELECT COUNT(*) FROM mass_mail_recipients WHERE job_id = ? AND status = 'pending'
                ");
                $cntStmt->execute([$jobId]);
                $remaining = (int)$cntStmt->fetchColumn();
                if ($remaining === 0) {
                    $db->prepare("UPDATE mass_mail_jobs SET status = 'completed' WHERE id = ?")->execute([$jobId]);
                }

                $message = "Batch verarbeitet – Versandt: {$sent}, Fehlgeschlagen: {$failed}. Verbleibend: {$remaining}.";
            } else {
                $error = 'Job nicht gefunden oder bereits abgeschlossen.';
            }
        } catch (Exception $e) {
            error_log("Continue queue error: " . $e->getMessage());
            $error = 'Fehler beim Fortsetzen der Warteschlange.';
        }
    }
}

// Auto-process paused jobs whose next_run_at has passed
try {
    $db = Database::getContentDB();
    $autoJobs = $db->query("
        SELECT * FROM mass_mail_jobs
        WHERE status = 'paused' AND next_run_at IS NOT NULL AND next_run_at <= NOW()
        LIMIT 1
    ")->fetchAll();
    foreach ($autoJobs as $job) {
        $autoJobEvent = !empty($job['event_id']) ? Event::getById((int)$job['event_id'], false) : null;
        // MAIL_BATCH_SIZE is a trusted integer constant – safe to interpolate as LIMIT
        $pendingStmt = $db->prepare("
            SELECT * FROM mass_mail_recipients WHERE job_id = ? AND status = 'pending'
            LIMIT " . MAIL_BATCH_SIZE
        );
        $pendingStmt->execute([$job['id']]);
        $batch = $pendingStmt->fetchAll();
        $sent = $failed = 0;
        $updStatus = $db->prepare("
            UPDATE mass_mail_recipients SET status = ?, processed_at = NOW() WHERE id = ?
        ");
        foreach ($batch as $r) {
            if (sendPersonalisedMail($r, $job['subject'], $job['body_template'], $job['event_name'] ?? '', $autoJobEvent)) {
                $sent++;
                $updStatus->execute(['sent', $r['id']]);
            } else {
                $failed++;
                $updStatus->execute(['failed', $r['id']]);
            }
        }
        $db->prepare("
            UPDATE mass_mail_jobs SET sent_count = sent_count + ?, failed_count = failed_count + ?,
                next_run_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
            WHERE id = ?
        ")->execute([$sent, $failed, $job['id']]);
        $cntStmt2 = $db->prepare("
            SELECT COUNT(*) FROM mass_mail_recipients WHERE job_id = ? AND status = 'pending'
        ");
        $cntStmt2->execute([$job['id']]);
        $remaining = (int)$cntStmt2->fetchColumn();
        if ($remaining === 0) {
            $db->prepare("UPDATE mass_mail_jobs SET status = 'completed' WHERE id = ?")->execute([$job['id']]);
        }
    }
} catch (Exception $e) {
    error_log("Auto-process mail queue error: " . $e->getMessage());
}

$users = User::getAll();

// Load pending mail jobs for the "Continue" button
$pendingJobs = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->query("
        SELECT j.*, 
            (SELECT COUNT(*) FROM mass_mail_recipients WHERE job_id = j.id AND status = 'pending') AS pending_count
        FROM mass_mail_jobs j
        WHERE j.status = 'paused'
        ORDER BY j.created_at DESC
    ");
    $pendingJobs = $stmt->fetchAll();
} catch (Exception $e) {
    // Table may not exist yet; ignore
}

$title = 'Masseneinladungen - IBC Intranet';
ob_start();
?>

<!-- Header -->
<div class="mb-8">
    <div class="flex items-center mb-4">
        <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-4">
            <i class="fas fa-mail-bulk text-indigo-600 dark:text-indigo-400 text-2xl"></i>
        </div>
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Masseneinladungen</h1>
            <p class="text-gray-600 dark:text-gray-400">E-Mail-Einladungen mit Vorlagen an mehrere Empfänger senden</p>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 dark:bg-green-900/40 border border-green-400 dark:border-green-700 text-green-800 dark:text-green-300 rounded-xl flex items-center">
    <i class="fas fa-check-circle text-xl mr-3"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 dark:bg-red-900/40 border border-red-400 dark:border-red-700 text-red-800 dark:text-red-300 rounded-xl flex items-center">
    <i class="fas fa-exclamation-circle text-xl mr-3"></i>
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (!empty($pendingJobs)): ?>
<!-- Pending Mail Jobs -->
<div class="mb-6 card p-6 border-l-4 border-yellow-400 bg-yellow-50 dark:bg-yellow-900/20">
    <h2 class="text-lg font-bold text-yellow-800 dark:text-yellow-300 mb-3">
        <i class="fas fa-hourglass-half mr-2"></i>Offene Warteschlangen
    </h2>
    <?php foreach ($pendingJobs as $job): ?>
    <div class="flex items-center justify-between mb-2">
        <div class="text-sm text-yellow-800 dark:text-yellow-300">
            <strong><?php echo htmlspecialchars($job['subject']); ?></strong>
            – <?php echo (int)$job['pending_count']; ?> ausstehend
            / <?php echo (int)$job['sent_count']; ?> versendet
            (gesamt: <?php echo (int)$job['total_recipients']; ?>)
            <br>
            <span class="text-xs text-yellow-600 dark:text-yellow-400">
                Automatisch fortgesetzt um: <?php echo htmlspecialchars($job['next_run_at'] ?? '–'); ?>
            </span>
        </div>
        <form method="POST" class="ml-4">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="continue_queue" value="1">
            <input type="hidden" name="job_id" value="<?php echo (int)$job['id']; ?>">
            <button type="submit"
                class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg text-sm font-semibold transition-colors">
                <i class="fas fa-play mr-1"></i>Jetzt fortsetzen
            </button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
    <input type="hidden" name="send_bulk_invite" value="1">

    <!-- Template Selection -->
    <div class="card p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-file-alt text-indigo-500 mr-2"></i>
            E-Mail-Vorlage
        </h2>

        <?php if (empty($templateFiles)): ?>
        <div class="p-6 text-center bg-gray-50 dark:bg-gray-800 rounded-xl border border-dashed border-gray-300 dark:border-gray-600">
            <i class="fas fa-folder-open text-4xl text-gray-400 dark:text-gray-500 mb-3"></i>
            <p class="text-gray-600 dark:text-gray-400 font-medium">Keine Vorlagen gefunden</p>
            <p class="text-sm text-gray-500 dark:text-gray-500 mt-1">
                Lege JSON-Dateien im Ordner <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">assets/mail_vorlage/</code> ab, um Vorlagen zu nutzen.
            </p>
        </div>
        <?php else: ?>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Vorlage auswählen
            </label>
            <select
                id="eventTemplateSelect"
                class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
                <option value="">— Vorlage wählen —</option>
                <?php foreach ($templateFiles as $tpl): ?>
                <option value="<?php echo htmlspecialchars($tpl); ?>">
                    <?php echo htmlspecialchars($tpl); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p id="templateLoadStatus" class="hidden mt-2 text-sm text-gray-500 dark:text-gray-400">
                <i class="fas fa-spinner fa-spin mr-1"></i>Vorlage wird geladen…
            </p>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 gap-4">
            <div>
                <label for="bulkSubject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Betreff <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    id="bulkSubject"
                    name="bulk_subject"
                    required
                    class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Betreff der E-Mail"
                    value="<?php echo htmlspecialchars($_POST['bulk_subject'] ?? ''); ?>"
                >
            </div>
            <div>
                <label for="eventId" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Event (für Datum, Ort und Anmeldelink)
                </label>
                <select
                    id="eventId"
                    name="event_id"
                    class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="">— Kein Event —</option>
                    <?php foreach ($allEvents as $ev):
                        $evTitle = htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8');
                        $evDate  = htmlspecialchars(date('d.m.Y', strtotime($ev['start_time'])), ENT_QUOTES, 'UTF-8');
                        $selected = (isset($_POST['event_id']) && (int)$_POST['event_id'] === (int)$ev['id']) ? ' selected' : '';
                    ?>
                    <option value="<?php echo (int)$ev['id']; ?>"<?php echo $selected; ?>>
                        <?php echo $evTitle; ?> (<?php echo $evDate; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="bulkBody" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Nachrichtentext <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="bulkBody"
                    name="bulk_body"
                    rows="8"
                    required
                    class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Hier den Einladungstext eingeben…"
                ><?php echo htmlspecialchars($_POST['bulk_body'] ?? ''); ?></textarea>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle mr-1"></i>
                    Verfügbare Platzhalter:
                    <code>{Anrede}</code> (z.B. „Hallo Max"),
                    <code>{Vorname}</code>,
                    <code>{Nachname}</code>,
                    <code>{Event_Name}</code>,
                    <code>{eventDateDay}</code>,
                    <code>{eventDateDayOf}</code>,
                    <code>{eventDateMonth}</code>,
                    <code>{EventDateHour}</code>,
                    <code>{location}</code>,
                    <code>{trainingLink}</code>.
                    Die letzten sechs werden automatisch aus dem gewählten Event befüllt.
                </p>
            </div>
        </div>
    </div>

    <!-- Recipients -->
    <div class="card p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-users text-indigo-500 mr-2"></i>
            Empfänger
        </h2>

        <!-- Tab Switcher -->
        <div class="flex rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 mb-6 w-fit">
            <button
                type="button"
                id="recipientTabUsers"
                class="px-6 py-2 font-semibold text-sm transition-colors bg-indigo-600 text-white"
            >
                <i class="fas fa-user-check mr-2"></i>Systembenutzer
            </button>
            <button
                type="button"
                id="recipientTabCsv"
                class="px-6 py-2 font-semibold text-sm transition-colors bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-500"
            >
                <i class="fas fa-file-csv mr-2"></i>CSV-Upload
            </button>
        </div>

        <!-- Panel: System users -->
        <div id="recipientPanelUsers">
            <!-- Group quick-select -->
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Gruppe:</span>
                <button type="button" id="selectGroupAlumni"
                    class="px-3 py-1 text-xs bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-lg hover:bg-purple-200 dark:hover:bg-purple-900/60 transition-colors font-medium border border-purple-200 dark:border-purple-700">
                    <i class="fas fa-graduation-cap mr-1"></i>Alumni
                </button>
                <button type="button" id="selectGroupMitglieder"
                    class="px-3 py-1 text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg hover:bg-green-200 dark:hover:bg-green-900/60 transition-colors font-medium border border-green-200 dark:border-green-700">
                    <i class="fas fa-users mr-1"></i>Mitglieder
                </button>
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input
                        type="text"
                        id="bulkUserSearch"
                        placeholder="Benutzer suchen…"
                        class="w-full pl-9 pr-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                    >
                </div>
                <button type="button" id="selectAllUsers"
                    class="px-4 py-2 text-sm bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded-xl hover:bg-indigo-200 dark:hover:bg-indigo-900/60 transition-colors font-medium">
                    Alle
                </button>
                <button type="button" id="deselectAllUsers"
                    class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors font-medium">
                    Keine
                </button>
                <span id="bulkSelectedCount" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">0 ausgewählt</span>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-72 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <?php foreach ($users as $u): ?>
                <?php
                    $uName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $uEmail = $u['email'] ?? '';
                    $uRole  = $u['role'] ?? '';
                    // Prefer entra_roles (human-readable); fall back to azure_roles for legacy accounts
                    $uEntraRoles = [];
                    if (!empty($u['entra_roles'])) {
                        $decoded = json_decode($u['entra_roles'], true);
                        if (is_array($decoded)) $uEntraRoles = $decoded;
                    }
                    if (empty($uEntraRoles) && !empty($u['azure_roles'])) {
                        $decoded = json_decode($u['azure_roles'], true);
                        if (is_array($decoded)) $uEntraRoles = $decoded;
                    }
                    $uEntraRolesJson = htmlspecialchars(json_encode($uEntraRoles), ENT_QUOTES, 'UTF-8');
                ?>
                <label class="bulk-user-row flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors"
                       data-email="<?php echo htmlspecialchars(strtolower($uEmail)); ?>"
                       data-name="<?php echo htmlspecialchars(strtolower($uName)); ?>"
                       data-role="<?php echo htmlspecialchars($uRole); ?>"
                       data-entra-roles="<?php echo $uEntraRolesJson; ?>">
                    <input type="checkbox" name="bulk_user_ids[]" value="<?php echo (int)$u['id']; ?>"
                           class="bulk-user-checkbox w-4 h-4 text-indigo-600 rounded border-gray-300 dark:border-gray-600 focus:ring-indigo-500">
                    <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-semibold text-xs flex-shrink-0">
                        <?php echo htmlspecialchars(strtoupper(substr($uName ?: $uEmail, 0, 2))); ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <?php if ($uName): ?>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"><?php echo htmlspecialchars($uName); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($uEmail); ?></p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Panel: CSV upload -->
        <div id="recipientPanelCsv" class="hidden">
            <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600">
                <label for="bulkCsvInput" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    CSV-Datei hochladen
                </label>
                <input
                    type="file"
                    id="bulkCsvInput"
                    name="bulk_csv"
                    accept=".csv,text/csv"
                    disabled
                    class="w-full text-sm text-gray-700 dark:text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-indigo-100 file:text-indigo-700 hover:file:bg-indigo-200 dark:file:bg-indigo-900/40 dark:file:text-indigo-300 cursor-pointer"
                >
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Format: E-Mail, Vorname, Nachname (kommagetrennt, eine Zeile pro Person).
                    Beispiel: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">max@example.com,Max,Mustermann</code>
                </p>
            </div>
        </div>
    </div>

    <!-- Submit -->
    <div class="flex items-center gap-4">
        <button
            type="submit"
            class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white rounded-xl font-semibold hover:from-indigo-700 hover:to-blue-700 transition-all shadow-lg hover:shadow-xl"
        >
            <i class="fas fa-paper-plane mr-2"></i>
            Einladungen versenden
        </button>
        <a href="<?php echo asset('pages/admin/index.php'); ?>"
           class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all no-underline">
            Abbrechen
        </a>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const templateSelect    = document.getElementById('eventTemplateSelect');
    const loadStatus        = document.getElementById('templateLoadStatus');
    const subjectInput      = document.getElementById('bulkSubject');
    const bodyTextarea      = document.getElementById('bulkBody');
    const userSearchInput   = document.getElementById('bulkUserSearch');
    const selectAllBtn      = document.getElementById('selectAllUsers');
    const deselectAllBtn    = document.getElementById('deselectAllUsers');
    const selectedCount     = document.getElementById('bulkSelectedCount');
    const recipientTabUsers = document.getElementById('recipientTabUsers');
    const recipientTabCsv   = document.getElementById('recipientTabCsv');
    const panelUsers        = document.getElementById('recipientPanelUsers');
    const panelCsv          = document.getElementById('recipientPanelCsv');
    const csvInput          = document.getElementById('bulkCsvInput');
    const selectGroupAlumniBtn     = document.getElementById('selectGroupAlumni');
    const selectGroupMitgliederBtn = document.getElementById('selectGroupMitglieder');

    const ALUMNI_ENTRA_ROLES = <?php echo json_encode(ALUMNI_ENTRA_ROLES); ?>;

    // Load template via AJAX
    if (templateSelect) {
        templateSelect.addEventListener('change', function () {
            const tpl = this.value;
            if (!tpl) return;

            if (loadStatus) loadStatus.classList.remove('hidden');

            fetch('<?php echo asset('api/get_mail_template.php'); ?>?template=' + encodeURIComponent(tpl))
                .then(r => r.json())
                .then(data => {
                    if (loadStatus) loadStatus.classList.add('hidden');
                    if (data.error) {
                        alert('Fehler beim Laden der Vorlage: ' + data.error);
                        return;
                    }
                    if (subjectInput)  subjectInput.value  = data.subject || '';
                    if (bodyTextarea)  bodyTextarea.value  = data.content || '';
                })
                .catch(() => {
                    if (loadStatus) loadStatus.classList.add('hidden');
                    alert('Netzwerkfehler beim Laden der Vorlage.');
                });
        });
    }

    // Recipient tab switching
    function activateRecipientTab(tab) {
        if (tab === 'users') {
            recipientTabUsers.classList.remove('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            recipientTabUsers.classList.add('bg-indigo-600', 'text-white');
            recipientTabCsv.classList.remove('bg-indigo-600', 'text-white');
            recipientTabCsv.classList.add('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            panelUsers.classList.remove('hidden');
            panelCsv.classList.add('hidden');
            if (csvInput) csvInput.disabled = true;
        } else {
            recipientTabCsv.classList.remove('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            recipientTabCsv.classList.add('bg-indigo-600', 'text-white');
            recipientTabUsers.classList.remove('bg-indigo-600', 'text-white');
            recipientTabUsers.classList.add('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            panelCsv.classList.remove('hidden');
            panelUsers.classList.add('hidden');
            if (csvInput) csvInput.disabled = false;
        }
    }

    if (recipientTabUsers) recipientTabUsers.addEventListener('click', () => activateRecipientTab('users'));
    if (recipientTabCsv)   recipientTabCsv.addEventListener('click',   () => activateRecipientTab('csv'));

    // Update selected count
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.bulk-user-checkbox:checked').length;
        if (selectedCount) selectedCount.textContent = checked + ' ausgewählt';
    }

    // Select users by Entra role group.
    // When isAlumni=true  → select users who have at least one Entra role from ALUMNI_ENTRA_ROLES.
    // When isAlumni=false → select users who have NO alumni Entra roles (all other members).
    function selectByEntraGroup(isAlumni) {
        document.querySelectorAll('.bulk-user-row').forEach(row => {
            const raw = row.getAttribute('data-entra-roles') || '[]';
            let entraRoles = [];
            try { entraRoles = JSON.parse(raw); } catch (e) { entraRoles = []; }
            const hasAlumniRole = entraRoles.some(r => ALUMNI_ENTRA_ROLES.includes(r));
            const match = isAlumni ? hasAlumniRole : !hasAlumniRole;
            const cb = row.querySelector('.bulk-user-checkbox');
            if (cb && match) {
                cb.checked = true;
            }
        });
        updateSelectedCount();
    }

    if (selectGroupAlumniBtn) {
        selectGroupAlumniBtn.addEventListener('click', () => selectByEntraGroup(true));
    }
    if (selectGroupMitgliederBtn) {
        selectGroupMitgliederBtn.addEventListener('click', () => selectByEntraGroup(false));
    }

    // Select/deselect all visible users
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            document.querySelectorAll('.bulk-user-row').forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.bulk-user-checkbox');
                    if (cb) cb.checked = true;
                }
            });
            updateSelectedCount();
        });
    }

    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function () {
            document.querySelectorAll('.bulk-user-checkbox').forEach(cb => { cb.checked = false; });
            updateSelectedCount();
        });
    }

    // Search filter
    if (userSearchInput) {
        userSearchInput.addEventListener('input', function () {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.bulk-user-row').forEach(row => {
                const email = row.getAttribute('data-email') || '';
                const name  = row.getAttribute('data-name')  || '';
                row.style.display = (email.includes(term) || name.includes(term)) ? '' : 'none';
            });
        });
    }

    // Track checkbox changes
    document.querySelectorAll('.bulk-user-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    updateSelectedCount();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
