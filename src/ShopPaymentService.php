<?php
/**
 * ShopPaymentService
 * Handles PayPal (via PayPal Checkout SDK) and Vorkasse payment processing.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/MailService.php';

use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;


class ShopPaymentService {

    // -------------------------------------------------------------------------
    // PayPal
    // -------------------------------------------------------------------------

    /**
     * Obtain a PayPal OAuth2 access token via Basic Auth using PAYPAL_CLIENT_ID and
     * PAYPAL_SECRET from config/.env. The token is cached for the lifetime of the
     * current request to avoid redundant round-trips.
     *
     * @return string  Bearer access token, or empty string on failure
     */
    private static function getAccessToken(): string {
        static $cached    = '';
        static $expiresAt = 0;

        if ($cached !== '' && time() < $expiresAt) {
            return $cached;
        }

        $cached    = '';
        $expiresAt = 0;

        try {
            $clientId = defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : (getenv('PAYPAL_CLIENT_ID') ?: '');
            $secret   = defined('PAYPAL_SECRET')    ? PAYPAL_SECRET    : (getenv('PAYPAL_SECRET')    ?: '');
            $baseUrl  = defined('PAYPAL_BASE_URL')  ? PAYPAL_BASE_URL  : (getenv('PAYPAL_BASE_URL')  ?: 'https://api-m.sandbox.paypal.com');

            $httpClient = new \GuzzleHttp\Client();
            $response   = $httpClient->post($baseUrl . '/v1/oauth2/token', [
                'auth'        => [$clientId, $secret],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (empty($data['access_token'])) {
                error_log("ShopPaymentService::getAccessToken – access_token fehlt in der Antwort: " . json_encode($data));
            } else {
                $cached    = $data['access_token'];
                $expiresAt = time() + max(0, (int) ($data['expires_in'] ?? 0) - 60);
            }
        } catch (\Exception $e) {
            error_log("ShopPaymentService::getAccessToken – " . $e->getMessage());
        }

        return $cached;
    }

    /**
     * Create a PayPal order via the REST API (/v2/checkout/orders) and return the
     * PayPal Order ID. Use this as a lightweight alternative to the SDK-based
     * processPaypalPayment() when you only need the order ID.
     *
     * @param float  $amount    Amount to charge
     * @param string $currency  ISO 4217 currency code (default: 'EUR')
     * @return string|null      PayPal Order ID on success, null on failure
     */
    public static function createPayPalOrder(float $amount, string $currency = 'EUR'): ?string {
        try {
            $accessToken = self::getAccessToken();
            if ($accessToken === '') {
                error_log("ShopPaymentService::createPayPalOrder – kein Access Token verfügbar");
                return null;
            }

            $baseUrl    = defined('PAYPAL_BASE_URL') ? PAYPAL_BASE_URL : 'https://api-m.sandbox.paypal.com';
            $httpClient = new \GuzzleHttp\Client();
            $response   = $httpClient->post($baseUrl . '/v2/checkout/orders', [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount' => [
                            'currency_code' => $currency,
                            'value'         => number_format($amount, 2, '.', ''),
                        ],
                    ]],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            error_log("ShopPaymentService::createPayPalOrder – " . $e->getMessage());
            return null;
        }
    }

    /**
     * Capture an approved PayPal order via the REST API
     * (/v2/checkout/orders/{orderId}/capture). Call this after the buyer has
     * approved the payment on PayPal's approval page.
     *
     * @param string $orderId  PayPal Order ID to capture
     * @return array           ['success' => bool, 'status' => string, 'data' => array, 'error' => string|null]
     */
    public static function capturePayPalOrder(string $orderId): array {
        try {
            $accessToken = self::getAccessToken();
            if ($accessToken === '') {
                error_log("ShopPaymentService::capturePayPalOrder – kein Access Token verfügbar");
                return ['success' => false, 'status' => '', 'data' => [], 'error' => 'Kein Access Token verfügbar'];
            }

            $baseUrl    = defined('PAYPAL_BASE_URL') ? PAYPAL_BASE_URL : 'https://api-m.sandbox.paypal.com';
            $httpClient = new \GuzzleHttp\Client();
            $response   = $httpClient->post($baseUrl . "/v2/checkout/orders/{$orderId}/capture", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $data   = json_decode((string) $response->getBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("ShopPaymentService::capturePayPalOrder – ungültiges JSON in der Antwort: " . json_last_error_msg());
                $data = [];
            }
            $status = $data['status'] ?? '';

            return [
                'success' => $status === 'COMPLETED',
                'status'  => $status,
                'data'    => $data,
                'error'   => $status !== 'COMPLETED' ? "PayPal-Status: {$status}" : null,
            ];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::capturePayPalOrder – " . $e->getMessage());
            return ['success' => false, 'status' => '', 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify a PayPal webhook signature by calling PayPal's
     * /v1/notifications/verify-webhook-signature endpoint.
     * Uses PAYPAL_WEBHOOK_ID from config/.env.
     *
     * @param array  $headers  Associative array of HTTP request headers (any case)
     * @param string $body     Raw request body
     * @return bool            True when PayPal confirms the signature is valid
     */
    public static function verifyWebhookSignature(array $headers, string $body): bool {
        try {
            $accessToken = self::getAccessToken();
            if ($accessToken === '') {
                error_log("ShopPaymentService::verifyWebhookSignature – kein Access Token verfügbar");
                return false;
            }

            $webhookId = defined('PAYPAL_WEBHOOK_ID') ? PAYPAL_WEBHOOK_ID : (getenv('PAYPAL_WEBHOOK_ID') ?: '');
            if ($webhookId === '') {
                error_log("ShopPaymentService::verifyWebhookSignature – PAYPAL_WEBHOOK_ID nicht konfiguriert");
                return false;
            }

            $headersLower = array_change_key_case($headers, CASE_LOWER);
            $baseUrl      = defined('PAYPAL_BASE_URL') ? PAYPAL_BASE_URL : 'https://api-m.sandbox.paypal.com';

            $webhookEvent = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("ShopPaymentService::verifyWebhookSignature – ungültiges Webhook-JSON: " . json_last_error_msg());
                return false;
            }

            $payload = [
                'auth_algo'         => $headersLower['paypal-auth-algo']         ?? '',
                'cert_url'          => $headersLower['paypal-cert-url']          ?? '',
                'transmission_id'   => $headersLower['paypal-transmission-id']   ?? '',
                'transmission_sig'  => $headersLower['paypal-transmission-sig']  ?? '',
                'transmission_time' => $headersLower['paypal-transmission-time'] ?? '',
                'webhook_id'        => $webhookId,
                'webhook_event'     => $webhookEvent ?? [],
            ];

            $httpClient = new \GuzzleHttp\Client();
            $response   = $httpClient->post($baseUrl . '/v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return ($data['verification_status'] ?? '') === 'SUCCESS';
        } catch (\Exception $e) {
            error_log("ShopPaymentService::verifyWebhookSignature – " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a configured PayPal HTTP client using credentials from .env.
     *
     * @return PayPalHttpClient
     */
    private static function buildPayPalClient(): PayPalHttpClient {
        $clientId     = $_ENV['PAYPAL_CLIENT_ID']     ?? getenv('PAYPAL_CLIENT_ID')     ?? '';
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'] ?? getenv('PAYPAL_CLIENT_SECRET') ?? '';
        $environment  = $_ENV['PAYPAL_ENVIRONMENT']   ?? getenv('PAYPAL_ENVIRONMENT')   ?? 'sandbox';

        $env = (strtolower($environment) === 'production')
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($env);
    }

    /**
     * Initiate a PayPal payment for the given internal order.
     * Creates a PayPal order via the PayPal REST SDK and returns the approval URL
     * so the buyer can be redirected to PayPal.
     *
     * @param int    $orderId   Internal order ID (shop_orders.id)
     * @param float  $amount    Amount in EUR
     * @param string $returnUrl URL to redirect the user after approval
     * @param string $cancelUrl URL to redirect the user on cancellation
     * @return array ['success' => bool, 'redirect_url' => string|null, 'paypal_order_id' => string|null, 'error' => string|null]
     */
    public static function initiatePayPal(int $orderId, float $amount, string $returnUrl, string $cancelUrl): array {
        return self::processPaypalPayment($orderId, $amount, $returnUrl, $cancelUrl);
    }

    /**
     * Create a PayPal order using the PayPal REST SDK and return the approval URL.
     * Uses PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET from the .env file.
     *
     * After the buyer approves the payment on PayPal, call capturePaypalPayment()
     * with the returned paypal_order_id to finalise the charge and mark the order
     * as 'paid' in shop_orders.
     *
     * @param int    $orderId   Internal order ID (shop_orders.id)
     * @param float  $amount    Amount in EUR
     * @param string $returnUrl URL PayPal redirects the buyer to after approval
     * @param string $cancelUrl URL PayPal redirects the buyer to on cancellation
     * @return array ['success' => bool, 'redirect_url' => string|null, 'paypal_order_id' => string|null, 'error' => string|null]
     */
    public static function processPaypalPayment(int $orderId, float $amount, string $returnUrl, string $cancelUrl): array {
        try {
            $client  = self::buildPayPalClient();
            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');
            $request->body = [
                'intent'              => 'CAPTURE',
                'purchase_units'      => [[
                    'reference_id' => (string) $orderId,
                    'custom_id'    => (string) $orderId,
                    'amount'       => [
                        'currency_code' => 'EUR',
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ];

            $response      = $client->execute($request);
            $paypalOrderId = $response->result->id ?? null;
            $approveUrl    = null;

            foreach (($response->result->links ?? []) as $link) {
                if ($link->rel === 'approve') {
                    $approveUrl = $link->href;
                    break;
                }
            }

            self::writeAuditLog(0, 'shop_payment_initiated', 'shop_order', $orderId,
                "PayPal-Bestellung erstellt: {$paypalOrderId}, Betrag: {$amount} EUR");

            return [
                'success'         => true,
                'redirect_url'    => $approveUrl,
                'paypal_order_id' => $paypalOrderId,
                'error'           => null,
            ];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::processPaypalPayment – Fehler bei Bestellung #{$orderId}: " . $e->getMessage());
            return [
                'success'         => false,
                'redirect_url'    => null,
                'paypal_order_id' => null,
                'error'           => 'PayPal-Zahlung konnte nicht initiiert werden: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Capture an approved PayPal order, then set payment_status = 'paid' and
     * write an audit-log entry.
     *
     * Call this after PayPal redirects the buyer back to your return URL.
     *
     * @param int    $orderId       Internal order ID (shop_orders.id)
     * @param string $paypalOrderId PayPal order ID from the return URL (?token=…)
     * @return array ['success' => bool, 'transaction_id' => string|null, 'error' => string|null]
     */
    public static function capturePaypalPayment(int $orderId, string $paypalOrderId): array {
        try {
            $client  = self::buildPayPalClient();
            $request = new OrdersCaptureRequest($paypalOrderId);
            $request->prefer('return=representation');

            $response      = $client->execute($request);
            $status        = $response->result->status ?? '';
            $transactionId = $response->result->purchase_units[0]->payments->captures[0]->id ?? null;

            if ($status === 'COMPLETED') {
                self::markOrderPaid($orderId);
                self::writeAuditLog(0, 'shop_payment_completed', 'shop_order', $orderId,
                    "PayPal-Zahlung abgeschlossen: TransaktionsID {$transactionId}");

                return [
                    'success'        => true,
                    'transaction_id' => $transactionId,
                    'error'          => null,
                ];
            }

            return [
                'success'        => false,
                'transaction_id' => $transactionId,
                'error'          => "PayPal-Status unbekannt: {$status}",
            ];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::capturePaypalPayment – Fehler bei Bestellung #{$orderId}: " . $e->getMessage());
            return [
                'success'        => false,
                'transaction_id' => null,
                'error'          => 'PayPal-Zahlung konnte nicht abgebucht werden: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a PayPal payment after the user returns from PayPal.
     * Alias for capturePaypalPayment() for backward compatibility.
     *
     * @param string $paypalOrderId The PayPal order ID returned in the redirect
     * @param int    $orderId       Internal order ID (shop_orders.id) – required to mark order as paid
     * @return array ['success' => bool, 'transaction_id' => string|null, 'error' => string|null]
     */
    public static function verifyPayPal(string $paypalOrderId, int $orderId = 0): array {
        return self::capturePaypalPayment($orderId, $paypalOrderId);
    }

    // -------------------------------------------------------------------------
    // Bank Transfer (Überweisung)
    // -------------------------------------------------------------------------

    /**
     * Generate an 8-character name code + zero-padded order ID as payment purpose.
     *
     * Rule: Take up to 4 characters from first name; fill the remainder to reach
     * 8 characters with characters from the last name.  Pad with 'X' for very
     * short names.  Append the order ID zero-padded to 5 digits.
     *
     * Example: first='Tom', last='Lehmann', orderId=1 → 'TOMLEHMA00001'
     *
     * @param string $firstName  User's first name
     * @param string $lastName   User's last name
     * @param int    $orderId    Internal shop order ID used as reference number
     * @return string            Uppercase payment purpose, e.g. 'TOMLEHMA00001'
     */
    public static function generatePaymentPurpose(string $firstName, string $lastName, int $orderId): string {
        $first = strtoupper(preg_replace('/[^A-Za-z]/', '', $firstName));
        $last  = strtoupper(preg_replace('/[^A-Za-z]/', '', $lastName));

        $prefix    = substr($first, 0, 4);
        $remaining = 8 - strlen($prefix);
        $suffix    = substr($last, 0, $remaining);

        $nameCode = str_pad($prefix . $suffix, 8, 'X');

        return $nameCode . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Initiate a bank-transfer payment for a shop order:
     *  1. Generate a payment purpose (Verwendungszweck).
     *  2. Save an invoice record in the Rech DB with the payment purpose.
     *  3. Send bank-transfer instructions via e-mail to the user.
     *  4. Create an open document (offener Beleg) in EasyVerein (non-blocking).
     *
     * @param int    $orderId    Internal shop order ID
     * @param float  $total      Grand total in EUR
     * @param string $firstName  Buyer's first name
     * @param string $lastName   Buyer's last name
     * @param int    $userId     Buyer's user ID
     * @param string $userEmail  Buyer's Entra e-mail address
     * @return array ['success' => bool, 'payment_purpose' => string|null, 'error' => string|null]
     */
    public static function initiateBankTransfer(int $orderId, float $total, string $firstName, string $lastName, int $userId, string $userEmail): array {
        try {
            $paymentPurpose = self::generatePaymentPurpose($firstName, $lastName, $orderId);

            // 1. Save invoice entry in Rech DB with payment_purpose
            $invoiceId = self::saveOrderInvoice($orderId, $userId, $total, $paymentPurpose);

            // 2. Send bank-transfer instructions to the buyer (non-blocking)
            try {
                MailService::sendBankTransferInstructions($userEmail, $firstName, $total, $paymentPurpose);
            } catch (\Exception $mailEx) {
                error_log("ShopPaymentService::initiateBankTransfer – e-mail failed for order #{$orderId}: " . $mailEx->getMessage());
            }

            // 3. Create open document in EasyVerein (non-blocking)
            try {
                $evDocId = self::createEasyVereinDocument($orderId, $total, $paymentPurpose);
                if ($evDocId !== null && $invoiceId > 0) {
                    $db   = Database::getRechDB();
                    $stmt = $db->prepare("UPDATE invoices SET easyverein_document_id = ? WHERE id = ?");
                    $stmt->execute([$evDocId, $invoiceId]);
                }
            } catch (\Exception $evEx) {
                error_log("ShopPaymentService::initiateBankTransfer – EasyVerein document failed for order #{$orderId}: " . $evEx->getMessage());
            }

            return ['success' => true, 'payment_purpose' => $paymentPurpose, 'error' => null];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::initiateBankTransfer – Fehler bei Bestellung #{$orderId}: " . $e->getMessage());
            return ['success' => false, 'payment_purpose' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Persist an invoice record for a shop order in the Rech DB.
     *
     * @param int    $orderId        Shop order ID (used as reference)
     * @param int    $userId         User ID
     * @param float  $total          Order total in EUR
     * @param string $paymentPurpose Generated Verwendungszweck
     * @return int                   New invoice ID
     */
    private static function saveOrderInvoice(int $orderId, int $userId, float $total, string $paymentPurpose): int {
        $db   = Database::getRechDB();
        $stmt = $db->prepare(
            "INSERT INTO invoices (user_id, description, amount, file_path, status, payment_purpose)
             VALUES (?, ?, ?, NULL, 'pending', ?)"
        );
        $stmt->execute([
            $userId,
            'Shop-Bestellung #' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT),
            $total,
            $paymentPurpose,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Create an open billing document (offener Beleg) in EasyVerein for the order.
     * This call is non-blocking – errors are logged but not propagated.
     *
     * @param int    $orderId        Shop order ID
     * @param float  $total          Order total in EUR
     * @param string $paymentPurpose Verwendungszweck
     * @return string|null           EasyVerein document ID, or null on failure
     */
    private static function createEasyVereinDocument(int $orderId, float $total, string $paymentPurpose): ?string {
        $apiToken = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        if ($apiToken === '') {
            error_log("ShopPaymentService::createEasyVereinDocument – EASYVEREIN_API_TOKEN not configured");
            return null;
        }

        $payload = [
            'name'        => 'Shop-Bestellung #' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT),
            'totalPrice'  => $total,
            'date'        => date('Y-m-d'),
            'isDone'      => false,
            'description' => 'Verwendungszweck: ' . $paymentPurpose,
        ];

        $httpClient = new \GuzzleHttp\Client();
        $response   = $httpClient->post('https://easyverein.com/api/v2.0/billing-document/', [
            'headers' => [
                'Authorization' => 'Token ' . $apiToken,
                'Content-Type'  => 'application/json',
            ],
            'json'            => $payload,
            'timeout'         => 15,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            error_log("ShopPaymentService::createEasyVereinDocument – EasyVerein API returned HTTP {$statusCode} for order #{$orderId}: " . (string) $response->getBody());
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);
        $evDocId = isset($data['id']) ? (string) $data['id'] : null;
        return $evDocId;
    }



    /**
     * Set payment_status = 'paid' for the given order in shop_orders (Content DB).
     *
     * @param int $orderId
     * @return void
     */
    private static function markOrderPaid(int $orderId): void {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare("UPDATE shop_orders SET payment_status = 'paid' WHERE id = ?");
            $stmt->execute([$orderId]);

            // Send confirmation email to the member
            try {
                $orderStmt = $db->prepare("SELECT user_id, total_amount FROM shop_orders WHERE id = ?");
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch();

                if ($order) {
                    $userDb   = Database::getUserDB();
                    $userStmt = $userDb->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                    $userStmt->execute([$order['user_id']]);
                    $user = $userStmt->fetch();

                    if ($user && !empty($user['email'])) {
                        $itemsStmt = $db->prepare(
                            "SELECT soi.quantity, soi.price_at_purchase,
                                    sp.name AS product_name,
                                    sv.value AS variant_value
                             FROM shop_order_items soi
                             JOIN shop_products sp ON sp.id = soi.product_id
                             LEFT JOIN shop_variants sv ON sv.id = soi.variant_id
                             WHERE soi.order_id = ?"
                        );
                        $itemsStmt->execute([$orderId]);
                        $items = $itemsStmt->fetchAll();

                        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        $orderData = [
                            'id'           => $orderId,
                            'total_amount' => $order['total_amount'],
                        ];

                        MailService::sendShopOrderConfirmation($user['email'], $fullName, $orderData, $items);
                    }
                }
            } catch (\Exception $mailEx) {
                error_log("ShopPaymentService::markOrderPaid – Fehler beim Senden der Bestätigungs-E-Mail für Bestellung #{$orderId}: " . $mailEx->getMessage());
            }
        } catch (\Exception $e) {
            error_log("ShopPaymentService::markOrderPaid – Fehler bei Bestellung #{$orderId}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Webhook handling
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming webhook from PayPal.
     *
     * Detects the payment provider from request headers, verifies the signature,
     * and updates the relevant records on payment events.
     *
     * PayPal:  listens for PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED,
     *          PAYMENT.CAPTURE.REFUNDED. Updates the invoices table (Rech DB).
     *          Verification via PayPal's verify-webhook-signature API.
     *          Requires PAYPAL_WEBHOOK_ID in .env. Returns 403 on invalid signature.
     *
     * @return void  Sends HTTP 200 / 400 / 403 and exits.
     */
    public static function handleWebhook(): void {
        $rawBody = (string) file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];

        // Normalise header names to lowercase for reliable lookup
        $headersLower = array_change_key_case($headers, CASE_LOWER);

        if (isset($headersLower['paypal-transmission-id'])) {
            self::handlePayPalWebhook($rawBody, $headersLower);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown webhook source']);
        exit;
    }

    /**
     * Process a verified PayPal webhook payload.
     *
     * Handles PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED and
     * PAYMENT.CAPTURE.REFUNDED events. Updates the invoices table in the
     * Rech DB accordingly.
     *
     * @param string $rawBody      Raw request body
     * @param array  $headers      Lowercase-keyed request headers
     * @return void
     */
    private static function handlePayPalWebhook(string $rawBody, array $headers): void {
        $webhookId = $_ENV['PAYPAL_WEBHOOK_ID'] ?? getenv('PAYPAL_WEBHOOK_ID') ?? '';

        if ($webhookId !== '' && !self::verifyPayPalWebhookSignature($rawBody, $headers, $webhookId)) {
            error_log("ShopPaymentService::handlePayPalWebhook – ungültige Signatur");
            http_response_code(403);
            echo json_encode(['error' => 'Invalid PayPal signature']);
            exit;
        }

        $event = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)) {
            error_log("ShopPaymentService::handlePayPalWebhook – ungültiges JSON im Request-Body");
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            exit;
        }
        $eventType = $event['event_type'] ?? '';
        $resource  = $event['resource'] ?? [];

        // Map PayPal event types to internal invoice statuses
        $eventStatusMap = [
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DENIED'    => 'failed',
            'PAYMENT.CAPTURE.REFUNDED'  => 'refunded',
        ];

        if (isset($eventStatusMap[$eventType])) {
            $invoiceStatus = $eventStatusMap[$eventType];

            // Extract the internal ID from custom_id (set when creating the PayPal order).
            // Falls back to a lookup by capture/transaction ID when custom_id is absent.
            $internalId = (int) ($resource['custom_id'] ?? 0);
            if ($internalId <= 0) {
                $transactionId = $resource['id'] ?? '';
                if ($transactionId !== '') {
                    $internalId = self::findInvoiceByTransaction($transactionId);
                }
            }

            if ($internalId > 0) {
                self::updateInvoiceStatus($internalId, $invoiceStatus);
                self::writeAuditLog(0, 'invoice_payment_' . $invoiceStatus, 'invoice', $internalId,
                    "PayPal Webhook: {$eventType} – Capture " . ($resource['id'] ?? ''));
            }
        }

        // Always acknowledge receipt so PayPal does not retry the event.
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    /**
     * Look up an invoice ID by PayPal capture/transaction ID.
     *
     * @param string $transactionId  PayPal capture or transaction ID
     * @return int                   Invoice ID, or 0 if not found
     */
    private static function findInvoiceByTransaction(string $transactionId): int {
        try {
            $db   = Database::getRechDB();
            $stmt = $db->prepare("SELECT id FROM invoices WHERE paypal_transaction_id = ? LIMIT 1");
            $stmt->execute([$transactionId]);
            $row  = $stmt->fetch();
            return $row ? (int) $row['id'] : 0;
        } catch (\Exception $e) {
            error_log("ShopPaymentService::findInvoiceByTransaction – " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update the status of an invoice in the invoices table (Rech DB).
     *
     * @param int    $invoiceId  Internal invoice ID
     * @param string $status     New status: 'paid', 'failed', or 'refunded'
     * @return void
     */
    private static function updateInvoiceStatus(int $invoiceId, string $status): void {
        try {
            $db   = Database::getRechDB();
            if ($status === 'paid') {
                $stmt = $db->prepare(
                    "UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?"
                );
                $stmt->execute([$status, date('Y-m-d H:i:s'), $invoiceId]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE invoices SET status = ? WHERE id = ?"
                );
                $stmt->execute([$status, $invoiceId]);
            }
        } catch (\Exception $e) {
            error_log("ShopPaymentService::updateInvoiceStatus – Fehler bei Rechnung #{$invoiceId}: " . $e->getMessage());
        }
    }

    /**
     * Verify a PayPal webhook signature by calling PayPal's
     * /v1/notifications/verify-webhook-signature endpoint.
     *
     * @param string $rawBody   Raw request body
     * @param array  $headers   Lowercase-keyed request headers
     * @param string $webhookId Webhook ID from the PayPal developer dashboard (PAYPAL_WEBHOOK_ID)
     * @return bool             True when PayPal confirms the signature is valid
     */
    private static function verifyPayPalWebhookSignature(string $rawBody, array $headers, string $webhookId): bool {
        try {
            $accessToken = self::getAccessToken();
            if ($accessToken === '') {
                return false;
            }

            $baseUrl    = defined('PAYPAL_BASE_URL') ? PAYPAL_BASE_URL : 'https://api-m.sandbox.paypal.com';
            $httpClient = new \GuzzleHttp\Client();

            $verifyPayload = [
                'auth_algo'         => $headers['paypal-auth-algo']         ?? '',
                'cert_url'          => $headers['paypal-cert-url']          ?? '',
                'transmission_id'   => $headers['paypal-transmission-id']   ?? '',
                'transmission_sig'  => $headers['paypal-transmission-sig']  ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id'        => $webhookId,
                'webhook_event'     => json_decode($rawBody, true) ?? [],
            ];

            $verifyRes  = $httpClient->post($baseUrl . '/v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => $verifyPayload,
            ]);

            $verifyData = json_decode((string) $verifyRes->getBody(), true);
            return ($verifyData['verification_status'] ?? '') === 'SUCCESS';

        } catch (\Exception $e) {
            error_log("ShopPaymentService::verifyPayPalWebhookSignature – " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert an entry into the system_logs audit table (Content DB).
     *
     * @param int         $userId      ID of the acting user (0 for system/cron actions)
     * @param string      $action      Action identifier, e.g. 'shop_payment_completed'
     * @param string      $entityType  Entity type, e.g. 'shop_order'
     * @param int|null    $entityId    ID of the affected entity
     * @param string|null $details     Free-text or JSON details
     * @return void
     */
    private static function writeAuditLog(int $userId, string $action, string $entityType, ?int $entityId, ?string $details): void {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $details,
                $_SERVER['REMOTE_ADDR']     ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Exception $e) {
            error_log("ShopPaymentService::writeAuditLog – " . $e->getMessage());
        }
    }
}
