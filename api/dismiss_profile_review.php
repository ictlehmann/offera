<?php
/**
 * Dismiss Profile Review Prompt API
 * Sets prompt_profile_review flag to 0 for the authenticated user
 */

// Set JSON response header
header('Content-Type: application/json');

// Disable error output in body
ini_set('display_errors', 0);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';

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

    // Get current user ID from session
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Sitzung ungültig'
        ]);
        exit;
    }
    
    $userId = $_SESSION['user_id'];

    // Update prompt_profile_review to 0
    $db = Database::getUserDB();
    $stmt = $db->prepare("UPDATE users SET prompt_profile_review = 0 WHERE id = ?");
    
    if ($stmt->execute([$userId])) {
        echo json_encode([
            'success' => true,
            'message' => 'Prompt erfolgreich geschlossen'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Aktualisieren'
        ]);
    }
} catch (Exception $e) {
    // Log the full error details
    error_log('Error in dismiss_profile_review.php: ' . $e->getMessage());
    
    // Return generic JSON error response
    echo json_encode([
        'success' => false,
        'message' => 'Server-Fehler'
    ]);
}
