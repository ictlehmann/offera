<?php
/**
 * Email Change Confirmation API
 * Validates token and updates user email address
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/User.php';

// Start session with secure parameters
init_session();

$error = '';
$success = '';

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    $error = 'Ungültiger Bestätigungslink';
} else {
    $token = $_GET['token'];
    
    try {
        // Confirm email change
        if (User::confirmEmailChange($token)) {
            // Update session if this is the current user
            if (Auth::check()) {
                $user = Auth::user();
                // Reload user to get updated email
                $updatedUser = User::getById($user['id']);
                if ($updatedUser) {
                    $_SESSION['user_email'] = $updatedUser['email'];
                }
            }
            
            $success = 'E-Mail-Adresse erfolgreich aktualisiert';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Redirect to settings page with message using BASE_URL for security
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$redirectUrl = $baseUrl . '/pages/auth/settings.php';
if (!empty($success)) {
    $_SESSION['success_message'] = $success;
} elseif (!empty($error)) {
    $_SESSION['error_message'] = $error;
}

header('Location: ' . $redirectUrl);
exit;
