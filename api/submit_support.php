<?php
/**
 * API: Submit Support / Change Request
 * Handles support and change requests submitted via the sidebar modal
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/helpers.php';

AuthHandler::startSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

$requestType = trim($_POST['request_type'] ?? '');
$description = trim($_POST['description'] ?? '');

$allowedTypes = ['bug', '2fa_reset', 'name_email_change', 'other'];
if (empty($requestType) || !in_array($requestType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Art der Anfrage']);
    exit;
}

if (empty($description)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Beschreibung darf nicht leer sein']);
    exit;
}

$typeLabels = [
    'bug'              => 'Bug / Fehler',
    '2fa_reset'        => '2FA zurücksetzen',
    'name_email_change' => 'E-Mail/Name ändern',
    'other'            => 'Sonstiges',
];
$typeLabel = $typeLabels[$requestType] ?? $requestType;

$user = Auth::user();
$userName = '';
if (!empty($user['firstname']) && !empty($user['lastname'])) {
    $userName = $user['firstname'] . ' ' . $user['lastname'];
} elseif (!empty($user['firstname'])) {
    $userName = $user['firstname'];
} else {
    $userName = $user['email'] ?? 'Unbekannt';
}
$userEmail = $user['email'] ?? '';

try {
    // Send notification email to admins
    $db = Database::getUserDB();
    $stmt = $db->query("SELECT email FROM users WHERE role IN ('admin', 'board_internal') AND email IS NOT NULL AND email != ''");
    $adminUsers = $stmt->fetchAll();

    $subject = '[IBC Support] ' . $typeLabel . ' von ' . $userName;
    $body = MailService::getTemplate(
        'Support-Anfrage: ' . $typeLabel,
        '<p>Eine neue Support-Anfrage wurde eingereicht.</p>' .
        '<p><strong>Art der Anfrage:</strong> ' . htmlspecialchars($typeLabel) . '</p>' .
        '<p><strong>Eingereicht von:</strong> ' . htmlspecialchars($userName) . ' (' . htmlspecialchars($userEmail) . ')</p>' .
        '<p><strong>Beschreibung:</strong><br>' . nl2br(htmlspecialchars($description)) . '</p>'
    );

    foreach ($adminUsers as $adminUser) {
        if (!empty($adminUser['email'])) {
            MailService::sendEmail($adminUser['email'], $subject, $body);
        }
    }

    error_log(sprintf(
        'Support request submitted - Type: %s, User: %s (%s)',
        $requestType,
        $userName,
        $userEmail
    ));

    echo json_encode(['success' => true, 'message' => 'Deine Anfrage wurde erfolgreich gesendet. Wir melden uns bald bei dir!']);
} catch (Exception $e) {
    error_log('Error in submit_support.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Anfrage. Bitte versuche es später erneut.']);
}
