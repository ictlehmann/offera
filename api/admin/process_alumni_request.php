<?php
/**
 * API: Process Alumni Access Request (Admin)
 *
 * Accepts or rejects a pending alumni access request. On approval the handler:
 *  1. Optionally disables the old Entra account (if old_email is set)
 *  2. Checks whether the new e-mail already has an Entra account
 *     – YES → reuse the existing account and ensure it is in the alumni distribution list
 *     – NO  → create a B2B Guest invitation and add the new account to the list
 *  3. Sets the DB status to 'approved'
 *  4. Sends a confirmation e-mail to the applicant via MailService
 *
 * Required permissions: alumni_finanz, alumni_vorstand, vorstand_finanzen,
 *                       vorstand_extern, vorstand_intern
 *
 * Required app permissions (Microsoft Graph):
 *   User.Invite.All, User.ReadWrite.All, GroupMember.ReadWrite.All
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// ── Authentication ─────────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// ── Role check ─────────────────────────────────────────────────────────────────
// Only board members and alumni board/auditor roles may process requests
$allowedRoles = [
    Auth::ROLE_BOARD_FINANCE,
    Auth::ROLE_BOARD_INTERNAL,
    Auth::ROLE_BOARD_EXTERNAL,
    Auth::ROLE_ALUMNI_BOARD,
    Auth::ROLE_ALUMNI_AUDITOR,
];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

// ── HTTP method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────────────────────
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// ── Input validation ───────────────────────────────────────────────────────────
$requestId = intval($_POST['request_id'] ?? 0);
$action    = $_POST['action'] ?? '';

if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage-ID']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
    exit;
}

// ── Load request from DB ───────────────────────────────────────────────────────
$request = AlumniAccessRequest::getById($requestId);
if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Anfrage nicht gefunden']);
    exit;
}

if ($request['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Diese Anfrage wurde bereits bearbeitet']);
    exit;
}

$processedBy = (int) ($_SESSION['user_id'] ?? 0);

// ── Rejection path ─────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $ok = AlumniAccessRequest::updateStatus($requestId, 'rejected', $processedBy);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Anfrage abgelehnt']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Ablehnen']);
    }
    exit;
}

// ── Alumni distribution-list group ID ─────────────────────────────────────────
// The Object ID of the "Verteiler Alumni" group in Microsoft Entra.
// Can be overridden by defining ALUMNI_DISTRIBUTION_GROUP_ID in config.php / .env.
if (!defined('ALUMNI_DISTRIBUTION_GROUP_ID')) {
    define('ALUMNI_DISTRIBUTION_GROUP_ID', '9e927fce-9029-4564-b2b6-e52c9f1588dd');
}

$firstName = $request['first_name'];
$lastName  = $request['last_name'];
$newEmail  = $request['new_email'];
$oldEmail  = $request['old_email'] ?? null;

try {
    $graphService = new MicrosoftGraphService();

    // Step 1 – Disable the old Entra account if one was supplied ─────────────
    if (!empty($oldEmail)) {
        try {
            $graphService->disableUserByEmail($oldEmail);
        } catch (Exception $disableEx) {
            // Log but do not abort: the old account may have already been removed
            // or may live in a different tenant that is not under our control.
            error_log(
                'process_alumni_request(admin): could not disable old account '
                . $oldEmail . ' for request #' . $requestId
                . ': ' . $disableEx->getMessage()
            );
        }
    }

    // Step 2 – Resolve or create the Entra account for the new e-mail ────────
    $existingUser = $graphService->getUserByEmail($newEmail);

    if ($existingUser !== null) {
        // Account already exists – reuse its Object ID
        $entraUserId = $existingUser['id'];
    } else {
        // No account yet – create a B2B Guest invitation
        $entraUserId = $graphService->inviteGuestUser($newEmail, $firstName, $lastName);
    }

    // Step 3 – Add the account to the alumni distribution list ───────────────
    $graphService->addUserToGroup($entraUserId, ALUMNI_DISTRIBUTION_GROUP_ID);

} catch (Exception $e) {
    // Do not reveal internal details in the response
    error_log(
        'process_alumni_request(admin): Entra operation failed for request #'
        . $requestId . ': ' . $e->getMessage()
    );
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler bei der Entra-Verarbeitung. Bitte prüfe die Logs.',
    ]);
    exit;
}

// Step 4 – Update DB status ───────────────────────────────────────────────────
$ok = AlumniAccessRequest::updateStatus($requestId, 'approved', $processedBy);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Akzeptieren']);
    exit;
}

// Step 5 – Send confirmation e-mail to the applicant ─────────────────────────
try {
    $subject = 'Willkommen im IBC Alumni-Verteiler';

    $bodyContent = '
        <p class="email-text">Hallo ' . htmlspecialchars($firstName) . ',</p>
        <p class="email-text">
            du wurdest erfolgreich in den Verteiler aufgenommen und dein Microsoft Entra
            Gast-Zugang ist bereit. Du kannst dich nun einloggen.
        </p>
        <p class="email-text">
            Falls du Fragen hast oder Hilfe benötigst, melde dich gerne bei uns.
        </p>';

    $intranetUrl  = defined('BASE_URL') ? BASE_URL : '';
    $callToAction = '<a href="' . htmlspecialchars($intranetUrl) . '" class="button">Zum Intranet</a>';

    $htmlBody = MailService::getTemplate(
        'Willkommen im Alumni-Netzwerk',
        $bodyContent,
        $callToAction
    );

    MailService::sendEmail($newEmail, $subject, $htmlBody);
} catch (Exception $mailEx) {
    // Mail failure must not roll back the approval
    error_log(
        'process_alumni_request(admin): confirmation mail failed for request #'
        . $requestId . ': ' . $mailEx->getMessage()
    );
}

echo json_encode(['success' => true, 'message' => 'Anfrage akzeptiert und Gast-Zugang eingerichtet']);
