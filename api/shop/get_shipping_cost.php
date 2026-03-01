<?php

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

$country   = strtoupper(trim($_GET['country'] ?? ''));
$cartTotal = isset($_GET['cart_total']) ? (float) $_GET['cart_total'] : null;

if (!preg_match('/^[A-Z]{2}$/', $country) || $cartTotal === null || $cartTotal < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Parameter.']);
    exit;
}

$shippingCost = Shop::calculateShippingCost($country, $cartTotal);
$newTotal     = $cartTotal + $shippingCost;

echo json_encode([
    'success'               => true,
    'shipping_cost'         => $shippingCost,
    'shipping_cost_formatted' => formatCurrency($shippingCost),
    'new_total'             => $newTotal,
    'new_total_formatted'   => formatCurrency($newTotal),
]);
