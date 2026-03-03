<?php
/**
 * Address Suggestion API – Server-side Nominatim proxy
 * Forwards address search queries to Nominatim (OpenStreetMap) and returns
 * geocoding results. Running the request server-side keeps the Nominatim
 * usage policy compliant (single, identifiable User-Agent) and hides the
 * third-party endpoint from the browser.
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

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

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Suchbegriff zu kurz (min. 3 Zeichen).']);
    exit;
}

// Hard-limit query length to prevent overly broad requests
if (strlen($query) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Suchbegriff zu lang.']);
    exit;
}

$nominatimUrl = 'https://nominatim.openstreetmap.org/search'
    . '?format=json'
    . '&addressdetails=1'
    . '&limit=5'
    . '&accept-language=de'
    . '&q=' . rawurlencode($query);

$context = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'header'          => "User-Agent: offera-intranet-shop/1.0\r\n"
                           . "Accept: application/json\r\n",
        'timeout'         => 5,
        'ignore_errors'   => true,
    ],
]);

$raw = @file_get_contents($nominatimUrl, false, $context);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Adressvorschläge momentan nicht verfügbar.']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Ungültige Antwort vom Geocoding-Dienst.']);
    exit;
}

echo json_encode(['success' => true, 'results' => $data]);
