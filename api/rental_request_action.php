<?php
/**
 * API: Rental Request Actions
 *
 * Handles approve, reject, and verify_return actions for inventory_requests.
 * Accessible only to board members (board_finance, board_internal, board_external).
 *
 * action: approve       – Sets request status to 'approved'
 * action: reject        – Sets request status to 'rejected'
 * action: verify_return – Sets request status to 'returned' with condition and optional notes
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/services/EasyVereinInventory.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

if (!Auth::isBoard()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$action    = $input['action']     ?? '';
$requestId = (int)($input['request_id'] ?? 0);

if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage-ID']);
    exit;
}

try {
    $db = Database::getContentDB();

    if ($action === 'approve') {
        // Fetch the pending request to get the applicant's user_id and the requested quantity
        $stmt = $db->prepare(
            "SELECT user_id, quantity FROM inventory_requests WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Anfrage nicht gefunden oder nicht im Status "ausstehend"']);
            exit;
        }

        // Fetch applicant name and email from the user DB
        $userDb   = Database::getUserDB();
        $userStmt = $userDb->prepare(
            "SELECT first_name, last_name, email FROM users WHERE id = ?"
        );
        $userStmt->execute([$request['user_id']]);
        $applicant = $userStmt->fetch();

        if (!$applicant) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Antragsteller nicht gefunden']);
            exit;
        }

        $userName = trim(($applicant['first_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
        if ($userName === '') {
            $userName = $applicant['email'] ?? 'Unbekannt';
        }
        $userEmail = $applicant['email'] ?? '';

        // Try to enrich name and email from Microsoft Entra ID (primary source)
        try {
            $graphService = new MicrosoftGraphService();
            $entraUsers   = $graphService->searchUsers($applicant['email']);
            $matched      = false;
            foreach ($entraUsers as $eu) {
                if (strcasecmp($eu['mail'] ?? '', $applicant['email']) === 0) {
                    $userName  = $eu['displayName'] ?? $userName;
                    $userEmail = $eu['mail'] ?? $userEmail;
                    $matched   = true;
                    break;
                }
            }
            if (!$matched) {
                error_log('rental_request_action: Entra user not found for email ' . $applicant['email'] . ' – using local fallback');
            }
        } catch (Exception $entraEx) {
            error_log('rental_request_action: Entra lookup failed – ' . $entraEx->getMessage() . ' – using local fallback');
        }

        $evi = new EasyVereinInventory();
        $evi->approveRental($requestId, $userName, $userEmail, (int)$request['quantity']);

        echo json_encode(['success' => true, 'message' => 'Anfrage genehmigt']);
        exit;
    }

    if ($action === 'reject') {
        $stmt = $db->prepare(
            "UPDATE inventory_requests SET status = 'rejected' WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$requestId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Anfrage nicht gefunden oder nicht im Status "ausstehend"']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Anfrage abgelehnt']);
        exit;
    }

    if ($action === 'verify_return') {
        $allowedConditions = ['einwandfrei', 'leichte_gebrauchsspuren', 'beschädigt', 'defekt_verlust'];
        $condition         = $input['condition'] ?? '';
        $notes             = $input['notes']     ?? '';

        if (!in_array($condition, $allowedConditions, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültiger Zustand']);
            exit;
        }

        $admin     = Auth::user();
        $adminName = $admin
            ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            : ($_SESSION['user_email'] ?? 'Unbekannt');
        if ($adminName === '') {
            $adminName = $admin['email'] ?? 'Unbekannt';
        }

        $inventory = new EasyVereinInventory();
        $inventory->verifyReturn($requestId, $adminName, $condition, $notes);

        echo json_encode(['success' => true, 'message' => 'Rückgabe erfolgreich verifiziert']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);

} catch (Exception $e) {
    error_log('rental_request_action: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'API Fehler: ' . $e->getMessage()]);
}
