<?php
/**
 * Authentication Handler
 * Manages user authentication, sessions, and 2FA
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../helpers.php';

class AuthHandler {
    
    /**
     * Start secure session
     */
    public static function startSession() {
        // Set timezone at the very beginning
        date_default_timezone_set('Europe/Berlin');
        
        // Use the centralized init_session() function for secure session initialization
        init_session();
        
        // Regenerate session ID periodically to prevent session fixation
        // BUT skip regeneration during OAuth flow to preserve state parameter
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            // Only skip regeneration if OAuth state is present
            if (!isset($_SESSION['oauth2state'])) {
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
        
        // Check for session timeout (30 minutes of inactivity)
        self::checkSessionTimeout();
    }
    
    /**
     * Check if session has timed out due to inactivity
     */
    private static function checkSessionTimeout() {
        // Skip timeout check if user is not authenticated
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            return;
        }
        
        // Check if last_activity is set
        if (isset($_SESSION['last_activity'])) {
            // Calculate time difference
            $inactiveTime = time() - $_SESSION['last_activity'];
            
            // If inactive for more than 30 minutes (1800 seconds)
            if ($inactiveTime > 1800) {
                // Destroy the session
                session_unset();
                session_destroy();
                
                // Redirect to login page with timeout parameter
                // Use BASE_URL for portability across environments
                $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/auth/login.php?timeout=1' : '/pages/auth/login.php?timeout=1';
                header('Location: ' . $loginUrl);
                exit;
            }
        }
        
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
    }

    /**
     * Login user
     */
    public static function login($email, $password, $tfaCode = null) {
        $db = Database::getUserDB();
        
        // Check if user is locked out
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Log failed login attempt with IP address and User Agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            self::logSystemAction(null, 'login_failed', 'user', null, "User not found: {$email} - IP: {$ipAddress} - User Agent: {$userAgent}");
            return ['success' => false, 'message' => 'Ungültige Anmeldedaten'];
        }
        
        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'message' => "Konto gesperrt. Versuchen Sie es in $remainingTime Minuten erneut."];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Increment failed attempts
            $failedAttempts = $user['failed_login_attempts'] + 1;
            $lockedUntil = null;
            
            // Implement exponential backoff rate limiting using shared configuration
            // Lockout durations defined in config.php: RATE_LIMIT_BACKOFF
            if ($failedAttempts >= 3) {
                if (!defined('RATE_LIMIT_BACKOFF') || !defined('RATE_LIMIT_MAX_BACKOFF')) {
                    error_log('CRITICAL: Rate limiting constants not defined in config.php');
                    // Use secure fallback values
                    $lockoutTimes = [3 => 60, 4 => 120, 5 => 300, 6 => 900, 7 => 1800];
                    $maxBackoff = 3600;
                } else {
                    $lockoutTimes = RATE_LIMIT_BACKOFF;
                    $maxBackoff = RATE_LIMIT_MAX_BACKOFF;
                }
                $lockoutDuration = $lockoutTimes[$failedAttempts] ?? $maxBackoff;
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutDuration);
            }
            
            $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
            $stmt->execute([$failedAttempts, $lockedUntil, $user['id']]);
            
            // Log failed login attempt with IP address and User Agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            self::logSystemAction($user['id'], 'login_failed', 'user', $user['id'], "Invalid password - Attempt {$failedAttempts} - IP: {$ipAddress} - User Agent: {$userAgent}");
            
            return ['success' => false, 'message' => 'Ungültige Anmeldedaten'];
        }
        
        // Check 2FA if enabled
        if ($user['tfa_enabled']) {
            if ($tfaCode === null) {
                return ['success' => false, 'require_2fa' => true, 'user_id' => $user['id']];
            }
            
            require_once __DIR__ . '/GoogleAuthenticator.php';
            $ga = new PHPGangsta_GoogleAuthenticator();
            
            if (!$ga->verifyCode($user['tfa_secret'], $tfaCode, 2)) {
                // Log failed 2FA attempt with IP address and User Agent
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                self::logSystemAction($user['id'], 'login_2fa_failed', 'user', $user['id'], "Invalid 2FA code - IP: {$ipAddress} - User Agent: {$userAgent}");
                return ['success' => false, 'message' => 'Ungültiger 2FA-Code'];
            }
        }
        
        // Reset failed attempts and update last login
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Initialize session
        self::startSession();
        
        // Regenerate session ID to prevent session fixation attacks
        // This must be called after session is started but before setting user-specific session data
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time(); // Initialize activity timestamp
        $_SESSION['profile_incomplete'] = !$user['profile_complete'];
        
        self::logSystemAction($user['id'], 'login_success', 'user', $user['id'], 'Successful login');
        
        return ['success' => true, 'user' => $user];
    }

    /**
     * Logout user
     */
    public static function logout() {
        self::startSession();
        
        if (isset($_SESSION['user_id'])) {
            self::logSystemAction($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id'], 'User logged out');
        }
        
        session_destroy();
        session_unset();
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated() {
        self::startSession();
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    /**
     * Get current user
     */
    public static function getCurrentUser() {
        self::startSession();
        if (self::isAuthenticated()) {
            return User::getById($_SESSION['user_id']);
        }
        return null;
    }

    /**
     * Check if user has permission
     */
    public static function hasPermission($requiredRole) {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        // Role hierarchy: alumni and member have read-only access (level 1)
        // head can edit inventory (level 2)
        // board roles and alumni_board have full board access (level 3)
        // Note: 'admin', 'board', and 'manager' kept for backward compatibility with legacy code paths.
        // 'manager' is DEPRECATED in favor of 'head' but kept for existing users
        // 'board' is a placeholder level 3 role used for permission checks
        // 'admin' is DEPRECATED and not assignable to new users
        $roleHierarchy = [
            'candidate' => 1,
            'alumni' => 1, 
            'member' => 1,
            'honorary_member' => 1,
            'manager' => 2,  // DEPRECATED: Use 'head' instead. Kept for existing users.
            'head' => 2,
            'alumni_board' => 3,
            'alumni_auditor' => 3,  // Same level as alumni_board
            'board_finance' => 3,
            'board_internal' => 3,
            'board_external' => 3,
            'board' => 3,  // DEPRECATED: Placeholder for backward compatibility checks
            'admin' => 3  // DEPRECATED: Keep for backward compatibility only. Not assignable to new users.
        ];
        $userRole = $_SESSION['user_role'];
        
        // Check if user role exists in hierarchy
        if (!isset($roleHierarchy[$userRole]) || !isset($roleHierarchy[$requiredRole])) {
            return false;
        }
        
        return $roleHierarchy[$userRole] >= $roleHierarchy[$requiredRole];
    }

    /**
     * Check if user has specific role (exact match, not hierarchical)
     * Special case: 'admin' and 'board' role checks return true for board users
     * 
     * @param string $role Required role
     * @return bool True if user has exact role
     */
    public static function hasRole($role) {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        // Special case: 'admin' maps to board_finance for backward compatibility
        if ($role === 'admin') {
            return Auth::isAdmin();
        }
        
        // Special case: 'board' maps to any board role for backward compatibility
        if ($role === 'board') {
            return Auth::isBoard();
        }
        
        return $userRole === $role;
    }

    /**
     * Check if user is admin (general system access for Logs, Stats, User Management)
     * 
     * @return bool True if user has any board role or alumni_board or alumni_auditor
     */
    public static function isAdmin() {
        return Auth::canManageUsers();
    }

    /**
     * Require admin privileges (any board role)
     * Redirects to login if not authorized
     */
    public static function requireAdmin() {
        if (!self::isAdmin()) {
            $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/auth/login.php' : '/pages/auth/login.php';
            header('Location: ' . $loginUrl);
            exit;
        }
    }
    
    /**
     * Check if user is a board member (any board role)
     * 
     * @return bool True if user has any board role
     */
    public static function isBoard() {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, Auth::BOARD_ROLES);
    }
    
    /**
     * Check if user can manage invoices
     * 
     * @return bool True if user is board_finance
     */
    public static function canManageInvoices() {
        self::startSession();
        if (!self::isAuthenticated()) {
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
        return Auth::canManageUsers();
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
     * Check if alumni user is validated
     * Alumni users need board approval before accessing internal alumni network data
     */
    public static function isAlumniValidated() {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $user = self::getCurrentUser();
        if (!$user || $user['role'] !== 'alumni') {
            return true; // Non-alumni users are always "validated"
        }
        
        return $user['is_alumni_validated'] == 1;
    }

    /**
     * Log system action
     */
    public static function logSystemAction($userId, $action, $entityType = null, $entityId = null, $details = null) {
        try {
            $db = Database::getContentDB();
            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log system action: " . $e->getMessage());
        }
    }

    /**
     * Initiate Microsoft Entra ID OAuth login
     */
    public static function initiateMicrosoftLogin() {
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        self::startSession();
        
        // Load credentials from configuration constants
        $clientId = defined('CLIENT_ID') ? CLIENT_ID : '';
        $clientSecret = defined('CLIENT_SECRET') ? CLIENT_SECRET : '';
        $redirectUri = defined('REDIRECT_URI') ? REDIRECT_URI : '';
        $tenantId = defined('TENANT_ID') ? TENANT_ID : '';
        
        // Validate required environment variables
        if (empty($clientId) || empty($clientSecret) || empty($redirectUri) || empty($tenantId)) {
            throw new Exception('Missing Azure OAuth configuration');
        }
        
        // Initialize GenericProvider with Azure endpoints using config constants
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'redirectUri'             => $redirectUri,
            'urlAuthorize'            => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
        ]);
        
        // Generate authorization URL with required scopes (space-separated as required by Microsoft OAuth v2.0)
        $authorizationUrl = $provider->getAuthorizationUrl([
            'scope' => 'openid profile email offline_access User.Read',
        ]);
        
        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $provider->getState();
        
        // Log state storage for debugging (log only presence, not actual value for security)
        error_log("[OAuth] State stored in session (length: " . strlen($_SESSION['oauth2state']) . ")");
        error_log("[OAuth] Session ID: " . session_id());
        
        // Ensure session is written to disk before redirect
        // This is critical for OAuth flow to preserve the state parameter
        session_write_close();
        
        // Redirect to authorization URL
        header('Location: ' . $authorizationUrl);
        exit;
    }

    /**
     * Handle Microsoft Entra ID OAuth callback
     */
    public static function handleMicrosoftCallback() {
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        self::startSession();
        
        // Validate state for CSRF protection
        if (!isset($_GET['state']) || !isset($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
            // Log detailed error information for debugging (without exposing actual values)
            error_log("[OAuth] State validation failed:");
            error_log("[OAuth]   - GET state present: " . (isset($_GET['state']) ? 'YES' : 'NO'));
            error_log("[OAuth]   - GET state length: " . (isset($_GET['state']) ? strlen($_GET['state']) : '0'));
            error_log("[OAuth]   - SESSION oauth2state present: " . (isset($_SESSION['oauth2state']) ? 'YES' : 'NO'));
            error_log("[OAuth]   - SESSION oauth2state length: " . (isset($_SESSION['oauth2state']) ? strlen($_SESSION['oauth2state']) : '0'));
            error_log("[OAuth]   - Session ID: " . session_id());
            unset($_SESSION['oauth2state']);
            throw new Exception('Invalid state parameter');
        }
        
        // Clear state
        unset($_SESSION['oauth2state']);
        
        // Check for error
        if (isset($_GET['error'])) {
            throw new Exception('OAuth error: ' . ($_GET['error_description'] ?? $_GET['error']));
        }
        
        // Check for authorization code
        if (!isset($_GET['code'])) {
            throw new Exception('No authorization code received');
        }
        
        // Load credentials from configuration constants
        $clientId = defined('CLIENT_ID') ? CLIENT_ID : '';
        $clientSecret = defined('CLIENT_SECRET') ? CLIENT_SECRET : '';
        $redirectUri = defined('REDIRECT_URI') ? REDIRECT_URI : '';
        $tenantId = defined('TENANT_ID') ? TENANT_ID : '';
        
        // Validate required environment variables
        if (empty($clientId) || empty($clientSecret) || empty($redirectUri) || empty($tenantId)) {
            throw new Exception('Missing Azure OAuth configuration');
        }
        
        // Initialize GenericProvider with Azure endpoints using config constants
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'redirectUri'             => $redirectUri,
            'urlAuthorize'            => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
        ]);
        
        try {
            // Get access token using authorization code
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);
            
            // Get resource owner (user) details
            $resourceOwner = $provider->getResourceOwner($token);
            
            // Get user claims (including roles)
            $claims = $resourceOwner->toArray();
            
            // Extract azure_oid from oid or sub claim
            $azureOid = $claims['oid'] ?? $claims['sub'] ?? null;
            
            // Look up user in local database by azure_oid
            $db = Database::getUserDB();
            $existingUser = null;
            if ($azureOid) {
                $stmt = $db->prepare("SELECT * FROM users WHERE azure_oid = ?");
                $stmt->execute([$azureOid]);
                $existingUser = $stmt->fetch() ?: null;
            }
            
            // Complete the login (role mapping, user create/update, session setup)
            self::completeMicrosoftLogin($claims, $existingUser);
            
        } catch (Exception $e) {
            self::logSystemAction(null, 'login_failed_microsoft', 'user', null, 'Microsoft login error: ' . $e->getMessage());
            throw new Exception('Failed to authenticate with Microsoft: ' . $e->getMessage());
        }
    }

    /**
     * Complete Microsoft login after access token and initial user lookup.
     * Handles role mapping, user create/update, profile sync, and session setup.
     *
     * @param array      $claims       Token claims from the resource owner
     * @param array|null $existingUser Pre-fetched user row (by azure_oid) or null
     */
    public static function completeMicrosoftLogin(array $claims, $existingUser = null) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        require_once __DIR__ . '/../services/MicrosoftGraphService.php';
        require_once __DIR__ . '/../models/Alumni.php';

        $azureRoles = $claims['roles'] ?? [];
        error_log("DEBUG AZURE ROLES FROM TOKEN: " . print_r($azureRoles, true));

        // Get Azure Object ID from claims for Microsoft Graph API calls
        $azureOid = $claims['oid'] ?? $claims['sub'] ?? null;

        // Fetch user's group memberships from Microsoft Graph API
        $entraGroups = [];
        if ($azureOid) {
            try {
                // Initialize Microsoft Graph Service (uses service account access token)
                $graphService = new MicrosoftGraphService();
                
                // Get user profile (includes groups)
                $profileData = $graphService->getUserProfile($azureOid);
                $entraGroups = $profileData['groups'] ?? [];
                
                // Debug logging for role matching
                error_log('DEBUG ENTRA - Gefundene Gruppen (Raw): ' . print_r($entraGroups, true));
                
                // Log groups for debugging
                if (!empty($entraGroups)) {
                    error_log("Microsoft Entra groups fetched for user {$azureOid}: " . json_encode($entraGroups));
                } else {
                    error_log("No Microsoft Entra groups found for user {$azureOid}");
                }
            } catch (Exception $e) {
                error_log("Failed to fetch user groups from Microsoft Graph during login: " . $e->getMessage());
                // Continue with empty groups - will use JWT roles or default
            }
        }
        
        // Define role mapping from Azure group IDs to internal role names
        // This mapping works for both:
        // 1. App Roles from JWT token (roles claim)
        // 2. Group IDs from Microsoft Entra (Graph API)
        // 
        // Start with Microsoft Entra Group IDs from global ROLE_MAPPING constant
        // Flip the mapping so Group IDs become keys and internal role names become values
        $roleMapping = array_flip(ROLE_MAPPING);
        
        // Add backward compatibility mappings for App Roles and display names
        $roleMapping = array_merge($roleMapping, [
            // Lowercase versions (for App Roles - backward compatibility)
            'anwaerter' => 'candidate',
            'mitglied' => 'member',
            'ressortleiter' => 'head',
            'vorstand_finanzen' => 'board_finance',
            'vorstand_intern' => 'board_internal',
            'vorstand_extern' => 'board_external',
            'vorstand' => 'board_internal',
            'alumni' => 'alumni',
            'alumni_vorstand' => 'alumni_board',
            'alumni_finanz' => 'alumni_auditor',
            'ehrenmitglied' => 'honorary_member',
            // Capitalized versions with underscore (for Group display names - backward compatibility)
            'Anwaerter' => 'candidate',
            'Mitglied' => 'member',
            'Ressortleiter' => 'head',
            'Vorstand_Finanzen' => 'board_finance',
            'Vorstand_Intern' => 'board_internal',
            'Vorstand_Extern' => 'board_external',
            'Vorstand' => 'board_internal',
            'Alumni' => 'alumni',
            'Alumni_Vorstand' => 'alumni_board',
            'Alumni_Finanz' => 'alumni_auditor',
            'Ehrenmitglied' => 'honorary_member',
            // Capitalized versions with space (alternative Group display names - backward compatibility)
            'Vorstand Finanzen' => 'board_finance',
            'Vorstand Finanzen und Recht' => 'board_finance',
            'Vorstand Intern' => 'board_internal',
            'Vorstand Extern' => 'board_external',
            'Alumni Vorstand' => 'alumni_board',
            'Alumni Finanz' => 'alumni_auditor'
        ]);
        
        // Debug logging for expected role keys
        error_log('DEBUG ENTRA - Erwartete Rollen-Keys: ' . print_r(array_keys($roleMapping), true));
        
        // Define role hierarchy for priority selection (higher value = higher priority)
        $roleHierarchy = [
            'candidate' => 1,
            'member' => 2,
            'head' => 3,
            'alumni' => 4,
            'honorary_member' => 5,
            'board_finance' => 6,
            'board_internal' => 7,
            'board_external' => 8,
            'alumni_board' => 9,
            'alumni_auditor' => 10
        ];
        
        // Find the role with the highest priority from all sources
        $highestPriority = 0;
        $selectedRole = 'member'; // Default to member if no valid role found
        
        // Process JWT roles (simple strings)
        foreach ($azureRoles as $roleSource) {
            // Check both exact match and lowercase match for compatibility
            $roleLower = strtolower($roleSource);
            
            if (isset($roleMapping[$roleSource])) {
                $internalRole = $roleMapping[$roleSource];
                $priority = $roleHierarchy[$internalRole] ?? 0;
                
                if ($priority > $highestPriority) {
                    $highestPriority = $priority;
                    $selectedRole = $internalRole;
                    error_log("Treffer! User erhält Rolle: " . $internalRole);
                }
            } elseif (isset($roleMapping[$roleLower])) {
                $internalRole = $roleMapping[$roleLower];
                $priority = $roleHierarchy[$internalRole] ?? 0;
                
                if ($priority > $highestPriority) {
                    $highestPriority = $priority;
                    $selectedRole = $internalRole;
                    error_log("Treffer! User erhält Rolle: " . $internalRole);
                }
            }
        }
        
        // Process Entra groups (objects with id and displayName)
        foreach ($entraGroups as $group) {
            // Handle both object and string formats for backwards compatibility
            if (is_array($group)) {
                $displayName = $group['displayName'] ?? null;
                $groupId = $group['id'] ?? null;
                
                // Check displayName against roleMapping
                if ($displayName && isset($roleMapping[$displayName])) {
                    $internalRole = $roleMapping[$displayName];
                    $priority = $roleHierarchy[$internalRole] ?? 0;
                    
                    if ($priority > $highestPriority) {
                        $highestPriority = $priority;
                        $selectedRole = $internalRole;
                        error_log("Treffer! User erhält Rolle: " . $internalRole);
                    }
                }
                
                // Check group ID against roleMapping
                if ($groupId && isset($roleMapping[$groupId])) {
                    $internalRole = $roleMapping[$groupId];
                    $priority = $roleHierarchy[$internalRole] ?? 0;
                    
                    if ($priority > $highestPriority) {
                        $highestPriority = $priority;
                        $selectedRole = $internalRole;
                        error_log("Treffer! User erhält Rolle: " . $internalRole);
                    }
                }
            } else {
                // Fallback for string format (backwards compatibility)
                $roleLower = strtolower($group);
                
                if (isset($roleMapping[$group])) {
                    $internalRole = $roleMapping[$group];
                    $priority = $roleHierarchy[$internalRole] ?? 0;
                    
                    if ($priority > $highestPriority) {
                        $highestPriority = $priority;
                        $selectedRole = $internalRole;
                        error_log("Treffer! User erhält Rolle: " . $internalRole);
                    }
                } elseif (isset($roleMapping[$roleLower])) {
                    $internalRole = $roleMapping[$roleLower];
                    $priority = $roleHierarchy[$internalRole] ?? 0;
                    
                    if ($priority > $highestPriority) {
                        $highestPriority = $priority;
                        $selectedRole = $internalRole;
                        error_log("Treffer! User erhält Rolle: " . $internalRole);
                    }
                }
            }
        }
        
        $roleName = $selectedRole;
        
        // Log the selected role for debugging
        error_log("Selected role for user {$azureOid}: {$roleName} (priority: {$highestPriority})");
        
        // Get user email from claims
        // Priority: email -> preferred_username -> upn
        $email = $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? null;
        
        if (!$email) {
            // Log available claims for debugging
            error_log('Azure AD claims received: ' . json_encode(array_keys($claims)));
            throw new Exception('Unable to retrieve user email from Azure AD claims. Expected one of: email, preferred_username, or upn');
        }
        
        // Extract first name and last name from claims
        // Standard OpenID Connect claims: given_name, family_name, name
        $firstName = $claims['given_name'] ?? $claims['givenName'] ?? null;
        $lastName = $claims['family_name'] ?? $claims['surname'] ?? null;
        
        // Format names from Entra ID (e.g., "tom.lehmann" -> "Tom Lehmann")
        if ($firstName) {
            $firstName = formatEntraName($firstName);
        }
        if ($lastName) {
            $lastName = formatEntraName($lastName);
        }
        
        // Look up user in database: if not already found by azure_oid, fall back to email
        $db = Database::getUserDB();
        if (!$existingUser) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch() ?: null;
        }

        if ($existingUser) {
            // Update existing user - update role and Azure info but keep profile_complete as is
            $userId = $existingUser['id'];
            
            // Update user table with role from Microsoft (but don't override profile_complete)
            // Also store the original Azure roles as JSON for profile display and Azure OID
            $azureRolesJson = json_encode($azureRoles);
            $stmt = $db->prepare("UPDATE users SET role = ?, azure_roles = ?, azure_oid = ?, last_login = NOW() WHERE id = ?");
            $stmt->execute([$roleName, $azureRolesJson, $azureOid, $userId]);
        } else {
            // Create new user without password (OAuth login only)
            $azureRolesJson = json_encode($azureRoles);
            
            // Use a random password hash since user will login via OAuth
            $randomPassword = password_hash(bin2hex(random_bytes(32)), HASH_ALGO);
            $isAlumniValidated = ($roleName === 'alumni') ? 0 : 1;
            // Set profile_complete=0 to force first-time profile completion
            $profileComplete = 0;
            // Enable email notifications by default
            $notifyProjects = 1;
            $notifyEvents = 1;
            
            $stmt = $db->prepare("
                INSERT INTO users (
                    email, password, role, azure_roles, azure_oid, 
                    is_alumni_validated, profile_complete, 
                    notify_new_projects, notify_new_events
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email, $randomPassword, $roleName, $azureRolesJson, $azureOid,
                $isAlumniValidated, $profileComplete,
                $notifyProjects, $notifyEvents
            ]);
            $userId = $db->lastInsertId();

            // Sync Entra data (displayName, mail, groups, role) for newly created users
            if ($azureOid) {
                self::syncEntraData($userId, $claims, $azureOid);
            }
        }
        
        // Update or create alumni profile if first_name and last_name are available
        if ($firstName && $lastName) {
            try {
                $contentDb = Database::getContentDB();
                
                // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert logic (prevents race conditions)
                $stmt = $contentDb->prepare("
                    INSERT INTO alumni_profiles (user_id, first_name, last_name, email)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        email = VALUES(email)
                ");
                $stmt->execute([$userId, $firstName, $lastName, $email]);
            } catch (Exception $e) {
                error_log("Failed to update alumni profile: " . $e->getMessage());
                // Don't throw - allow login to proceed even if profile update fails
            }
        }
        
        // Sync profile data and photo from Microsoft Graph
        try {
            // Reuse or create MicrosoftGraphService instance
            if (!isset($graphService) && $azureOid) {
                $graphService = new MicrosoftGraphService();
            }
            
            if ($azureOid && isset($graphService)) {
                // Get or reuse user profile data (job title, company, groups)
                // If we already fetched this earlier for role determination, reuse it
                if (!isset($profileData)) {
                    try {
                        $profileData = $graphService->getUserProfile($azureOid);
                    } catch (Exception $e) {
                        error_log("Failed to fetch user profile from Microsoft Graph: " . $e->getMessage());
                        $profileData = ['jobTitle' => null, 'companyName' => null, 'groups' => []];
                    }
                }
                
                // Store job title and company in users table
                $jobTitle = $profileData['jobTitle'] ?? null;
                $companyName = $profileData['companyName'] ?? null;
                $groups = $profileData['groups'] ?? [];
                
                // Convert groups array to JSON string for entra_roles
                // Groups are displayName from Microsoft Graph, already human-readable
                try {
                    $entraRolesJson = !empty($groups) ? json_encode($groups, JSON_THROW_ON_ERROR) : null;
                } catch (JsonException $e) {
                    error_log("Failed to JSON encode groups during profile sync for user ID " . intval($userId) . ": " . $e->getMessage());
                    $entraRolesJson = null; // Fallback to null if encoding fails
                }
                
                // Update user record with profile data
                $stmt = $db->prepare("UPDATE users SET job_title = ?, company = ?, entra_roles = ? WHERE id = ?");
                $stmt->execute([$jobTitle, $companyName, $entraRolesJson, $userId]);
                
                // Store Entra roles in session for display
                $_SESSION['entra_roles'] = $groups;
                
                // Get user photo from Microsoft Graph
                $photoData = $graphService->getUserPhoto($azureOid);
                
                if ($photoData) {
                    // Ensure profile_photos directory exists using realpath for security
                    $baseDir = realpath(__DIR__ . '/../../uploads');
                    if ($baseDir === false) {
                        $attemptedPath = __DIR__ . '/../../uploads';
                        throw new Exception("Base uploads directory does not exist at: {$attemptedPath}");
                    }
                    
                    $uploadDir = $baseDir . '/profile_photos';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Save photo as user_{id}.jpg
                    $filename = "user_{$userId}.jpg";
                    $filepath = $uploadDir . '/' . $filename;
                    
                    $bytesWritten = file_put_contents($filepath, $photoData);
                    if ($bytesWritten !== false) {
                        // Update alumni profile with image path using upsert logic
                        $imagePath = '/uploads/profile_photos/' . $filename;
                        
                        try {
                            $contentDb = Database::getContentDB();
                            
                            // Update image_path in profile, creating profile if we have name data
                            if ($firstName && $lastName) {
                                // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert (prevents race conditions)
                                // This handles both profile creation and update in one statement
                                $stmt = $contentDb->prepare("
                                    INSERT INTO alumni_profiles (user_id, first_name, last_name, email, image_path)
                                    VALUES (?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE 
                                        image_path = VALUES(image_path)
                                ");
                                $stmt->execute([$userId, $firstName, $lastName, $email, $imagePath]);
                            } else {
                                // Only update image_path if profile already exists (no name data to create profile)
                                $stmt = $contentDb->prepare("UPDATE alumni_profiles SET image_path = ? WHERE user_id = ?");
                                $stmt->execute([$imagePath, $userId]);
                            }
                        } catch (Exception $e) {
                            error_log("Failed to update profile image path: " . $e->getMessage());
                        }
                    } else {
                        error_log("Failed to save profile photo for user {$userId}: file_put_contents returned false");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to sync profile photo from Microsoft Graph: " . $e->getMessage());
            // Don't throw - allow login to proceed even if photo sync fails
        }
        
        // Check if profile is complete and 2FA status
        $stmt = $db->prepare("SELECT profile_complete, tfa_enabled FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userCheck = $stmt->fetch();

        // Check if 2FA is enabled - do this BEFORE setting authenticated session
        if ($userCheck && intval($userCheck['tfa_enabled']) === 1) {
            // Store pending authentication state (without granting full access)
            $_SESSION['pending_2fa_user_id'] = $userId;
            $_SESSION['pending_2fa_email'] = $email;
            $_SESSION['pending_2fa_role'] = $roleName;
            $_SESSION['pending_2fa_profile_complete'] = $userCheck['profile_complete'] ?? 1;
            
            // Log 2FA required
            self::logSystemAction($userId, 'login_2fa_required', 'user', $userId, 'Microsoft login successful, 2FA verification required');
            
            // Redirect to 2FA verification page
            $verify2faUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/auth/verify_2fa.php' : '/pages/auth/verify_2fa.php';
            header('Location: ' . $verify2faUrl);
            exit;
        }

        // Set session variables (only after confirming 2FA is not required)
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $roleName;
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        
        if ($userCheck && intval($userCheck['profile_complete']) === 0) {
            $_SESSION['profile_incomplete'] = true;
        } else {
            $_SESSION['profile_incomplete'] = false;
        }
        
        // Regenerate session ID to prevent session fixation attacks (mirrors Auth::createSession())
        session_regenerate_id(true);
        // Store current session ID in database for single-session enforcement (mirrors Auth::createSession())
        $stmt = $db->prepare("UPDATE users SET current_session_id = ? WHERE id = ?");
        $stmt->execute([session_id(), $userId]);

        // Log successful login
        self::logSystemAction($userId, 'login_success_microsoft', 'user', $userId, 'Successful Microsoft Entra ID login');
        
        // Redirect to dashboard
        $dashboardUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php';
        header('Location: ' . $dashboardUrl);
        exit;
    }

    /**
     * Sync Microsoft Entra ID data into the local users table on every login.
     *
     * Performs the following steps:
     *  1. Updates displayName (first_name, last_name) and mail from the supplied claims.
     *  2. Fetches the user's current group memberships from Microsoft Graph API.
     *  3. Overwrites the entra_roles JSON field with the current group list.
     *  4. Derives the primary local role from ROLE_MAPPING and updates it.
     *
     * @param int    $userId   Local database user ID.
     * @param array  $userData Claims array from Microsoft Entra ID / OAuth token.
     * @param string $azureOid Azure Object Identifier used for Graph API calls.
     */
    public static function syncEntraData(int $userId, array $userData, string $azureOid): void {
        require_once __DIR__ . '/../services/MicrosoftGraphService.php';

        $db = Database::getUserDB();

        // --- 1. Extract displayName (first_name, last_name) and mail from claims ---
        $firstName = $userData['given_name'] ?? $userData['givenName'] ?? null;
        $lastName  = $userData['family_name'] ?? $userData['surname'] ?? null;

        // Format names from Entra ID (e.g. "tom.lehmann" → "Tom Lehmann")
        if ($firstName) {
            $firstName = formatEntraName($firstName);
        }
        if ($lastName) {
            $lastName = formatEntraName($lastName);
        }

        // Prefer explicit email/mail over UPN which may carry an #EXT# suffix
        $mail = $userData['email'] ?? $userData['mail'] ?? $userData['preferred_username'] ?? null;

        // --- 2. Fetch current group memberships from Microsoft Graph ---
        $entraGroups = [];
        try {
            $graphService = new MicrosoftGraphService();
            $profileData  = $graphService->getUserProfile($azureOid);
            $entraGroups  = $profileData['groups'] ?? [];
            error_log(sprintf('[syncEntraData] Fetched %d groups for user %d (azure_oid: %s)', count($entraGroups), $userId, $azureOid));
        } catch (Exception $e) {
            error_log('[syncEntraData] Failed to fetch groups for user ' . $userId . ': ' . $e->getMessage());
        }

        // --- 3. Overwrite entra_roles JSON field ---
        try {
            $entraRolesJson = json_encode($entraGroups, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[syncEntraData] Failed to JSON-encode entra_groups for user ' . $userId . ': ' . $e->getMessage());
            $entraRolesJson = '[]';
        }

        // --- 4. Determine primary local role from ROLE_MAPPING ---
        $roleMapping = array_flip(ROLE_MAPPING);
        $roleHierarchy = [
            'candidate'       => 1,
            'member'          => 2,
            'head'            => 3,
            'alumni'          => 4,
            'honorary_member' => 5,
            'board_finance'   => 6,
            'board_internal'  => 7,
            'board_external'  => 8,
            'alumni_board'    => 9,
            'alumni_auditor'  => 10,
        ];

        $highestPriority = 0;
        $selectedRole    = 'member'; // Default to member when no group matches ROLE_MAPPING

        foreach ($entraGroups as $group) {
            $candidates = [];
            if (is_array($group)) {
                if (!empty($group['displayName'])) {
                    $candidates[] = $group['displayName'];
                }
                if (!empty($group['id'])) {
                    $candidates[] = $group['id'];
                }
            } else {
                $candidates[] = (string) $group;
            }

            foreach ($candidates as $key) {
                if (isset($roleMapping[$key])) {
                    $internalRole = $roleMapping[$key];
                    $priority     = $roleHierarchy[$internalRole] ?? 0;
                    if ($priority > $highestPriority) {
                        $highestPriority = $priority;
                        $selectedRole    = $internalRole;
                    }
                }
            }
        }

        // --- Build and execute UPDATE statement ---
        $setClauses = ['entra_roles = ?', 'role = ?'];
        $params     = [$entraRolesJson, $selectedRole];
        if ($firstName !== null) {
            $setClauses[] = 'first_name = ?';
            $params[]     = $firstName;
        }
        if ($lastName !== null) {
            $setClauses[] = 'last_name = ?';
            $params[]     = $lastName;
        }
        if ($mail !== null) {
            $setClauses[] = 'email = ?';
            $params[]     = $mail;
        }

        $params[] = $userId;
        $sql      = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ?';

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {
            // Log but do not abort the login if the sync update fails
            error_log('[syncEntraData] DB update failed for user ' . $userId . ': ' . $e->getMessage());
            return;
        }

        error_log(sprintf(
            '[syncEntraData] Synced user %d: role=%s, entra_roles=%d groups, first_name=%s, mail=%s',
            $userId,
            $selectedRole ?? '(unchanged)',
            count($entraGroups),
            $firstName ?? '(not updated)',
            $mail       ?? '(not updated)'
        ));
    }
}
