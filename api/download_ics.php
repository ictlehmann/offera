<?php
/**
 * Download ICS file for event
 * Generates and downloads an iCal (.ics) file for a specific event
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Event.php';
require_once __DIR__ . '/../src/CalendarService.php';

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo 'Nicht authentifiziert';
    exit;
}

// Get event ID
$eventId = $_GET['event_id'] ?? null;
if (!$eventId || !is_numeric($eventId) || $eventId <= 0) {
    http_response_code(400);
    echo 'Ungültige Event ID';
    exit;
}

// Get event details
$event = Event::getById($eventId, true);
if (!$event) {
    http_response_code(404);
    echo 'Event nicht gefunden';
    exit;
}

// Check if user has permission to view this event
$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'member';
$allowedRoles = $event['allowed_roles'] ?? [];
if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    echo 'Keine Berechtigung';
    exit;
}

// Generate ICS content
$icsContent = CalendarService::generateIcsFile($event);

// Generate filename - sanitize to prevent header injection
$safeEventId = preg_replace('/[^0-9]/', '', $eventId);
$safeDate = date('Ymd');
$filename = 'event_' . $safeEventId . '_' . $safeDate . '.ics';
// RFC 6266 compliant filename encoding
$filename = str_replace('"', '', $filename); // Remove any quotes

// Set headers for file download
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($icsContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output ICS content
echo $icsContent;
exit;
