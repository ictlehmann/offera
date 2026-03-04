<?php
/**
 * Shop Payment Webhook Endpoint
 *
 * Receives webhook POST requests from PayPal after a payment event.
 * Delegates signature verification and order status updates to ShopPaymentService.
 *
 * Register this URL in:
 *  - PayPal Developer Dashboard  → Webhooks → https://yourdomain.com/api/shop/webhook.php
 *
 * Required .env keys:
 *  - PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_ENVIRONMENT, PAYPAL_WEBHOOK_ID
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/ShopPaymentService.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Read the raw request body here so it is available before PHP can parse/consume it.
// Signature verification (e.g. PayPal) requires the unmodified raw body – $_POST must
// never be used for this purpose because PHP only populates $_POST for form-encoded
// payloads, not for JSON bodies, and the body stream is exhausted after the first read.
$payload = (string) file_get_contents('php://input');

ShopPaymentService::handleWebhook($payload);
