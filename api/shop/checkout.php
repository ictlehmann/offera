<?php
/**
 * Shop Checkout API
 * Validates the session cart, checks stock, creates an order and initiates payment.
 */

require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/models/Shop.php';
require_once __DIR__ . '/../../src/ShopPaymentService.php';
require_once __DIR__ . '/../../src/MailService.php';

// Start session and set JSON response header
AuthHandler::startSession();

header('Content-Type: application/json');

// 1. Check authentication
if (!AuthHandler::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

$user   = AuthHandler::getCurrentUser();
$userId = (int) ($user['id'] ?? 0);

$routeAction = $_GET['action'] ?? '';

// ─── PayPal JS SDK v6: createOrder ───────────────────────────────────────────
if ($routeAction === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        exit;
    }

    $input          = json_decode(file_get_contents('php://input'), true) ?? [];
    $shippingMethod = in_array($input['shipping_method'] ?? '', ['pickup', 'mail'], true)
        ? $input['shipping_method']
        : 'pickup';
    $shippingCost    = ($shippingMethod === 'mail') ? 4.90 : 0.00;
    $shippingAddress = trim($input['shipping_address'] ?? '');

    if ($shippingMethod === 'mail' && $shippingAddress === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bitte gib eine Lieferadresse an.']);
        exit;
    }

    try {
        $cart = isset($_SESSION['shop_cart']) ? array_values($_SESSION['shop_cart']) : [];
        if (empty($cart)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Der Warenkorb ist leer.']);
            exit;
        }

        $stockErrors = Shop::checkStock($cart);
        if (!empty($stockErrors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => implode(' ', $stockErrors)]);
            exit;
        }

        $orderId = Shop::createOrder($userId, $cart, 'paypal', $shippingMethod, $shippingCost, $shippingAddress);
        if (!$orderId) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Erstellen der Bestellung.']);
            exit;
        }

        Shop::decrementStock($orderId);

        $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cart)) + $shippingCost;

        // Send order notification e-mail to merch team (non-blocking)
        try {
            $userEmail = $user['email'] ?? '';
            if ($userEmail !== '') {
                $fullUser = User::getByEmail($userEmail);
                MailService::sendNewOrderNotification(
                    $orderId,
                    $fullUser['first_name'] ?? '',
                    $fullUser['last_name']  ?? '',
                    $userEmail,
                    $cart,
                    'paypal',
                    $total
                );
            }
        } catch (Exception $e) {
            error_log('api/shop/checkout.php (create) – order notification email failed: ' . $e->getMessage());
        }

        // Create PayPal order via REST API and get the PayPal order ID
        $paypalOrderId = ShopPaymentService::createPayPalOrder($total);
        if (!$paypalOrderId) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Erstellen der PayPal-Bestellung.']);
            exit;
        }

        // Store in session so the capture endpoint can verify ownership
        $_SESSION['pending_paypal_order'] = [
            'order_id'       => $orderId,
            'paypal_order_id' => $paypalOrderId,
        ];

        echo json_encode([
            'success'         => true,
            'paypal_order_id' => $paypalOrderId,
            'order_id'        => $orderId,
        ]);
    } catch (Exception $e) {
        error_log('api/shop/checkout.php (create) – ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
    }
    exit;
}

// ─── PayPal JS SDK v6: capture ────────────────────────────────────────────────
if ($routeAction === 'capture') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        exit;
    }

    $input         = json_decode(file_get_contents('php://input'), true) ?? [];
    $paypalOrderId = trim($input['paypal_order_id'] ?? '');

    if ($paypalOrderId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'PayPal-Order-ID fehlt.']);
        exit;
    }

    // Verify the PayPal order ID against the session to prevent cross-order manipulation
    $pending = $_SESSION['pending_paypal_order'] ?? null;
    if (!$pending || $pending['paypal_order_id'] !== $paypalOrderId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige oder abgelaufene PayPal-Session.']);
        exit;
    }

    $orderId = (int) $pending['order_id'];

    try {
        $captureResult = ShopPaymentService::capturePaypalPayment($orderId, $paypalOrderId);

        if ($captureResult['success']) {
            $_SESSION['shop_cart'] = [];
            unset($_SESSION['pending_paypal_order']);

            echo json_encode([
                'success'  => true,
                'order_id' => $orderId,
                'message'  => 'Zahlung für Bestellung #' . $orderId . ' erfolgreich abgeschlossen!',
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => ($captureResult['error'] ?? 'PayPal-Zahlung konnte nicht abgebucht werden.') . ' Bitte versuche es erneut oder kontaktiere den Support.',
            ]);
        }
    } catch (Exception $e) {
        error_log('api/shop/checkout.php (capture) – ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
    }
    exit;
}

