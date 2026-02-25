<?php
/**
 * API: Submit Invoice
 * Handles invoice submission with file upload
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../src/Database.php';

// Check authentication
if (!Auth::check()) {
    $_SESSION['error_message'] = 'Nicht authentifiziert';
    header('Location: ' . asset('pages/auth/login.php'));
    exit;
}

$user = Auth::user();

// Check if user has permission to submit invoices
// Allowed: board members, alumni_board, alumni_auditor, head (department leaders)
$hasInvoiceSubmitAccess = Auth::isBoard() || Auth::hasRole(['alumni_board', 'alumni_auditor', 'head']);
if (!$hasInvoiceSubmitAccess) {
    $_SESSION['error_message'] = 'Keine Berechtigung';
    header('Location: ' . asset('pages/dashboard/index.php'));
    exit;
}

$userRole = $user['role'] ?? '';

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Methode nicht erlaubt';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Validate required fields
$amount = $_POST['amount'] ?? null;
$description = $_POST['description'] ?? null;
$date = $_POST['date'] ?? null;

if (empty($amount) || empty($description) || empty($date)) {
    $_SESSION['error_message'] = 'Alle Felder sind erforderlich';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Validate amount
if (!is_numeric($amount) || $amount <= 0) {
    $_SESSION['error_message'] = 'Ungültiger Betrag';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Validate file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error_message'] = 'Datei-Upload fehlgeschlagen';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Create invoice using Invoice model
$result = Invoice::create($user['id'], [
    'description' => $description,
    'amount' => $amount
], $_FILES['file']);

if ($result['success']) {
    // Send email notification to board_finance users
    try {
        $userDb = Database::getUserDB();
        $stmt = $userDb->prepare("SELECT email FROM users WHERE role = ?");
        $stmt->execute(['board_finance']);
        $financeUsers = $stmt->fetchAll();
        
        if (!empty($financeUsers)) {
            $uploaderName = !empty($user['firstname']) && !empty($user['lastname'])
                ? $user['firstname'] . ' ' . $user['lastname'] 
                : $user['email'];
            
            $subject = "Neue Rechnung eingereicht von " . $uploaderName;
            
            $body = MailService::getTemplate(
                'Neue Rechnung eingereicht',
                '<p>Eine neue Rechnung wurde zur Genehmigung eingereicht.</p>' .
                '<p><strong>Eingereicht von:</strong> ' . htmlspecialchars($uploaderName) . '</p>' .
                '<p><strong>Beschreibung:</strong> ' . htmlspecialchars($description) . '</p>' .
                '<p><strong>Betrag:</strong> ' . number_format($amount, 2, ',', '.') . ' €</p>' .
                '<p>Bitte prüfen Sie die Rechnung im System.</p>'
            );
            
            foreach ($financeUsers as $financeUser) {
                if (!empty($financeUser['email'])) {
                    MailService::sendEmail($financeUser['email'], $subject, $body);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error sending invoice notification email: " . $e->getMessage());
    }
    
    $_SESSION['success_message'] = 'Rechnung erfolgreich eingereicht';
} else {
    $_SESSION['error_message'] = $result['error'] ?? 'Fehler beim Einreichen der Rechnung';
}

header('Location: ' . asset('pages/invoices/index.php'));
exit;
