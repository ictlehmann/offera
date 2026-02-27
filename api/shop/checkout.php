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

// 2. Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$user   = AuthHandler::getCurrentUser();
$userId = (int) ($user['id'] ?? 0);

$input         = json_decode(file_get_contents('php://input'), true) ?? [];
$paymentMethod = in_array($input['payment_method'] ?? '', ['paypal', 'sepa'], true)
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
    } elseif ($paymentMethod === 'sepa') {
        $iban   = trim($input['sepa_iban']   ?? '');
        $holder = trim($input['sepa_holder'] ?? '');

        if ($iban === '' || $holder === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'IBAN und Kontoinhaber sind erforderlich.']);
            exit;
        }

        $payResult = ShopPaymentService::initiateSepa($orderId, $total, $iban, $holder);

        if ($payResult['success']) {
            $_SESSION['shop_cart'] = [];
            echo json_encode([
                'success'  => true,
                'order_id' => $orderId,
                'message'  => 'Bestellung #' . $orderId . ' aufgegeben! Ihre SEPA-Lastschrift wurde bei der Bank eingereicht.',
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $payResult['error'] ?? 'SEPA-Zahlung fehlgeschlagen.',
            ]);
        }
    }
} catch (Exception $e) {
    error_log('api/shop/checkout.php – ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
