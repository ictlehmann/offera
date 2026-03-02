<?php
/**
 * Complete Onboarding API
 * Sets has_seen_onboarding flag to 1 for the authenticated user
 */

// Set JSON response header
header('Content-Type: application/json');

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

try {
    // Check authentication
    if (!Auth::check()) {
        echo json_encode([
            'success' => false,
            'message' => 'Nicht authentifiziert'
        ]);
        exit;
    }

    // Check if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Anfrage'
        ]);
        exit;
    }

    // Read JSON body for CSRF token
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    CSRFHandler::verifyToken($input['csrf_token'] ?? '');

    // Get current user ID from session
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Sitzung ungültig'
        ]);
        exit;
    }

    $userId = $_SESSION['user_id'];

    // Update has_seen_onboarding to 1
    $db = Database::getUserDB();
    $stmt = $db->prepare("UPDATE users SET has_seen_onboarding = 1 WHERE id = ?");

    if ($stmt->execute([$userId])) {
        echo json_encode([
            'success' => true,
            'message' => 'Onboarding abgeschlossen'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Aktualisieren'
        ]);
    }
} catch (Exception $e) {
    error_log('Error in complete_onboarding.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server-Fehler'
    ]);
}
