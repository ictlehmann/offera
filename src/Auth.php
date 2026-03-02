<?php
/**
 * Auth Class
 * Complete authentication handler with session management and auto-logout
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/helpers.php';

class Auth {
    
    /**
     * Role constants - exactly 10 allowed roles
     */
    const ROLE_ALUMNI = 'alumni';
    const ROLE_ALUMNI_AUDITOR = 'alumni_auditor';
    const ROLE_ALUMNI_BOARD = 'alumni_board';
    const ROLE_CANDIDATE = 'candidate';
    const ROLE_MEMBER = 'member';
    const ROLE_HEAD = 'head';
    const ROLE_HONORARY_MEMBER = 'honorary_member';
    const ROLE_BOARD_FINANCE = 'board_finance';
    const ROLE_BOARD_EXTERNAL = 'board_external';
    const ROLE_BOARD_INTERNAL = 'board_internal';
    
    /**
     * All valid role types in the system
     */
    const VALID_ROLES = [
        'alumni',
        'alumni_auditor',
        'alumni_board',
        'candidate',
        'member',
        'head',
        'honorary_member',
        'board_finance',
        'board_internal',
        'board_external'
    ];
    
    /**
     * Board role types (all variants)
     */
    const BOARD_ROLES = [
        'board_finance',
        'board_internal',
        'board_external'
    ];

    /**
     * Roles allowed to create polls
     */
    const POLL_CREATOR_ROLES = [
        'board_finance',
        'board_internal',
        'board_external',
        'alumni_board',
        'alumni_auditor',
        'head'
    ];

    /**
     * Whether the single-session check has already been performed for this request
     */
    private static $sessionVerified = false;

    /**
     * Check if user is authenticated and handle session timeout
     * 
     * @return bool True if authenticated
     */
    public static function check() {
        // Set timezone
        date_default_timezone_set('Europe/Berlin');
        
        // Initialize session with secure parameters
        init_session();
        
        // Check if user is authenticated
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            // If a 2FA verification is still pending, redirect to the 2FA page instead
            // of the login page so the user cannot bypass 2FA by navigating away.
            if (isset($_SESSION['pending_2fa_user_id'])) {
                $verify2faUrl = '/pages/auth/verify_2fa.php';
                if (defined('BASE_URL') && BASE_URL) {
                    $verify2faUrl = BASE_URL . $verify2faUrl;
                }
                // Avoid redirect loops when already on the verify_2fa page
                $currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
                if (strpos($currentScript, 'verify_2fa.php') === false) {
                    header('Location: ' . $verify2faUrl);
                    exit;
                }
            }
            return false;
        }
        
        // Check for session timeout (30 minutes = 1800 seconds)
        if (isset($_SESSION['last_activity'])) {
            $inactiveTime = time() - $_SESSION['last_activity'];
            
            // If inactive for more than 30 minutes
            if ($inactiveTime > 1800) {
                // Destroy session
                session_unset();
                session_destroy();
                
                // Redirect to login with timeout parameter
                $loginUrl = '/pages/auth/login.php?timeout=1';
                if (defined('BASE_URL') && BASE_URL) {
                    $loginUrl = BASE_URL . $loginUrl;
                }
                header('Location: ' . $loginUrl);
                exit;
            }
        }
        
        // Enforce single active session per user (checked once per request for performance)
        if (!self::$sessionVerified && isset($_SESSION['user_id'])) {
            self::$sessionVerified = true;
            try {
                $db = Database::getUserDB();
                $stmt = $db->prepare("SELECT current_session_id FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch();
                
                if ($row && $row['current_session_id'] !== null && $row['current_session_id'] !== session_id()) {
                    // Session ID mismatch – user logged in from another device
                    session_unset();
                    session_destroy();
                    // Clear the session cookie so the browser doesn't reuse the old session ID
                    if (isset($_COOKIE[session_name()])) {
                        setcookie(session_name(), '', time() - 42000, '/');
                    }
                    
                    $loginUrl = '/pages/auth/login.php?error=' . urlencode('Du wurdest abgemeldet, da eine neue Anmeldung an einem anderen Gerät erfolgt ist');
                    if (defined('BASE_URL') && BASE_URL) {
                        $loginUrl = BASE_URL . $loginUrl;
                    }
                    header('Location: ' . $loginUrl);
                    exit;
                }
            } catch (Exception $e) {
                error_log("Session verification failed: " . $e->getMessage());
            }
        }
        
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Verify user credentials (email and password)
     * 
     * @param string $email User email
     * @param string $password User password
     * @return array User array on success, or array with 'error' key on failure
     */
    public static function verifyCredentials($email, $password) {
        $db = Database::getUserDB();
        
        // Find user by email
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['error' => 'Ungültige Anmeldedaten'];
        }
        
        // Check if account is permanently locked
        if (isset($user['is_locked_permanently']) && $user['is_locked_permanently']) {
            return ['error' => 'Account gesperrt. Bitte Admin kontaktieren.'];
        }
        
        // Check if account is temporarily locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['error' => 'Zu viele Versuche. Wartezeit läuft.'];
        }
        
        // Check if password field exists and is valid
        if (!isset($user['password']) || !is_string($user['password'])) {
            error_log("Database error: password field missing or invalid for user ID: " . $user['id']);
            return ['error' => 'Systemfehler. Bitte Admin kontaktieren.'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Increment failed attempts
            $failedAttempts = ($user['failed_login_attempts'] ?? 0) + 1;
            $lockedUntil = null;
            $isPermanentlyLocked = 0;
            
            // Implement exponential backoff rate limiting using shared configuration
            // Lockout durations defined in config.php: RATE_LIMIT_BACKOFF
            // After 8 failed attempts: Account is permanently locked
            if ($failedAttempts >= 3) {
                if ($failedAttempts >= 8) {
                    // Permanently lock account after 8 failed attempts
                    $isPermanentlyLocked = 1;
                    $lockedUntil = null;
                } else {
                    // Exponential backoff for attempts 3-7 using shared configuration
                    if (!defined('RATE_LIMIT_BACKOFF')) {
                        error_log('CRITICAL: RATE_LIMIT_BACKOFF constant not defined in config.php');
                        // Use secure fallback values
                        $lockoutTimes = [3 => 60, 4 => 120, 5 => 300, 6 => 900, 7 => 1800];
                    } else {
                        $lockoutTimes = RATE_LIMIT_BACKOFF;
                    }
                    // Use null coalescing to safely handle missing keys
                    $lockoutDuration = $lockoutTimes[$failedAttempts] ?? end($lockoutTimes);
                    $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutDuration);
                }
            }
            
            $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ?, is_locked_permanently = ? WHERE id = ?");
            $stmt->execute([$failedAttempts, $lockedUntil, $isPermanentlyLocked, $user['id']]);
            
            return ['error' => 'Ungültige Anmeldedaten'];
        }
        
        return $user;
    }
    
    /**
     * Create session for authenticated user
     * 
     * @param array $user User data array
     * @return bool True on success
     */
    public static function createSession($user) {
        $db = Database::getUserDB();
        
        // Reset failed attempts and update last login
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Initialize session with secure parameters
        init_session();
        
        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);
        
        // Store current session ID in database for single-session enforcement
        $stmt = $db->prepare("UPDATE users SET current_session_id = ? WHERE id = ?");
        $stmt->execute([session_id(), $user['id']]);
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        
        // Set 2FA nudge if 2FA is not enabled
        if (!$user['tfa_enabled']) {
            $_SESSION['show_2fa_nudge'] = true;
        }
        
        return true;
    }
    
    /**
     * Login user with email and password
     * 
     * @param string $email User email
     * @param string $password User password
     * @param string|null $tfaCode Optional 2FA code
     * @return array Result with 'success' and 'message' keys
     */
    public static function login($email, $password, $tfaCode = null) {
        // Capture IP address and user agent for logging
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Verify credentials
        $result = self::verifyCredentials($email, $password);
        
        // Check if verification returned an error
        if (isset($result['error'])) {
            // Log failed login attempt
            self::logLoginAttempt(null, $email, 'failed', $result['error'], $ipAddress, $userAgent);
            return ['success' => false, 'message' => $result['error']];
        }
        
        $user = $result;
        
        // Check 2FA if enabled
        if ($user['tfa_enabled']) {
            if ($tfaCode === null) {
                return ['success' => false, 'require_2fa' => true, 'user_id' => $user['id']];
            }
            
            // Verify 2FA code
            require_once __DIR__ . '/../includes/handlers/GoogleAuthenticator.php';
            $ga = new PHPGangsta_GoogleAuthenticator();
            
            if (!$ga->verifyCode($user['tfa_secret'], $tfaCode, 2)) {
                // Log failed 2FA attempt
                self::logLoginAttempt($user['id'], $email, 'failed_2fa', 'Invalid 2FA code', $ipAddress, $userAgent);
                return ['success' => false, 'message' => 'Ungültiger 2FA-Code'];
            }
        }
        
        // Create session
        self::createSession($user);
        
        // Log successful login attempt
        self::logLoginAttempt($user['id'], $email, 'success', 'Login successful', $ipAddress, $userAgent);
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Logout current user
     */
    public static function logout() {
        // Initialize session with secure parameters if not already started
        init_session();
        
        // Clear current_session_id in database so no stale session ID remains
        if (isset($_SESSION['user_id'])) {
            try {
                $db = Database::getUserDB();
                $stmt = $db->prepare("UPDATE users SET current_session_id = NULL WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            } catch (Exception $e) {
                error_log("Failed to clear session ID on logout: " . $e->getMessage());
            }
        }
        
        // Clear all session data
        session_unset();
        session_destroy();
    }
    
    /**
     * Check if user has specific role(s)
     * 
     * @param string|array $role Required role or array of roles
     * @return bool True if user has the role
     */
    public static function hasRole($role) {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        // If $role is an array, check if user has any of them
        if (is_array($role)) {
            return in_array($userRole, $role);
        }
        
        // If $role is a string, check for exact match
        return $userRole === $role;
    }
    
    /**
     * Check if user has any board role
     * 
     * @return bool True if user has any board role
     */
    public static function isBoardMember() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, self::BOARD_ROLES);
    }
    
    /**
     * Check if user is admin (general system access for Logs, Stats, User Management)
     * 
     * @return bool True if user has any board role or alumni_board or alumni_auditor
     */
    public static function isAdmin() {
        return self::canManageUsers();
    }
    
    /**
     * Check if user is a board member (any board role)
     * 
     * @return bool True if user has any board role (board_finance, board_internal, board_external)
     */
    public static function isBoard() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, self::BOARD_ROLES);
    }
    
    /**
     * Check if user can manage invoices
     * 
     * @return bool True if user is board_finance
     */
    public static function canManageInvoices() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return $userRole === 'board_finance';
    }
    
    /**
     * Check if user can manage users
     * 
     * @return bool True if user has any board role, alumni_board, or alumni_auditor
     */
    public static function canManageUsers() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, array_merge(self::BOARD_ROLES, ['alumni_board', 'alumni_auditor']));
    }
    
    /**
     * Check if user can see system stats (Logs, Stats, Dashboard)
     * 
     * @return bool True if user has any board role (board_finance, board_internal, board_external)
     */
    public static function canSeeSystemStats() {
        return self::isBoard();
    }
    
    /**
     * Check if user can create complex content (Events, Projects, Polls, Blog)
     * 
     * @return bool True if user has any board role
     */
    public static function canCreateComplexContent() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, self::BOARD_ROLES);
    }
    
    /**
     * Check if user can approve inventory returns
     * 
     * @return bool True if user is board_internal, board_external, board_finance, or head
     */
    public static function canApproveReturns() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, array_merge(self::BOARD_ROLES, ['head']));
    }

    /**
     * Check if user can create polls
     * 
     * @return bool True if user has any board role, alumni_board, alumni_auditor, or head
     */
    public static function canCreatePolls() {
        if (!self::check()) {
            return false;
        }

        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, self::POLL_CREATOR_ROLES);
    }

    /**
     * Check if user can create basic content (Events, Blog)
     * 
     * @return bool True if user has any board role or is head (Ressortleiter)
     */
    public static function canCreateBasicContent() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, array_merge(self::BOARD_ROLES, ['head']));
    }
    
    /**
     * Check if user can view admin stats
     * 
     * @return bool True for all 3 Boards + alumni_board + alumni_auditor
     */
    public static function canViewAdminStats() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, array_merge(self::BOARD_ROLES, ['alumni_board', 'alumni_auditor']));
    }
    
    /**
     * Check if user can edit admin settings (legacy method - use canAccessSystemSettings instead)
     * 
     * @return bool True ONLY for the 3 Boards (board_finance, board_internal, board_external)
     */
    public static function canEditAdminSettings() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, self::BOARD_ROLES);
    }
    
    /**
     * Check if user can access system settings
     * 
     * @return bool True for board_finance, board_internal, board_external, alumni_board, and alumni_auditor
     */
    public static function canAccessSystemSettings() {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, array_merge(self::BOARD_ROLES, ['alumni_board', 'alumni_auditor']));
    }
    
    /**
     * Check if user has specific permission/role
     * 
     * @param string $role Required role
     * @return bool True if user has permission
     */
    public static function hasPermission($role) {
        if (!self::check()) {
            return false;
        }
        
        // Role hierarchy - only the 10 allowed roles
        $roleHierarchy = [
            'candidate' => 0,
            'alumni' => 1,
            'member' => 1,
            'honorary_member' => 1,
            'head' => 2,
            'alumni_board' => 3,
            'alumni_auditor' => 3,
            'board_finance' => 3,
            'board_internal' => 3,
            'board_external' => 3
        ];
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        if (!isset($roleHierarchy[$userRole]) || !isset($roleHierarchy[$role])) {
            return false;
        }
        
        return $roleHierarchy[$userRole] >= $roleHierarchy[$role];
    }
    
    /**
     * Check if user can access a specific page/menu item
     * Centralizes permission logic for sidebar menu
     * 
     * @param string $page Page identifier (e.g., 'members', 'invoices', 'ideas', 'training_requests')
     * @return bool True if user has permission to access the page
     */
    public static function canAccessPage($page) {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        // Define page access permissions
        $pagePermissions = [
            'members' => ['board_finance', 'board_internal', 'board_external', 'head', 'member', 'candidate'],
            'invoices' => ['board_finance', 'board_internal', 'board_external', 'alumni', 'alumni_board', 'alumni_auditor', 'honorary_member'],
            'ideas' => ['board_finance', 'board_internal', 'board_external', 'member', 'candidate', 'head'],
            'training_requests' => ['alumni', 'alumni_board', 'alumni_auditor'],
            'polls' => ['board_finance', 'board_internal', 'board_external', 'head', 'member', 'candidate', 'alumni', 'alumni_board', 'alumni_auditor', 'honorary_member']
        ];
        
        // Check if page exists in permissions map
        if (!isset($pagePermissions[$page])) {
            return false;
        }
        
        // Check if user's role is in the allowed roles for this page
        return in_array($userRole, $pagePermissions[$page]);
    }
    
    /**
     * Get current user ID
     * 
     * @return int|null User ID or null if not authenticated
     */
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user data
     * 
     * @return array|null User data or null if not authenticated
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        $result = $stmt->fetch();
        
        // If user not found in database (zombie session), destroy session and return null
        if ($result === false) {
            // Log zombie session detection for security monitoring
            error_log("Zombie session detected: User ID " . ($_SESSION['user_id'] ?? 'unknown') . 
                      " not found in database. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            
            self::logout();
            return null;
        }
        
        return $result;
    }
    
    /**
     * Require specific role or redirect to login
     * 
     * @param string $role Required role
     */
    public static function requireRole($role) {
        if (!self::hasPermission($role)) {
            $loginUrl = '/pages/auth/login.php';
            if (defined('BASE_URL') && BASE_URL) {
                $loginUrl = BASE_URL . $loginUrl;
            }
            header('Location: ' . $loginUrl);
            exit;
        }
    }
    
    /**
     * Create new Auth instance (for compatibility)
     * 
     * @return Auth New Auth instance
     */
    public static function getInstance() {
        return new self();
    }
    
    /**
     * Convert a username (e.g., "tom.lehmann") into a human-readable display name ("Tom Lehmann").
     * Replaces dots with spaces and capitalises each word with ucwords().
     *
     * @param string $username Username to format (e.g., the part before @ in an email address)
     * @return string Formatted display name with each word capitalised
     */
    public static function getDisplayName(string $username): string {
        return ucwords(str_replace('.', ' ', $username));
    }

    /**
     * Format a username (e.g., "tom.lehmann") into a display name ("Tom Lehmann").
     * Replaces dots with spaces and capitalizes each word.
     *
     * @param string $username Username to format (e.g., the part before @ in an email address)
     * @return string Formatted display name with each word capitalized
     * @deprecated Use getDisplayName() instead
     */
    public static function getFormattedName(string $username): string {
        return self::getDisplayName($username);
    }

    /**
     * Get German display name for a role
     * 
     * @param string $role Role internal key
     * @return string German display name
     */
    public static function getRoleLabel($role) {
        $roleLabels = [
            'alumni' => 'Alumni',
            'alumni_auditor' => 'Alumni-Finanzprüfer',
            'alumni_board' => 'Alumni-Vorstand',
            'candidate' => 'Anwärter',
            'member' => 'Mitglied',
            'head' => 'Ressortleiter',
            'honorary_member' => 'Ehrenmitglied',
            'board_finance' => 'Vorstand Finanzen und Recht',
            'board_external' => 'Vorstand Extern',
            'board_internal' => 'Vorstand Intern'
        ];
        
        // Return label if exists for internal role key
        if (isset($roleLabels[$role])) {
            return $roleLabels[$role];
        }
        
        // Check if $role is an Entra role ID (GUID) present in ROLE_MAPPING
        if (defined('ROLE_MAPPING') && is_array(ROLE_MAPPING)) {
            $reverseMapping = array_flip(ROLE_MAPPING);
            if (isset($reverseMapping[$role]) && isset($roleLabels[$reverseMapping[$role]])) {
                return $roleLabels[$reverseMapping[$role]];
            }
        }
        
        error_log("Warning: Unknown role '$role' passed to getRoleLabel()");
        return ucwords(str_replace('_', ' ', $role));
    }

    /**
     * Resolve the primary internal role key from an Entra roles JSON string.
     * Uses ROLE_MAPPING to translate the first Entra role GUID to an internal key.
     *
     * @param string|null $entraRolesJson JSON-encoded Entra roles array
     * @param string $fallbackRole Internal role key to return when Entra data is absent or unrecognised
     * @return string Internal role key (e.g. 'alumni', 'board_finance')
     */
    public static function getPrimaryEntraRoleKey(?string $entraRolesJson, string $fallbackRole): string {
        if (!empty($entraRolesJson) && defined('ROLE_MAPPING') && is_array(ROLE_MAPPING)) {
            $entraArr = json_decode($entraRolesJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($entraArr) && !empty($entraArr)) {
                $firstRole = reset($entraArr);
                $roleId = is_array($firstRole) ? ($firstRole['id'] ?? null) : (is_string($firstRole) ? $firstRole : null);
                if ($roleId !== null) {
                    $reverseMapping = array_flip(ROLE_MAPPING);
                    return $reverseMapping[$roleId] ?? $fallbackRole;
                }
            }
        }
        return $fallbackRole;
    }
    
    /**
     * Log login attempt to system_logs table
     * 
     * @param int|null $userId User ID (null if login failed before user identification)
     * @param string $email Email used for login attempt
     * @param string $status Login status ('success', 'failed', 'failed_2fa')
     * @param string $details Additional details about the login attempt
     * @param string|null $ipAddress IP address of the client
     * @param string|null $userAgent User agent string
     */
    private static function logLoginAttempt($userId, $email, $status, $details, $ipAddress, $userAgent) {
        try {
            $dbContent = Database::getContentDB();
            $stmt = $dbContent->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $action = 'login_' . $status;
            $logDetails = "Email: {$email}, Status: {$status}, Details: {$details}";
            
            $stmt->execute([
                $userId,
                $action,
                'login',
                $userId,
                $logDetails,
                $ipAddress,
                $userAgent
            ]);
        } catch (Exception $e) {
            // Log to error log if database logging fails
            error_log("Failed to log login attempt for {$email}: " . $e->getMessage());
        }
    }
}
