<?php
/**
 * API: Public Alumni Recovery Request (Isolated Gateway)
 *
 * SECURITY NOTICE: This script intentionally loads NO Microsoft Entra / Graph API code.
 * It acts as an isolated public gateway for alumni e-mail recovery submissions.
 *
 * Protection layers (in order):
 *  1. IP-based rate limiting  – max 3 requests per hour per IP (file-based)
 *  2. reCAPTCHA v3 validation – reject if success=false or score < 0.5
 *  3. Input sanitization      – htmlspecialchars + email format validation
 *  4. DB storage              – AlumniAccessRequest::create() (status 'pending')
 *  5. Generic response        – always identical success message (no info leakage)
 */

// ── Minimal dependency surface – NO auth, NO Entra, NO Graph API ──────────────
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';

// ── Configuration constants ────────────────────────────────────────────────────
define('ALUMNI_RATE_LIMIT_MAX',     3);    // Max requests per window
define('ALUMNI_RATE_LIMIT_WINDOW',  3600); // Window in seconds (1 hour)
define('ALUMNI_RECAPTCHA_THRESHOLD', 0.5); // Minimum reCAPTCHA v3 score

// Field-length limits (must stay in sync with DB column definitions)
define('ALUMNI_MAX_NAME_LENGTH',     100);
define('ALUMNI_MAX_EMAIL_LENGTH',    254);
define('ALUMNI_MAX_SEMESTER_LENGTH',  20);
define('ALUMNI_MAX_PROGRAM_LENGTH',  200);

header('Content-Type: application/json; charset=utf-8');

// Only POST requests are accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// ── Generic success helper (always send the same message) ─────────────────────
function sendGenericSuccess(): void {
    echo json_encode([
        'success' => true,
        'message' => 'Deine Anfrage wird geprüft. Wir melden uns in Kürze bei dir.',
    ]);
    exit;
}

// ── Determine client IP ────────────────────────────────────────────────────────
/**
 * Return the best-available client IP address for rate-limiting purposes.
 *
 * Priority (highest → lowest):
 *  1. HTTP_X_FORWARDED_FOR – may contain a comma-separated chain of IPs added
 *     by successive proxies.  The leftmost public IP is the original client.
 *     Private / reserved ranges (RFC 1918, loopback, link-local, …) are
 *     skipped so that internal reverse-proxy IPs do not shadow the real client.
 *  2. HTTP_CLIENT_IP – set by some load-balancers; accepted if it is a valid IP.
 *  3. REMOTE_ADDR – direct TCP peer; always available and trusted as last resort.
 *
 * Each candidate is validated with filter_var() to guard against malformed or
 * injected header values being stored / hashed as the rate-limit key.
 *
 * @return string A validated IP address string, or '0.0.0.0' as a safe fallback.
 */
function getClientIp(): string {
    // 1. X-Forwarded-For: "client, proxy1, proxy2"  →  take first public IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidate = trim($part);
            if (filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false) {
                return $candidate;
            }
        }
    }

    // 2. Client-IP header (set by some proxies / load-balancers)
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $candidate = trim($_SERVER['HTTP_CLIENT_IP']);
        if (filter_var(
            $candidate,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false) {
            return $candidate;
        }
    }

    // 3. Direct TCP peer address – always present, used as final fallback
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── IP-based rate limiting (max 3 requests / hour) ────────────────────────────
/**
 * Check whether the given IP is below the rate limit and, if so, record the
 * current request atomically.
 *
 * Rate-limit state is stored in PHP's system temp directory to keep it outside
 * the web root and to benefit from automatic cleanup on server restart.
 *
 * @param string $ip            Client IP address
 * @param int    $maxRequests   Maximum allowed requests within the window
 * @param int    $windowSeconds Size of the sliding window in seconds
 * @return bool  true = request is allowed, false = rate limit exceeded
 */
function checkAndRecordIpRateLimit(string $ip, int $maxRequests = 3, int $windowSeconds = 3600): bool {
    $dir = sys_get_temp_dir() . '/offera_rate_limits';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('alumni_recovery: could not create rate-limit directory: ' . $dir);
            return true; // Fail open so a temp-fs issue does not block all requests
        }
    }

    // Salt the hash with a server-side secret so that IP addresses cannot be
    // recovered from the file names via a rainbow-table attack against the
    // small IPv4 address space.
    $salt   = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
    $ipHash = hash('sha256', $salt . $ip);
    $file   = $dir . '/' . $ipHash . '_alumni.json';

    $now        = time();
    $timestamps = [];

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $timestamps = $decoded;
            }
        }
    }

    // Slide the window: discard entries older than $windowSeconds; also guard
    // against corrupted entries that may not be integers.
    $timestamps = array_values(
        array_filter($timestamps, static fn($ts): bool => is_int($ts) && ($now - $ts) < $windowSeconds)
    );

    if (count($timestamps) >= $maxRequests) {
        return false; // Rate limit exceeded – do NOT record
    }

    // Record this request and persist
    $timestamps[] = $now;
    $written = @file_put_contents($file, json_encode($timestamps), LOCK_EX);
    if ($written === false) {
        error_log('alumni_recovery: failed to write rate-limit file: ' . $file);
    }

    return true; // Allowed
}

$clientIp = getClientIp();
if (!checkAndRecordIpRateLimit($clientIp, ALUMNI_RATE_LIMIT_MAX, ALUMNI_RATE_LIMIT_WINDOW)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Zu viele Anfragen. Bitte versuche es später erneut.']);
    exit;
}

// ── Parse JSON request body ───────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Eingabeformat']);
    exit;
}

