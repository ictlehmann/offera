<?php
/**
 * Microsoft Login Start
 * Initiates the Microsoft Entra ID OAuth login flow
 */

// Load configuration and helpers first (no Composer required)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers/AuthHandler.php';

// Start session
AuthHandler::startSession();

// Verify Composer dependencies are installed before proceeding
$_autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($_autoloadPath)) {
    error_log('Microsoft login failed: vendor/autoload.php not found. Run "composer install" on the server.');
    $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/auth/login.php' : '/pages/auth/login.php';
    header('Location: ' . $loginUrl . '?error=' . urlencode('Microsoft-Login derzeit nicht verfügbar. Bitte Administrator kontaktieren.'));
    exit;
}
require_once $_autoloadPath;
unset($_autoloadPath);

// Initiate Microsoft login
try {
    AuthHandler::initiateMicrosoftLogin();
} catch (Exception $e) {
    // Log the full error details server-side
    error_log("Microsoft login initiation error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Redirect to login page with a generic error message
    $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/auth/login.php' : '/pages/auth/login.php';
    $errorMessage = urlencode('Microsoft Login konnte nicht gestartet werden. Bitte kontaktieren Sie den Administrator.');
    header('Location: ' . $loginUrl . '?error=' . $errorMessage);
    exit;
}
