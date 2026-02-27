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
    // Shared helpers
    // -------------------------------------------------------------------------

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
    // Bank Transfer (Vorkasse / Banküberweisung)
    // -------------------------------------------------------------------------

    /**
     * Generate a structured payment purpose (Verwendungszweck) for bank transfers.
     *
     * Rule: Take the first 4 letters of the first name; if the first name is shorter
     * than 4 letters, fill up with letters from the last name so the alphabetic prefix
     * is exactly 8 characters long. Append the invoice ID zero-padded to 5 digits.
     * Everything is converted to uppercase.
     *
     * Example: "Tom" + "Lehmann" + invoiceId 1 → "TOMLEHMA00001"
     *
     * @param string $firstName  User's first name
     * @param string $lastName   User's last name
     * @param int    $invoiceId  Invoice ID from the invoices table
     * @return string            Payment purpose string (e.g. "TOMLEHMA00001")
     */
    public static function generatePaymentPurpose(string $firstName, string $lastName, int $invoiceId): string {
        $fn = strtoupper(preg_replace('/[^A-Za-z]/', '', $firstName));
        $ln = strtoupper(preg_replace('/[^A-Za-z]/', '', $lastName));

        $fromFirst = min(4, mb_strlen($fn));
        $fromLast  = 8 - $fromFirst;

        $prefix = mb_substr($fn, 0, $fromFirst) . mb_substr($ln, 0, $fromLast);

        return $prefix . str_pad((string) $invoiceId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Initiate a bank-transfer (Vorkasse) checkout for the given shop order.
     *
     * Actions performed:
     *  1. Creates an open invoice record in the invoices table (Rech DB)
     *  2. Generates the payment purpose (Verwendungszweck)
     *  3. Updates the invoice with the generated payment purpose
     *  4. Sends a bank-transfer instruction e-mail to the user's Entra e-mail address
     *  5. Creates an open bill (offener Beleg) in EasyVerein
     *
     * @param int    $orderId   Internal shop order ID
     * @param float  $total     Total amount in EUR
     * @param int    $userId    User ID
     * @param string $firstName User's first name
     * @param string $lastName  User's last name
     * @param string $userEmail User's Entra e-mail address
     * @return array ['success' => bool, 'payment_purpose' => string|null, 'invoice_id' => int|null, 'error' => string|null]
     */
    public static function initiateBankTransfer(int $orderId, float $total, int $userId, string $firstName, string $lastName, string $userEmail): array {
        try {
            $db = Database::getRechDB();

            // 1. Create open invoice record (file_path uses a placeholder for shop orders)
            $stmt = $db->prepare(
                "INSERT INTO invoices (user_id, description, amount, file_path, status)
                 VALUES (?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $userId,
                'Shop-Bestellung #' . $orderId,
                $total,
                'shop_order_' . $orderId,
            ]);
            $invoiceId = (int) $db->lastInsertId();

            // 2. Generate payment purpose
            $paymentPurpose = self::generatePaymentPurpose($firstName, $lastName, $invoiceId);

            // 3. Save payment_purpose back to the invoice
            $stmt = $db->prepare("UPDATE invoices SET payment_purpose = ? WHERE id = ?");
            $stmt->execute([$paymentPurpose, $invoiceId]);

            // 4. Send bank-transfer instruction e-mail to the user
            try {
                self::sendBankTransferEmail($userEmail, $total, $paymentPurpose, $orderId);
            } catch (\Exception $mailEx) {
                error_log("ShopPaymentService::initiateBankTransfer – E-Mail an {$userEmail} fehlgeschlagen: " . $mailEx->getMessage());
            }

            // 5. Create open bill in EasyVerein (non-blocking – failures are only logged)
            try {
                self::createEasyVereinBill($orderId, $total, 'Shop-Bestellung #' . $orderId, $paymentPurpose);
            } catch (\Exception $evEx) {
                error_log("ShopPaymentService::initiateBankTransfer – EasyVerein-Beleg fehlgeschlagen: " . $evEx->getMessage());
            }

            self::writeAuditLog($userId, 'shop_bank_transfer_initiated', 'shop_order', $orderId,
                "Banküberweisung initiiert: Verwendungszweck {$paymentPurpose}, Betrag {$total} EUR");

            return [
                'success'         => true,
                'payment_purpose' => $paymentPurpose,
                'invoice_id'      => $invoiceId,
                'error'           => null,
            ];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::initiateBankTransfer – " . $e->getMessage());
            return [
                'success'         => false,
                'payment_purpose' => null,
                'invoice_id'      => null,
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Send bank-transfer payment instructions to the user.
     *
     * The e-mail contains the total amount, the club's IBAN and the payment purpose
     * (Verwendungszweck) displayed very prominently, together with the mandatory
     * note asking the user to use the exact payment purpose when transferring.
     *
     * @param string $toEmail        Recipient e-mail address (Entra e-mail of the user)
     * @param float  $total          Total amount in EUR
     * @param string $paymentPurpose Generated Verwendungszweck (e.g. "TOMLEHMA00001")
     * @param int    $orderId        Internal shop order ID (used in the e-mail subject)
     * @return bool
     */
    private static function sendBankTransferEmail(string $toEmail, float $total, string $paymentPurpose, int $orderId): bool {
        $vereinsIban = defined('VEREINS_IBAN') ? VEREINS_IBAN : (getenv('VEREINS_IBAN') ?: '');

        if ($vereinsIban === '') {
            error_log("ShopPaymentService::sendBankTransferEmail – VEREINS_IBAN ist nicht konfiguriert. E-Mail wird ohne IBAN gesendet.");
        }

        $amountFormatted = number_format($total, 2, ',', '.') . ' €';

        $bodyContent  = '<p class="email-text">Vielen Dank für deine Bestellung! Bitte überweise den fälligen Betrag auf das folgende Vereinskonto.</p>';
        $bodyContent .= '<table class="info-table">';
        $bodyContent .= '<tr><td><strong>Betrag</strong></td><td>' . htmlspecialchars($amountFormatted) . '</td></tr>';
        $bodyContent .= '<tr><td><strong>IBAN</strong></td><td>' . htmlspecialchars($vereinsIban) . '</td></tr>';
        $bodyContent .= '</table>';
        $bodyContent .= '<div style="background-color:#fff8e1;border:2px solid #f59e0b;border-radius:8px;padding:24px;margin:24px 0;text-align:center;">';
        $bodyContent .= '<p style="font-size:15px;font-weight:bold;color:#555;margin:0 0 8px 0;">Verwendungszweck – bitte exakt so angeben:</p>';
        $bodyContent .= '<p style="font-size:30px;font-weight:bold;color:#20234A;letter-spacing:3px;margin:0 0 16px 0;">' . htmlspecialchars($paymentPurpose) . '</p>';
        $bodyContent .= '<p style="font-size:14px;color:#333;margin:0;"><strong>Bitte gib bei der Überweisung EXAKT diesen Verwendungszweck an, da wir deine Zahlung sonst nicht automatisch zuordnen können.</strong></p>';
        $bodyContent .= '</div>';
        $bodyContent .= '<p class="email-text">Sobald deine Zahlung eingegangen ist, wird deine Bestellung bearbeitet.</p>';

        $htmlBody = MailService::getTemplate('Zahlungsinformationen für deine Bestellung', $bodyContent);

        return MailService::sendEmail($toEmail, 'Zahlungsinformationen für deine Bestellung #' . $orderId, $htmlBody);
    }

    /**
     * Create an open bill (offener Beleg) in EasyVerein via the REST API.
     *
     * Failures are non-fatal: the caller catches exceptions and only logs them so
     * that the checkout is not blocked when EasyVerein is temporarily unavailable.
     *
     * @param int    $orderId          Internal shop order ID
     * @param float  $amount           Total amount in EUR
     * @param string $description      Bill description (e.g. "Shop-Bestellung #42")
     * @param string $paymentPurpose   Payment purpose / Verwendungszweck
     * @return bool                    True when the bill was created successfully
     */
    private static function createEasyVereinBill(int $orderId, float $amount, string $description, string $paymentPurpose): bool {
        $apiToken = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : (getenv('EASYVEREIN_API_TOKEN') ?: '');

        if ($apiToken === '') {
            error_log("ShopPaymentService::createEasyVereinBill – EASYVEREIN_API_TOKEN nicht konfiguriert");
            return false;
        }

        $payload = [
            'name'             => $description,
            'amount'           => round($amount, 2),
            'dateOfInvoice'    => date('Y-m-d'),
            'paymentReference' => $paymentPurpose,
            'isPaid'           => false,
        ];

        $ch = curl_init('https://easyverein.com/api/v2.0/bill');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("ShopPaymentService::createEasyVereinBill – cURL-Fehler: {$curlErr}");
            return false;
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("ShopPaymentService::createEasyVereinBill – API HTTP {$httpCode} für Bestellung #{$orderId}: {$response}");
            return false;
        }

        return true;
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

        $event     = json_decode($rawBody, true) ?? [];
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