// ─── Legacy checkout: bank transfer / Banküberweisung (POST only) ────────────

// 2. Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$input         = json_decode(file_get_contents('php://input'), true) ?? [];
$paymentMethod = in_array($input['payment_method'] ?? '', ['paypal', 'bank_transfer'], true)
    ? $input['payment_method']
    : 'paypal';

$shippingMethod = in_array($input['shipping_method'] ?? '', ['pickup', 'mail'], true)
    ? $input['shipping_method']
    : 'pickup';

$shippingCost = ($shippingMethod === 'mail') ? 4.90 : 0.00;
// Always use server-side shipping cost to prevent client manipulation

$shippingAddress = trim($input['shipping_address'] ?? '');

if ($shippingMethod === 'mail' && $shippingAddress === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib eine Lieferadresse an.']);
    exit;
}

try {
    // 3. Validate session cart
    $cart = isset($_SESSION['shop_cart']) ? array_values($_SESSION['shop_cart']) : [];
    if (empty($cart)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Der Warenkorb ist leer.']);
        exit;
    }

    // 4. Check stock in shop_variants
    $stockErrors = Shop::checkStock($cart);
    if (!empty($stockErrors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $stockErrors)]);
        exit;
    }

    // 5. Create order in shop_orders
    $orderId = Shop::createOrder($userId, $cart, $paymentMethod, $shippingMethod, $shippingCost, $shippingAddress);
    if (!$orderId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fehler beim Erstellen der Bestellung.']);
        exit;
    }

    // 5a. Immediately decrement stock after order is created
    Shop::decrementStock($orderId);

    $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cart)) + $shippingCost;

    // 5b. Send order notification e-mail to merch team (non-blocking)
    try {
        $fullUser = User::getByEmail($user['email'] ?? '');
        MailService::sendNewOrderNotification(
            $orderId,
            $fullUser['first_name'] ?? '',
            $fullUser['last_name']  ?? '',
            $user['email']          ?? '',
            $cart,
            $paymentMethod,
            $total
        );
    } catch (Exception $e) {
        error_log('api/shop/checkout.php – order notification email failed: ' . $e->getMessage());
    }

    // 6. Call ShopPaymentService to initiate payment
    if ($paymentMethod === 'paypal') {
        $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
        $returnUrl = $baseUrl . '/pages/shop/index.php?action=payment_return&order=' . $orderId;
        $cancelUrl = $baseUrl . '/pages/shop/index.php?action=cart';

        $payResult = ShopPaymentService::initiatePayPal($orderId, $total, $returnUrl, $cancelUrl);

        if ($payResult['success'] && !empty($payResult['redirect_url'])) {
            $_SESSION['shop_cart'] = [];
            echo json_encode([
                'success'      => true,
                'order_id'     => $orderId,
                'redirect_url' => $payResult['redirect_url'],
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $payResult['error'] ?? 'PayPal-Weiterleitung fehlgeschlagen.',
            ]);
        }
    } elseif ($paymentMethod === 'bank_transfer') {
        $fullUser  = User::getByEmail($user['email'] ?? '');
        $firstName = $fullUser['first_name'] ?? '';
        $lastName  = $fullUser['last_name']  ?? '';

        $payResult = ShopPaymentService::initiateBankTransfer(
            $orderId,
            $total,
            $userId,
            $firstName,
            $lastName,
            $user['email'] ?? ''
        );

        if ($payResult['success']) {
            $_SESSION['shop_cart'] = [];
            echo json_encode([
                'success'          => true,
                'order_id'         => $orderId,
                'payment_purpose'  => $payResult['payment_purpose'],
                'message'          => 'Bestellung #' . $orderId . ' aufgegeben! Du erhältst eine E-Mail mit den Überweisungsdaten.',
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $payResult['error'] ?? 'Banküberweisung konnte nicht initiiert werden.',
            ]);
        }
    }
} catch (Exception $e) {
    error_log('api/shop/checkout.php – ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
