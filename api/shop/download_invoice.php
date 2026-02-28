<?php
/**
 * Shop Invoice Download
 * Renders a shop order as a PDF invoice and streams it to the browser.
 */

require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Shop.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

AuthHandler::startSession();

// 1. Authentication check
if (!AuthHandler::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

$currentUser   = AuthHandler::getCurrentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentRole   = $currentUser['role'] ?? '';

// 2. Validate the order ID parameter
$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Bestellungs-ID']);
    exit;
}

try {
    // 3. Load order from content DB
    $contentDb = Database::getContentDB();

    $orderStmt = $contentDb->prepare("
        SELECT id, user_id, total_amount, payment_method, payment_status,
               shipping_method, shipping_cost, shipping_address, created_at
        FROM shop_orders
        WHERE id = ?
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bestellung nicht gefunden']);
        exit;
    }

    // 4. Access control: only the order owner or managers may download
    $managerRoles = Shop::MANAGER_ROLES;
    if ((int) $order['user_id'] !== $currentUserId && !in_array($currentRole, $managerRoles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
        exit;
    }

    // 5. Load full user data (first_name, last_name, email) for the recipient address
    $userDb   = Database::getUserDB();
    $userStmt = $userDb->prepare("
        SELECT first_name, last_name, email FROM users WHERE id = ?
    ");
    $userStmt->execute([(int) $order['user_id']]);
    $orderUser = $userStmt->fetch();
    if (!$orderUser) {
        $orderUser = ['first_name' => '', 'last_name' => '', 'email' => ''];
    }

    // 6. Load order items with product and variant details
    $itemsStmt = $contentDb->prepare("
        SELECT
            oi.quantity,
            oi.price_at_purchase,
            sp.name                                    AS product_name,
            sv.type                                    AS variant_type,
            sv.value                                   AS variant_value
        FROM shop_order_items oi
        JOIN shop_products sp ON sp.id = oi.product_id
        LEFT JOIN shop_variants sv ON sv.id = oi.variant_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    // 7. Compute totals
    $subtotal     = (float) $order['total_amount'] - (float) $order['shipping_cost'];
    $shippingCost = (float) $order['shipping_cost'];
    $total        = (float) $order['total_amount'];

    // 8. Format helper values
    $invoiceDate  = date('d.m.Y', strtotime($order['created_at']));
    $recipientName = trim(
        htmlspecialchars($orderUser['first_name'] ?? '') . ' ' .
        htmlspecialchars($orderUser['last_name']  ?? '')
    );
    $recipientEmail = htmlspecialchars($orderUser['email'] ?? '');

    // Institute contact details (configurable via constants / env)
    $instituteEmail   = defined('INVOICE_NOTIFICATION_EMAIL') ? INVOICE_NOTIFICATION_EMAIL : 'vorstand@business-consulting.de';
    $instituteAddress = defined('INSTITUTE_ADDRESS') ? INSTITUTE_ADDRESS : 'Musterstraße 1, 12345 Musterstadt';
    $instituteTaxNo   = defined('INSTITUTE_TAX_NO')  ? INSTITUTE_TAX_NO  : 'XX/XXX/XXXXX';

    // 9. Build items table rows
    $tableRows = '';
    foreach ($items as $pos => $item) {
        $description = htmlspecialchars($item['product_name']);
        if (!empty($item['variant_type']) && !empty($item['variant_value'])) {
            $description .= ' (' . htmlspecialchars($item['variant_type']) . ': ' .
                            htmlspecialchars($item['variant_value']) . ')';
        }
        $qty       = (int) $item['quantity'];
        $unitPrice = number_format((float) $item['price_at_purchase'], 2, ',', '.');
        $lineTotal = number_format((float) $item['price_at_purchase'] * $qty, 2, ',', '.');

        $tableRows .= '
            <tr>
                <td class="pos">' . ($pos + 1) . '</td>
                <td>' . $description . '</td>
                <td class="right">' . $qty . '</td>
                <td class="right">' . $unitPrice . ' €</td>
                <td class="right">' . $lineTotal . ' €</td>
            </tr>';
    }

    // 10. Build the HTML document
    $logoAbsPath = realpath(__DIR__ . '/../../assets/img/logo.png');
    $logoSrc     = ($logoAbsPath !== false && file_exists($logoAbsPath))
        ? 'file://' . $logoAbsPath
        : '';

    $html = '<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10pt;
        color: #1a1a1a;
        padding: 20mm 20mm 30mm 25mm;
    }

    /* ── Header ─────────────────────────────── */
    .header {
        width: 100%;
        margin-bottom: 10mm;
    }
    .header-logo {
        float: right;
        max-width: 50mm;
        max-height: 20mm;
    }
    .header-left {
        float: left;
        width: 80mm;
    }
    .sender-line {
        font-size: 7pt;
        color: #888;
        border-bottom: 1px solid #ccc;
        padding-bottom: 1mm;
        margin-bottom: 3mm;
        white-space: nowrap;
        overflow: hidden;
    }
    .recipient-address {
        font-size: 10pt;
        line-height: 1.6;
    }
    .clearfix::after { content: ""; display: table; clear: both; }

    /* ── Invoice meta ────────────────────────── */
    .invoice-meta {
        clear: both;
        margin-top: 10mm;
        margin-bottom: 8mm;
    }
    .invoice-meta h1 {
        font-size: 14pt;
        font-weight: bold;
        margin-bottom: 2mm;
    }
    .invoice-meta p {
        font-size: 10pt;
        color: #444;
    }

    /* ── Items table ─────────────────────────── */
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6mm;
    }
    table.items th {
        background: #f0f0f0;
        border-bottom: 2px solid #333;
        padding: 3mm 2mm;
        font-size: 9pt;
        text-align: left;
    }
    table.items td {
        padding: 2.5mm 2mm;
        border-bottom: 1px solid #ddd;
        font-size: 9pt;
    }
    table.items .pos  { width: 8mm;  text-align: center; }
    table.items .right { text-align: right; }
    table.items th.right { text-align: right; }

    /* ── Totals ──────────────────────────────── */
    .totals {
        width: 100%;
        margin-top: 2mm;
    }
    .totals td {
        padding: 1.5mm 2mm;
        font-size: 10pt;
    }
    .totals .label { text-align: right; width: 70%; color: #444; }
    .totals .amount { text-align: right; width: 30%; }
    .totals .total-row td {
        font-weight: bold;
        font-size: 11pt;
        border-top: 2px solid #333;
        padding-top: 2mm;
    }

    /* ── Footer ──────────────────────────────── */
    .footer {
        position: fixed;
        bottom: 10mm;
        left: 25mm;
        right: 20mm;
        border-top: 1px solid #ccc;
        padding-top: 2mm;
        text-align: center;
        font-size: 7.5pt;
        color: #777;
    }
</style>
</head>
<body>

<!-- Header: logo (top right) + sender/recipient (top left) -->
<div class="header clearfix">
    <div class="header-left">
        <div class="sender-line">
            Institut f&uuml;r Business Consulting e.&nbsp;V. &middot; ' . htmlspecialchars($instituteAddress) . '
        </div>
        <div class="recipient-address">
            ' . $recipientName . '<br>
            ' . $recipientEmail . '
        </div>
    </div>
    ' . ($logoSrc !== '' ? '<img src="' . $logoSrc . '" class="header-logo" alt="Logo">' : '<div class="header-logo"></div>') . '
</div>

<!-- Invoice meta -->
<div class="invoice-meta">
    <h1>Rechnung Nr. ' . (int) $orderId . '</h1>
    <p>Rechnungsdatum: ' . $invoiceDate . '</p>
</div>

<!-- Items table -->
<table class="items">
    <thead>
        <tr>
            <th class="pos">Pos.</th>
            <th>Beschreibung</th>
            <th class="right">Menge</th>
            <th class="right">Einzelpreis</th>
            <th class="right">Gesamtpreis</th>
        </tr>
    </thead>
    <tbody>
        ' . $tableRows . '
    </tbody>
</table>

<!-- Totals (bottom right) -->
<table class="totals">
    <tr>
        <td class="label">Zwischensumme:</td>
        <td class="amount">' . number_format($subtotal, 2, ',', '.') . ' &euro;</td>
    </tr>' .
    ($shippingCost > 0.0 ? '
    <tr>
        <td class="label">Versandkosten:</td>
        <td class="amount">' . number_format($shippingCost, 2, ',', '.') . ' &euro;</td>
    </tr>' : '') . '
    <tr class="total-row">
        <td class="label">Rechnungsbetrag:</td>
        <td class="amount">' . number_format($total, 2, ',', '.') . ' &euro;</td>
    </tr>
</table>

<!-- Footer -->
<div class="footer">
    Institut f&uuml;r Business Consulting e.&nbsp;V. &nbsp;|&nbsp;
    ' . htmlspecialchars($instituteAddress) . ' &nbsp;|&nbsp;
    ' . htmlspecialchars($instituteEmail) . ' &nbsp;|&nbsp;
    St.-Nr.: ' . htmlspecialchars($instituteTaxNo) . '
</div>

</body>
</html>';

    // 11. Render with DomPDF and stream
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Arial');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Rechnung.pdf', ['Attachment' => true]);

} catch (Exception $e) {
    error_log('api/shop/download_invoice.php – ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Fehler beim Erstellen der Rechnung.']);
}
