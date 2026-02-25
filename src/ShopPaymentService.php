<?php
/**
 * ShopPaymentService
 * Handles PayPal (via PayPal Checkout SDK) and SEPA (via Stripe) payment processing.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/MailService.php';

use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use Stripe\StripeClient;

class ShopPaymentService {

    // -------------------------------------------------------------------------
    // PayPal
    // -------------------------------------------------------------------------

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
    // Stripe SEPA
    // -------------------------------------------------------------------------

    /**
     * Build a configured Stripe client using the secret key from .env.
     *
     * @return StripeClient
     */
    private static function buildStripeClient(): StripeClient {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?? '';
        return new StripeClient($secretKey);
    }

    /**
     * Initiate a SEPA direct-debit payment via Stripe.
     * Alias for processSepaPayment() for backward compatibility.
     *
     * @param int    $orderId     Internal order ID
     * @param float  $amount      Amount in EUR
     * @param string $iban        Customer IBAN
     * @param string $holderName  Account holder name
     * @return array ['success' => bool, 'mandate_id' => string|null, 'client_secret' => string|null, 'error' => string|null]
     */
    public static function initiateSepa(int $orderId, float $amount, string $iban, string $holderName): array {
        return self::processSepaPayment($orderId, $amount, $iban, $holderName);
    }

    /**
     * Process a SEPA direct-debit payment using Stripe.
     * Uses STRIPE_SECRET_KEY from the .env file.
     *
     * Flow:
     *  1. Create (or retrieve) a Stripe Customer.
     *  2. Attach a sepa_debit PaymentMethod using the customer's IBAN.
     *  3. Create and confirm a PaymentIntent.
     *  4. On success (status = 'succeeded' or 'processing'): mark shop_orders.payment_status
     *     as 'paid' and write an audit-log entry.
     *
     * Note: SEPA debit payments may be asynchronous. Stripe will send a
     * payment_intent.succeeded webhook when the bank confirms the debit.
     * You should also handle that webhook to guarantee the status update.
     *
     * @param int    $orderId    Internal order ID (shop_orders.id)
     * @param float  $amount     Amount in EUR
     * @param string $iban       Customer IBAN (e.g. "DE89370400440532013000")
     * @param string $holderName Account holder full name
     * @return array ['success' => bool, 'mandate_id' => string|null, 'client_secret' => string|null, 'error' => string|null]
     */
    public static function processSepaPayment(int $orderId, float $amount, string $iban, string $holderName): array {
        try {
            $stripe = self::buildStripeClient();

            // 1. Create a Stripe customer so the mandate is attached to a named entity
            $customer = $stripe->customers->create([
                'name'     => $holderName,
                'metadata' => ['shop_order_id' => $orderId],
            ]);

            // 2. Create the sepa_debit PaymentMethod with the provided IBAN
            $paymentMethod = $stripe->paymentMethods->create([
                'type'       => 'sepa_debit',
                'sepa_debit' => ['iban' => $iban],
                'billing_details' => [
                    'name' => $holderName,
                ],
            ]);

            // 3. Create and immediately confirm the PaymentIntent
            $amountCents = (int) round($amount * 100);
            $intent = $stripe->paymentIntents->create([
                'amount'               => $amountCents,
                'currency'             => 'eur',
                'customer'             => $customer->id,
                'payment_method'       => $paymentMethod->id,
                'payment_method_types' => ['sepa_debit'],
                'confirm'              => true,
                'mandate_data'         => [
                    'customer_acceptance' => [
                        'type'   => 'online',
                        'online' => [
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        ],
                    ],
                ],
                'metadata' => ['shop_order_id' => $orderId],
            ]);

            $mandateId = null;
            if (!empty($intent->payment_method_options->sepa_debit->mandate_options)) {
                $mandateId = $intent->payment_method_options->sepa_debit->mandate_options->reference ?? null;
            }

            // SEPA debit payments are often 'processing' (async bank confirmation)
            if (in_array($intent->status, ['succeeded', 'processing'], true)) {
                self::markOrderPaid($orderId);
                self::writeAuditLog(0, 'shop_payment_completed', 'shop_order', $orderId,
                    "SEPA-Lastschrift via Stripe eingeleitet: PaymentIntent {$intent->id}, Status: {$intent->status}");

                return [
                    'success'       => true,
                    'mandate_id'    => $mandateId,
                    'client_secret' => $intent->client_secret,
                    'error'         => null,
                ];
            }

            return [
                'success'       => false,
                'mandate_id'    => $mandateId,
                'client_secret' => $intent->client_secret,
                'error'         => "Stripe-Status unbekannt: {$intent->status}",
            ];
        } catch (\Exception $e) {
            error_log("ShopPaymentService::processSepaPayment – Fehler bei Bestellung #{$orderId}: " . $e->getMessage());
            return [
                'success'       => false,
                'mandate_id'    => null,
                'client_secret' => null,
                'error'         => 'SEPA-Zahlung konnte nicht verarbeitet werden: ' . $e->getMessage(),
            ];
        }
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
    // Webhook handling
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming webhook from PayPal or Stripe.
     *
     * Detects the payment provider from request headers, verifies the signature,
     * and – on a successful payment event – sets payment_status = 'paid' in
     * shop_orders via markOrderPaid().
     *
     * PayPal:  listens for PAYMENT.CAPTURE.COMPLETED
     *          Verification via PayPal's verify-webhook-signature API.
     *          Requires PAYPAL_WEBHOOK_ID in .env.
     *
     * Stripe:  listens for payment_intent.succeeded
     *          Verification via Stripe\Webhook::constructEvent().
     *          Requires STRIPE_WEBHOOK_SECRET in .env.
     *
     * @return void  Sends HTTP 200 / 400 and exits.
     */
    public static function handleWebhook(): void {
        $rawBody = (string) file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];

        // Normalise header names to lowercase for reliable lookup
        $headersLower = array_change_key_case($headers, CASE_LOWER);

        if (isset($headersLower['stripe-signature'])) {
            self::handleStripeWebhook($rawBody, $headersLower['stripe-signature']);
            return;
        }

        if (isset($headersLower['paypal-transmission-id'])) {
            self::handlePayPalWebhook($rawBody, $headersLower);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown webhook source']);
        exit;
    }

    /**
     * Process a verified Stripe webhook payload.
     *
     * @param string $rawBody     Raw request body
     * @param string $sigHeader   Value of the Stripe-Signature header
     * @return void
     */
    private static function handleStripeWebhook(string $rawBody, string $sigHeader): void {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $webhookSecret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log("ShopPaymentService::handleStripeWebhook – ungültige Signatur: " . $e->getMessage());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid Stripe signature']);
            exit;
        }

        if ($event->type === 'payment_intent.succeeded') {
            $intent  = $event->data->object;
            $orderId = (int) ($intent->metadata->shop_order_id ?? 0);

            if ($orderId > 0) {
                self::markOrderPaid($orderId);
                self::writeAuditLog(0, 'shop_payment_completed', 'shop_order', $orderId,
                    "Stripe Webhook: PaymentIntent {$intent->id} succeeded");
            }
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    /**
     * Process a verified PayPal webhook payload.
     *
     * @param string $rawBody      Raw request body
     * @param array  $headers      Lowercase-keyed request headers
     * @return void
     */
    private static function handlePayPalWebhook(string $rawBody, array $headers): void {
        $webhookId = $_ENV['PAYPAL_WEBHOOK_ID'] ?? getenv('PAYPAL_WEBHOOK_ID') ?? '';

        if ($webhookId !== '' && !self::verifyPayPalWebhookSignature($rawBody, $headers, $webhookId)) {
            error_log("ShopPaymentService::handlePayPalWebhook – ungültige Signatur");
            http_response_code(400);
            echo json_encode(['error' => 'Invalid PayPal signature']);
            exit;
        }

        $event     = json_decode($rawBody, true) ?? [];
        $eventType = $event['event_type'] ?? '';

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = $event['resource'] ?? [];
            // custom_id is set to the internal order ID when the PayPal order is created
            $orderId  = (int) ($resource['custom_id'] ?? 0);

            if ($orderId > 0) {
                self::markOrderPaid($orderId);
                self::writeAuditLog(0, 'shop_payment_completed', 'shop_order', $orderId,
                    "PayPal Webhook: Capture {$resource['id']} completed");
            }
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
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
            $clientId     = $_ENV['PAYPAL_CLIENT_ID']     ?? getenv('PAYPAL_CLIENT_ID')     ?? '';
            $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'] ?? getenv('PAYPAL_CLIENT_SECRET') ?? '';
            $environment  = strtolower($_ENV['PAYPAL_ENVIRONMENT'] ?? getenv('PAYPAL_ENVIRONMENT') ?? 'sandbox');

            $baseUrl  = ($environment === 'production')
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            // 1. Obtain an access token via GuzzleHTTP
            $client = new \GuzzleHttp\Client();

            $tokenRes = $client->post("{$baseUrl}/v1/oauth2/token", [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);
            $tokenData   = json_decode((string) $tokenRes->getBody(), true);
            $accessToken = $tokenData['access_token'] ?? '';

            if ($accessToken === '') {
                return false;
            }

            // 2. Call verify-webhook-signature
            $verifyPayload = [
                'auth_algo'         => $headers['paypal-auth-algo']         ?? '',
                'cert_url'          => $headers['paypal-cert-url']          ?? '',
                'transmission_id'   => $headers['paypal-transmission-id']   ?? '',
                'transmission_sig'  => $headers['paypal-transmission-sig']  ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id'        => $webhookId,
                'webhook_event'     => json_decode($rawBody, true) ?? [],
            ];

            $verifyRes = $client->post("{$baseUrl}/v1/notifications/verify-webhook-signature", [
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
