<?php
/**
 * Notifications API
 * GET  – returns latest notifications and unread count
 * POST – marks notification(s) as read
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/models/Notification.php';

try {
    if (!Auth::check()) {
        echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $notifications  = Notification::getLatest($userId, 10);
        $unreadCount    = Notification::getUnreadCount($userId);

        echo json_encode([
            'success'       => true,
            'unread_count'  => $unreadCount,
            'notifications' => $notifications,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        $action = $input['action'] ?? '';

        if ($action === 'mark_all_read') {
            Notification::markAllAsRead($userId);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'mark_read' && isset($input['id'])) {
            $id = (int) $input['id'];
            Notification::markAsRead($id, $userId);
            echo json_encode(['success' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);

} catch (Exception $e) {
    error_log('Error in notifications.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server-Fehler']);
}