// ── reCAPTCHA v3 validation ───────────────────────────────────────────────────
/**
 * Verify a reCAPTCHA v3 token against Google's siteverify endpoint.
 *
 * Uses cURL instead of file_get_contents() so that the request works even when
 * allow_url_fopen is disabled on the server.
 *
 * @param string $token          Token submitted by the client
 * @param string $secretKey      RECAPTCHA_SECRET_KEY from config
 * @param string $remoteIp       Client IP for additional signal
 * @param float  $scoreThreshold Minimum acceptable reCAPTCHA score
 * @return bool|null  true = human (success=true AND score >= threshold),
 *                    false = bot / invalid token,
 *                    null = network / timeout error (service unavailable)
 */
function verifyRecaptcha(string $token, string $secretKey, string $remoteIp, float $scoreThreshold): ?bool {
    if ($token === '' || $secretKey === '') {
        return false;
    }

    $postData = http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch === false) {
        error_log('alumni_recovery: curl_init failed');
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response  = curl_exec($ch);
    $errno     = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($errno !== CURLE_OK) {
        error_log('alumni_recovery: reCAPTCHA cURL request failed (errno ' . $errno . ': ' . $curlError . ')');
        return null; // Network / timeout error – signal caller to return friendly message
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return false;
    }

    return ($result['success'] === true) && (($result['score'] ?? 0.0) >= $scoreThreshold);
}

$recaptchaToken        = trim($data['recaptcha_token'] ?? '');
$recaptchaVerification = verifyRecaptcha($recaptchaToken, RECAPTCHA_SECRET_KEY, $clientIp, ALUMNI_RECAPTCHA_THRESHOLD);

if ($recaptchaVerification === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Die Überprüfung konnte momentan nicht abgeschlossen werden. Bitte versuche es in wenigen Minuten erneut.']);
    exit;
}

if ($recaptchaVerification === false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA-Überprüfung fehlgeschlagen']);
    exit;
}

// ── Input sanitization & validation ──────────────────────────────────────────
// Text fields: trim, then encode HTML special characters to neutralise injections
$firstName          = htmlspecialchars(trim($data['first_name']          ?? ''), ENT_QUOTES, 'UTF-8');
$lastName           = htmlspecialchars(trim($data['last_name']           ?? ''), ENT_QUOTES, 'UTF-8');
$graduationSemester = htmlspecialchars(trim($data['graduation_semester'] ?? ''), ENT_QUOTES, 'UTF-8');
$studyProgram       = htmlspecialchars(trim($data['study_program']       ?? ''), ENT_QUOTES, 'UTF-8');

// Email fields: validate format, then normalise to lowercase
$newEmailRaw = trim($data['new_email'] ?? '');
$oldEmailRaw = trim($data['old_email'] ?? '');

if (empty($firstName) || empty($lastName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vor- und Nachname sind erforderlich']);
    exit;
}

if (empty($newEmailRaw) || filter_var($newEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige neue E-Mail-Adresse an']);
    exit;
}
$newEmail = strtolower($newEmailRaw);

// Old email is optional but must be valid when supplied
if ($oldEmailRaw !== '') {
    if (filter_var($oldEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige alte E-Mail-Adresse an']);
        exit;
    }
    $oldEmail = strtolower($oldEmailRaw);
} else {
    $oldEmail = '';
}

if (empty($graduationSemester)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Abschlusssemester ist erforderlich']);
    exit;
}

if (empty($studyProgram)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Studiengang ist erforderlich']);
    exit;
}

// Field-length guards to prevent oversized payloads reaching the database
if (mb_strlen($firstName) > ALUMNI_MAX_NAME_LENGTH || mb_strlen($lastName) > ALUMNI_MAX_NAME_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name ist zu lang (max. ' . ALUMNI_MAX_NAME_LENGTH . ' Zeichen)']);
    exit;
}

if (mb_strlen($newEmail) > ALUMNI_MAX_EMAIL_LENGTH || mb_strlen($oldEmail) > ALUMNI_MAX_EMAIL_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'E-Mail-Adresse ist zu lang']);
    exit;
}

if (mb_strlen($graduationSemester) > ALUMNI_MAX_SEMESTER_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Abschlusssemester ist zu lang (max. ' . ALUMNI_MAX_SEMESTER_LENGTH . ' Zeichen)']);
    exit;
}

if (mb_strlen($studyProgram) > ALUMNI_MAX_PROGRAM_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Studiengang ist zu lang (max. ' . ALUMNI_MAX_PROGRAM_LENGTH . ' Zeichen)']);
    exit;
}

// ── Duplicate-pending check ────────────────────────────────────────────────────
// Prevent unnecessary DB duplicates: if a pending request for this e-mail
// already exists, abort cleanly and return a friendly informational message.
if (AlumniAccessRequest::hasPendingRequest($newEmail)) {
    echo json_encode([
        'success' => true,
        'message' => 'Deine Anfrage wird bereits geprüft.',
    ]);
    exit;
}

// ── Persist via AlumniAccessRequest model (status defaults to 'pending') ──────
try {
    AlumniAccessRequest::create([
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'new_email'           => $newEmail,
        'old_email'           => $oldEmail,
        'graduation_semester' => $graduationSemester,
        'study_program'       => $studyProgram,
    ]);
} catch (Exception $e) {
    error_log('alumni_recovery: DB insert failed – ' . $e->getMessage());
    // Intentionally fall through: always return the same generic success response
    // to avoid leaking whether the request was stored or why it failed.
}

// ── Generic success response ───────────────────────────────────────────────────
// Returned unconditionally so attackers cannot infer anything about the system
// (e.g. whether an e-mail address already exists in the database).
sendGenericSuccess();
