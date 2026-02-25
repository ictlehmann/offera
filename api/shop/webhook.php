<?php
/**
 * Shop Payment Webhook Endpoint
 *
 * Receives webhook POST requests from PayPal and Stripe after a payment event.
 * Delegates signature verification and order status updates to ShopPaymentService.
 *
 * Register this URL in:
 *  - PayPal Developer Dashboard  → Webhooks → https://yourdomain.com/api/shop/webhook.php
 *  - Stripe Dashboard            → Webhooks → https://yourdomain.com/api/shop/webhook.php
 *
 * Required .env keys:
 *  - PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_ENVIRONMENT, PAYPAL_WEBHOOK_ID
 *  - STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/ShopPaymentService.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

ShopPaymentService::handleWebhook();
