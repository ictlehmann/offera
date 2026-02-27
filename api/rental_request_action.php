<?php
/**
 * API: Rental Request Actions
 *
 * Handles approve, reject, and verify_return actions for inventory_requests.
 * Accessible only to board members (board_finance, board_internal, board_external).
 *
 * action: approve              – Sets request status to 'approved'
 * action: reject               – Sets request status to 'rejected'
 * action: verify_return        – Sets inventory_requests status to 'returned'
 * action: verify_rental_return – Deletes the inventory_rentals record, making the item available again
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/services/EasyVereinInventory.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';
require_once __DIR__ . '/../includes/models/Inventory.php';

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
        $notes = $input['notes'] ?? '';

        $admin     = Auth::user();
        $adminName = $admin
            ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            : ($_SESSION['user_email'] ?? 'Unbekannt');
        if ($adminName === '') {
            $adminName = $admin['email'] ?? 'Unbekannt';
        }

        $inventory = new EasyVereinInventory();
        $inventory->verifyReturn($requestId, $adminName, '', $notes);

        // Notify the user that their return has been confirmed
        try {
            $reqStmt = $db->prepare(
                "SELECT user_id, inventory_object_id, quantity FROM inventory_requests WHERE id = ?"
            );
            $reqStmt->execute([$requestId]);
            $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);

            if ($reqData) {
                $userEmail = '';
                try {
                    $userDb = Database::getUserDB();
                    $uStmt  = $userDb->prepare(
                        "SELECT email FROM users WHERE id = ? LIMIT 1"
                    );
                    $uStmt->execute([(int)$reqData['user_id']]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($uRow) {
                        $userEmail = $uRow['email'] ?? '';
                    }
                } catch (Exception $userEx) {
                    // Continue without user email
                }

                if ($userEmail !== '') {
                    $itemName = '#' . $reqData['inventory_object_id'];
                    try {
                        $item = Inventory::getById($reqData['inventory_object_id']);
                        if ($item) {
                            $itemName = $item['name'] ?? $itemName;
                        }
                    } catch (Exception $itemEx) {
                        // Use fallback item name
                    }

                    $safeItemName = str_replace(["\r", "\n"], '', $itemName);
                    $emailBody    = MailService::getTemplate(
                        'Rückgabe bestätigt',
                        '<p class="email-text">Deine Rückgabe wurde vom Vorstand bestätigt. Vielen Dank!</p>
                        <table class="info-table">
                            <tr><td>Artikel</td><td>' . htmlspecialchars($itemName) . '</td></tr>
                            <tr><td>Menge</td><td>' . htmlspecialchars((string)$reqData['quantity']) . '</td></tr>
                            <tr><td>Bestätigt am</td><td>' . date('d.m.Y H:i') . '</td></tr>
                        </table>'
                    );
                    MailService::sendEmail(
                        $userEmail,
                        'Rückgabe bestätigt: ' . $safeItemName,
                        $emailBody
                    );
                }
            }
        } catch (Exception $mailEx) {
            error_log('rental_request_action: Fehler beim Senden der Rückgabe-Bestätigung: ' . $mailEx->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Rückgabe erfolgreich verifiziert']);
        exit;
    }

    if ($action === 'verify_rental_return') {
        $rentalId  = (int)($input['rental_id'] ?? 0);
        $notes     = $input['notes'] ?? '';

        if ($rentalId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Ausleihe-ID']);
            exit;
        }

        $admin     = Auth::user();
        $adminName = $admin
            ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            : ($_SESSION['user_email'] ?? 'Unbekannt');
        if ($adminName === '') {
            $adminName = $admin['email'] ?? 'Unbekannt';
        }

        $result = Inventory::approveReturn($rentalId, $adminName, $notes);

        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => $result['message']]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $result['message']]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);

} catch (Exception $e) {
    error_log('rental_request_action: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'API Fehler: ' . $e->getMessage()]);
}
