<?php
/**
 * API: Shop Product Image Management
 * Handles image reordering (sort_order) and deletion via AJAX.
 * Access: shop manager roles only.
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

if (!Auth::hasRole(Shop::MANAGER_ROLES)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültiges JSON']);
    exit;
}

$action = $input['action'] ?? '';

if ($action === 'reorder') {
    $orders = $input['orders'] ?? [];
    if (!is_array($orders)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Daten']);
        exit;
    }
    foreach ($orders as $item) {
        $id        = (int) ($item['id']         ?? 0);
        $sortOrder = (int) ($item['sort_order'] ?? 0);
        if ($id > 0) {
            Shop::updateImageSortOrder($id, $sortOrder);
        }
    }
    echo json_encode(['success' => true]);

} elseif ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige ID']);
        exit;
    }
    $ok = Shop::deleteProductImage($id);
    echo json_encode(['success' => $ok]);

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
}
