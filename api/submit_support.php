<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/MailService.php';

header('Content-Type: application/json; charset=UTF-8');

// -----------------------------------------------------------------
// 1.  Authentication check
// -----------------------------------------------------------------
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt.']);
    exit;
}

// -----------------------------------------------------------------
// 2.  Read and validate POST parameters
// -----------------------------------------------------------------
$requestType = trim($_POST['request_type'] ?? '');
$message     = trim($_POST['message']      ?? '');

if ($requestType === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pflichtfelder fehlen.']);
    exit;
}

// -----------------------------------------------------------------
// 3.  Build the e-mail subject dynamically
// -----------------------------------------------------------------
$subject = 'Support-Anfrage: ' . $requestType;

// -----------------------------------------------------------------
// 4.  Compose and send the e-mail
// -----------------------------------------------------------------
$userName  = Auth::getUserName()  ?? 'Unbekannt';
$userEmail = Auth::getUserEmail() ?? '';

$body  = "Support-Anfrage eingegangen\n";
$body .= "===========================\n\n";
$body .= "Name:  {$userName}\n";
$body .= "Email: {$userEmail}\n\n";
$body .= "Anfragetyp: {$requestType}\n\n";
$body .= "Nachricht:\n{$message}\n";

$mailService = new MailService();
$from        = $userEmail !== '' ? "{$userName} <{$userEmail}>" : '';

$sent = $mailService->sendMail(
    $mailService->getSupportEmail(),
    $subject,
    $body,
    $from
);

// -----------------------------------------------------------------
// 5.  Return JSON response
// -----------------------------------------------------------------
if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Anfrage erfolgreich gesendet.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'E-Mail konnte nicht gesendet werden.']);
}
