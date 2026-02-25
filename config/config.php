<?php
ob_start(); // Output Buffering starten

// Security Headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

// Load .env file
$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if (strpos($_line, '#') === 0 || strpos($_line, '=') === false) continue;
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val);
        if (strlen($_val) >= 2 && $_val[0] === '"' && substr($_val, -1) === '"') {
            $_val = substr($_val, 1, -1);
        } elseif (strlen($_val) >= 2 && $_val[0] === "'" && substr($_val, -1) === "'") {
            $_val = substr($_val, 1, -1);
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/i', $_key) && !isset($_ENV[$_key])) {
            $_ENV[$_key] = $_val;
        }
    }
    unset($_envFile, $_line, $_key, $_val);
} else {
    unset($_envFile);
}

// Helper to read env value with default
function _env($key, $default = '') {
    if (isset($_ENV[$key])) return $_ENV[$key];
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

// Application Settings
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(_env('BASE_URL', ''), '/'));
}
define('ENVIRONMENT', _env('ENVIRONMENT', 'production'));

// Password hashing algorithm
define('HASH_ALGO', PASSWORD_BCRYPT);

// Error reporting based on environment
if (ENVIRONMENT !== 'production') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Database Settings (User DB)
define('DB_USER_HOST', _env('DB_USER_HOST', 'localhost'));
define('DB_USER_NAME', _env('DB_USER_NAME', ''));
define('DB_USER_USER', _env('DB_USER_USER', ''));
define('DB_USER_PASS', _env('DB_USER_PASS', ''));

// Database Settings (Content DB)
define('DB_CONTENT_HOST', _env('DB_CONTENT_HOST', 'localhost'));
define('DB_CONTENT_NAME', _env('DB_CONTENT_NAME', ''));
define('DB_CONTENT_USER', _env('DB_CONTENT_USER', ''));
define('DB_CONTENT_PASS', _env('DB_CONTENT_PASS', ''));

// Database Settings (Invoice/Rech DB)
define('DB_RECH_HOST', _env('DB_RECH_HOST', _env('DB_INVOICE_HOST', 'localhost')));
define('DB_RECH_PORT', _env('DB_RECH_PORT', _env('DB_INVOICE_PORT', '3306')));
define('DB_RECH_NAME', _env('DB_RECH_NAME', _env('DB_INVOICE_NAME', '')));
define('DB_RECH_USER', _env('DB_RECH_USER', _env('DB_INVOICE_USER', '')));
define('DB_RECH_PASS', _env('DB_RECH_PASS', _env('DB_INVOICE_PASS', '')));

// SMTP Settings
define('SMTP_HOST',       _env('SMTP_HOST', ''));
define('SMTP_PORT',       (int) _env('SMTP_PORT', '587'));
define('SMTP_USER',       _env('SMTP_USER', ''));
define('SMTP_PASS',       _env('SMTP_PASS', ''));
define('SMTP_FROM',       _env('SMTP_FROM', ''));
define('SMTP_FROM_EMAIL', _env('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME',  _env('SMTP_FROM_NAME', 'IBC Intranet'));

// Azure / Microsoft Entra Settings
define('AZURE_TENANT_ID',     _env('AZURE_TENANT_ID',     _env('TENANT_ID', '')));
define('AZURE_CLIENT_ID',     _env('AZURE_CLIENT_ID',     _env('CLIENT_ID', '')));
define('AZURE_CLIENT_SECRET', _env('AZURE_CLIENT_SECRET', _env('CLIENT_SECRET', '')));
define('AZURE_REDIRECT_URI',  _env('AZURE_REDIRECT_URI',  ''));

if (AZURE_CLIENT_ID === '' || AZURE_CLIENT_SECRET === '') {
    throw new \RuntimeException('Azure Konfiguration in der .env fehlt oder ist unvollständig');
}

// Legacy aliases for backward compatibility
define('TENANT_ID',    AZURE_TENANT_ID);
define('CLIENT_ID',    AZURE_CLIENT_ID);
define('CLIENT_SECRET', AZURE_CLIENT_SECRET);
define('REDIRECT_URI', AZURE_REDIRECT_URI);

// Default profile image fallback path
define('DEFAULT_PROFILE_IMAGE', 'assets/img/default_profil.png');

// Invoice Settings
define('INVOICE_NOTIFICATION_EMAIL', _env('INVOICE_NOTIFICATION_EMAIL', 'vorstand@business-consulting.de'));

// Inventory Settings
define('INVENTORY_BOARD_EMAIL', _env('INVENTORY_BOARD_EMAIL', 'vorstand@business-consulting.de'));

// EasyVerein API
define('EASYVEREIN_API_TOKEN', _env('EASYVEREIN_API_TOKEN', ''));

// Role Mapping (IDs aus Entra -> Interne Rollen)
define('ROLE_MAPPING', [
    'board_finance'   => '3ad43a76-75af-48a7-9974-7a2cf350f349',
    'board_internal'  => 'f61e99e2-2717-4aff-b3f5-ef2ec489b598',
    'board_external'  => 'bf17e26b-e5f1-4a63-ae56-91ab69ae33ca',
    'alumni_board'    => '8a45c6aa-e791-422e-b964-986d8bdd2ed8',
    'alumni_auditor'  => '39597941-0a22-4922-9587-e3d62ab986d6',
    'alumni'          => '7ffd9c73-a828-4e34-a9f4-10f4ed00f796',
    'honorary_member' => '09686b92-dbc8-4e66-a851-2dafea64df89',
    'head'            => '9456552d-0f49-42ff-bbde-495a60e61e61',
    'member'          => '70f07477-ea4e-4edc-b0e6-7e25968f16c0',
    'candidate'       => '75edcb0a-c610-4ceb-82f2-457a9dde4fc0'
]);

function isActivePath($path) {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false;
}
?>
