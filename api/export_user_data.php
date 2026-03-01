<?php
/**
 * API: GDPR User Data Export
 * Collects all data linked to the authenticated user and offers it as a JSON download.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only allow POST requests with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

if (!Auth::check()) {
    header('Location: ../pages/auth/login.php');
    exit;
}

if (!CSRFHandler::verifyToken($_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];

// ── 1. Profile data ──────────────────────────────────────────────────────────
$sensitiveFields = ['password', 'tfa_secret', 'current_session_id'];
$profile = array_diff_key($user, array_flip($sensitiveFields));

// ── 2. Event sign-ups (slot-based) ───────────────────────────────────────────
$eventSignups = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT es.id, es.event_id, e.title AS event_title, e.start_date,
                es.slot_id, es.helper_type_id, es.role_id, es.created_at
         FROM event_signups es
         LEFT JOIN events e ON e.id = es.event_id
         WHERE es.user_id = ?
         ORDER BY es.created_at DESC"
    );
    $stmt->execute([$userId]);
    $eventSignups = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: event_signups query failed: " . $e->getMessage());
}

// ── 3. Simple event registrations ────────────────────────────────────────────
$eventRegistrations = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT er.id, er.event_id, e.title AS event_title, e.start_date,
                er.status, er.registered_at
         FROM event_registrations er
         LEFT JOIN events e ON e.id = er.event_id
         WHERE er.user_id = ?
         ORDER BY er.registered_at DESC"
    );
    $stmt->execute([$userId]);
    $eventRegistrations = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: event_registrations query failed: " . $e->getMessage());
}

// ── 4. Inventory rentals ─────────────────────────────────────────────────────
$rentals = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT ir.id, ir.item_id, ii.name AS item_name,
                ir.rental_start, ir.rental_end, ir.returned_at,
                ir.status, ir.notes, ir.created_at
         FROM inventory_rentals ir
         LEFT JOIN inventory_items ii ON ii.id = ir.item_id
         WHERE ir.user_id = ?
         ORDER BY ir.created_at DESC"
    );
    $stmt->execute([$userId]);
    $rentals = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: inventory_rentals query failed: " . $e->getMessage());
}

// ── 5. Shop orders ───────────────────────────────────────────────────────────
$shopOrders = [];
try {
    $shopDb = Database::getShopDB();
    $stmt = $shopDb->prepare(
        "SELECT so.id, so.status, so.total_amount_cents, so.currency,
                so.shipping_name, so.shipping_address, so.shipping_city,
                so.shipping_zip, so.shipping_country, so.created_at,
                GROUP_CONCAT(
                    CONCAT(soi.quantity, 'x ', soi.product_name,
                           IF(soi.variant_label IS NOT NULL AND soi.variant_label != '',
                              CONCAT(' (', soi.variant_label, ')'), '')
                    )
                    ORDER BY soi.id SEPARATOR ', '
                ) AS items
         FROM shop_orders so
         LEFT JOIN shop_order_items soi ON soi.order_id = so.id
         WHERE so.user_id = ?
         GROUP BY so.id
         ORDER BY so.created_at DESC"
    );
    $stmt->execute([$userId]);
    $shopOrders = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: shop_orders query failed: " . $e->getMessage());
}

// ── 6. Project applications ──────────────────────────────────────────────────
$projectApplications = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT pa.id, pa.project_id, p.title AS project_title,
                pa.status, pa.message, pa.created_at
         FROM project_applications pa
         LEFT JOIN projects p ON p.id = pa.project_id
         WHERE pa.user_id = ?
         ORDER BY pa.created_at DESC"
    );
    $stmt->execute([$userId]);
    $projectApplications = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: project_applications query failed: " . $e->getMessage());
}

// ── Assemble export payload ───────────────────────────────────────────────────
$exportTimestamp = gmdate('c'); // UTC ISO-8601 for consistent GDPR records

$export = [
    'export_generated_at'  => $exportTimestamp,
    'profile'              => $profile,
    'event_signups'        => $eventSignups,
    'event_registrations'  => $eventRegistrations,
    'inventory_rentals'    => $rentals,
    'shop_orders'          => $shopOrders,
    'project_applications' => $projectApplications,
];

// Filename uses only server-generated values; escape to prevent header injection
$filename = 'meine_daten_' . gmdate('Y-m-d_H-i-s') . '.json';
$safeFilename = str_replace(['"', '\\', "\r", "\n"], '', $filename);

$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

echo $json;
exit;
