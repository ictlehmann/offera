<?php
declare(strict_types=1);

/**
 * Alumni Model
 * Manages alumni profile data and operations
 */

require_once __DIR__ . '/../database.php';

class Alumni extends Database {
    
    /**
     * Get profile by primary key ID
     * 
     * @param int $id The primary key ID
     * @return array|false Profile data or false if not found
     */
    public static function getProfileById($id) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("
            SELECT id, user_id, first_name, last_name, email, secondary_email, mobile_phone, 
                   linkedin_url, xing_url, industry, company, position, 
                   study_program, semester, angestrebter_abschluss, 
                   degree, graduation_year,
                   image_path, last_verified_at, last_reminder_sent_at, created_at, updated_at
            FROM alumni_profiles 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get profile by user ID
     * 
     * @param int $userId The user ID
     * @return array|false Profile data or false if not found
     */
    public static function getProfileByUserId(int $userId) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("
            SELECT id, user_id, first_name, last_name, email, secondary_email, mobile_phone, 
                   linkedin_url, xing_url, industry, company, position, 
                   study_program, semester, angestrebter_abschluss, 
                   degree, graduation_year,
                   image_path, last_verified_at, last_reminder_sent_at, created_at, updated_at
            FROM alumni_profiles 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    /**
     * Create a new alumni profile
     * 
     * @param array $data Profile data to create
     * @return bool True on success
     * @throws Exception On database error
     */
    public static function create(array $data): bool {
        $db = Database::getContentDB();
        
        // Sanitize image_path if provided
        if (isset($data['image_path'])) {
            $data['image_path'] = self::sanitizeImagePath($data['image_path']);
        }
        
        // Required fields validation
        $requiredFields = ['user_id', 'first_name', 'last_name', 'email'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO alumni_profiles 
            (user_id, first_name, last_name, email, secondary_email, mobile_phone, 
             linkedin_url, xing_url, industry, company, position, image_path,
             study_program, semester, angestrebter_abschluss, 
             degree, graduation_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $data['user_id'],
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['secondary_email'] ?? null,
            $data['mobile_phone'] ?? null,
            $data['linkedin_url'] ?? null,
            $data['xing_url'] ?? null,
            $data['industry'] ?? null,
            $data['company'] ?? null,
            $data['position'] ?? null,
            $data['image_path'] ?? null,
            $data['study_program'] ?? null,
            $data['semester'] ?? null,
            $data['angestrebter_abschluss'] ?? null,
            $data['degree'] ?? null,
            $data['graduation_year'] ?? null
        ]);
    }
    
    /**
     * Update an existing alumni profile
     * 
     * @param int $userId The user ID
     * @param array $data Profile data to update
     * @return bool True on success
     * @throws Exception On database error
     */
    public static function update(int $userId, array $data): bool {
        // Check permissions
        require_once __DIR__ . '/../../src/Auth.php';
        if (!Auth::check()) {
            throw new Exception("Keine Berechtigung zum Aktualisieren des Alumni-Profils");
        }
        
        $currentUser = Auth::user();
        $currentRole = $currentUser['role'] ?? '';
        
        // Alumni, honorary_member, and active members (member, candidate, head) can update their own profile
        // alumni_board/alumni_auditor/board roles (all types) can update any
        if (in_array($currentRole, ['alumni', 'honorary_member', 'member', 'candidate', 'head'])) {
            if ($currentUser['id'] !== $userId) {
                throw new Exception("Keine Berechtigung zum Aktualisieren anderer Alumni-Profile");
            }
        } elseif (!in_array($currentRole, array_merge(Auth::BOARD_ROLES, ['alumni_board', 'alumni_auditor']))) {
            throw new Exception("Keine Berechtigung zum Aktualisieren des Alumni-Profils");
        }
        
        $db = Database::getContentDB();
        
        // Check if profile exists
        $checkStmt = $db->prepare("SELECT id FROM alumni_profiles WHERE user_id = ?");
        $checkStmt->execute([$userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Profil nicht gefunden");
        }
        
        // Sanitize image_path if provided
        if (isset($data['image_path'])) {
            $data['image_path'] = self::sanitizeImagePath($data['image_path']);
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'first_name', 'last_name', 'email', 'secondary_email', 'mobile_phone',
            'linkedin_url', 'xing_url', 'industry', 'company', 
            'position', 'image_path', 'study_program', 
            'semester', 'angestrebter_abschluss', 'degree', 
            'graduation_year'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return true; // No fields to update
        }
        
        $values[] = $userId;
        $sql = "UPDATE alumni_profiles SET " . implode(', ', $fields) . " WHERE user_id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Update or create profile (upsert)
     * 
     * @param int $userId The user ID
     * @param array $data Profile data to upsert
     * @return bool True on success
     * @throws Exception On database error
     */
    public static function updateOrCreateProfile(int $userId, array $data): bool {
        // Check if profile exists
        $existing = self::getProfileByUserId($userId);
        
        if ($existing) {
            return self::update($userId, $data);
        } else {
            $data['user_id'] = $userId;
            return self::create($data);
        }
    }
    
    /**
     * Sanitize image path to prevent directory traversal
     * 
     * @param string $imagePath The image path to sanitize
     * @return string Sanitized image path
     */
    private static function sanitizeImagePath(string $imagePath): string {
        // Reject paths that contain traversal attempts
        // First pattern catches standalone '..' at start, second catches '/..' or '\..'
        if (preg_match('/\.\./', $imagePath) || 
            preg_match('/[\/\\\\]\.\./', $imagePath) ||
            str_contains($imagePath, "\0") ||
            str_starts_with($imagePath, '/')) {
            // If path contains traversal attempts or null bytes, use only the basename
            $imagePath = basename($imagePath);
        }
        
        // Additional loop-based sanitization as defense-in-depth
        // Handles edge cases where basename might not catch everything
        do {
            $previousPath = $imagePath;
            $imagePath = str_replace(['../', '..\\'], '', $imagePath);
        } while ($imagePath !== $previousPath);
        
        // Ensure path starts with uploads/ if it doesn't already
        if (!str_starts_with($imagePath, 'uploads/')) {
            $imagePath = 'uploads/' . ltrim($imagePath, '/\\');
        }
        
        return $imagePath;
    }
    
    /**
     * Search profiles with filters
     * Returns ONLY profiles where the linked User has role 'alumni', 'alumni_board', or 'honorary_member'
     * 
     * @param array $filters Array of filters: search (name/position/company/industry), industry
     * @return array Array of matching profiles
     */
    public static function searchProfiles(array $filters = []): array {
        $contentDb = Database::getContentDB();
        $userDb = Database::getConnection('user');
        
        $whereClauses = [];
        $params = [];
        
        // Search term filters by: Name OR Position OR Company OR Industry
        if (!empty($filters['search'])) {
            $whereClauses[] = "(ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.position LIKE ? OR ap.company LIKE ? OR ap.industry LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Additional filter by industry (for dropdown filter)
        if (!empty($filters['industry'])) {
            $whereClauses[] = "ap.industry LIKE ?";
            $params[] = '%' . $filters['industry'] . '%';
        }
        
        // Additional filter by company (if needed)
        if (!empty($filters['company'])) {
            $whereClauses[] = "ap.company LIKE ?";
            $params[] = '%' . $filters['company'] . '%';
        }
        
        $whereSQL = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        
        // Fetch alumni profiles from content DB (no cross-DB queries)
        $sql = "
            SELECT ap.id, ap.user_id, ap.first_name, ap.last_name, ap.email, ap.mobile_phone, 
                   ap.linkedin_url, ap.xing_url, ap.industry, ap.company, ap.position, 
                   ap.study_program, ap.semester, ap.angestrebter_abschluss, 
                   ap.degree, ap.graduation_year,
                   ap.image_path, ap.last_verified_at, ap.last_reminder_sent_at, ap.created_at, ap.updated_at
            FROM alumni_profiles ap" . $whereSQL . "
            ORDER BY ap.last_name ASC, ap.first_name ASC
        ";
        
        $stmt = $contentDb->prepare($sql);
        $stmt->execute($params);
        $profiles = $stmt->fetchAll();
        
        // Filter profiles by user role (alumni, alumni_board, or honorary_member only)
        // Fetch all user roles in a single query to avoid N+1 problem
        $result = [];
        
        if (!empty($profiles)) {
            $userIds = array_column($profiles, 'user_id');
            
            try {
                require_once __DIR__ . '/../../src/Auth.php';
                
                // Fetch all user roles and entra_roles in a single query
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $userStmt = $userDb->prepare("SELECT id, role, entra_roles FROM users WHERE id IN ($placeholders)");
                $userStmt->execute($userIds);
                $userDataMap = [];
                foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $userDataMap[$row['id']] = $row;
                }
                
                // Filter profiles by role and resolve display_role from Entra data
                foreach ($profiles as $profile) {
                    $userId = $profile['user_id'];
                    $userData = $userDataMap[$userId] ?? null;
                    $userRole = $userData['role'] ?? null;
                    
                    // Only include profiles where user has role 'alumni', 'alumni_board', or 'honorary_member'
                    if (in_array($userRole, ['alumni', 'alumni_board', 'honorary_member'])) {
                        $profile['role'] = $userRole;
                        
                        // Resolve display_role: prefer Entra display names, fall back to role label
                        $displayRole = null;
                        $entraRolesJson = $userData['entra_roles'] ?? null;
                        $profile['entra_roles'] = $entraRolesJson;
                        if (!empty($entraRolesJson)) {
                            $entraRolesArray = json_decode($entraRolesJson, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($entraRolesArray) && !empty($entraRolesArray)) {
                                $displayNames = [];
                                foreach ($entraRolesArray as $entraRole) {
                                    if (is_array($entraRole) && isset($entraRole['displayName'])) {
                                        $displayNames[] = $entraRole['displayName'];
                                    } elseif (is_array($entraRole) && isset($entraRole['id'])) {
                                        $displayNames[] = Auth::getRoleLabel($entraRole['id']);
                                    } elseif (is_string($entraRole)) {
                                        $displayNames[] = Auth::getRoleLabel($entraRole);
                                    }
                                }
                                if (!empty($displayNames)) {
                                    $displayRole = implode(', ', $displayNames);
                                }
                            }
                        }
                        $profile['display_role'] = $displayRole ?? Auth::getRoleLabel($userRole);
                        
                        $result[] = $profile;
                    }
                }
            } catch (Exception $e) {
                // Log error but continue
                error_log("Error checking user roles: " . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    /**
     * Get all unique industries for filter dropdown
     * 
     * @return array Array of unique industry names
     */
    public static function getAllIndustries(): array {
        $db = Database::getContentDB();
        $stmt = $db->query("
            SELECT DISTINCT industry 
            FROM alumni_profiles 
            WHERE industry IS NOT NULL AND industry != ''
            ORDER BY industry ASC
        ");
        
        $industries = [];
        while ($row = $stmt->fetch()) {
            $industries[] = $row['industry'];
        }
        
        return $industries;
    }
    
    /**
     * Get profiles where updated_at is older than specified months
     * Used by email bot to send verification reminders
     * Only returns profiles for users with role 'alumni' or 'alumni_board'
     * 
     * @param int $months Number of months (default: 12)
     * @return array Array of outdated profiles
     */
    public static function getOutdatedProfiles(int $months = 12): array {
        $contentDb = Database::getContentDB();
        $userDb = Database::getConnection('user');
        
        // Fetch profiles where updated_at is older than specified months
        // AND last reminder was either never sent OR sent more than 12 months ago (spam protection)
        $stmt = $contentDb->prepare("
            SELECT id, user_id, first_name, last_name, email, mobile_phone, 
                   linkedin_url, xing_url, industry, company, position, 
                   study_program, semester, angestrebter_abschluss, 
                   degree, graduation_year,
                   image_path, last_verified_at, last_reminder_sent_at, created_at, updated_at
            FROM alumni_profiles 
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? MONTH)
              AND (last_reminder_sent_at IS NULL OR last_reminder_sent_at < DATE_SUB(NOW(), INTERVAL 12 MONTH))
            ORDER BY updated_at ASC
        ");
        $stmt->execute([$months]);
        $profiles = $stmt->fetchAll();
        
        // Filter profiles by user role (alumni or alumni_board only)
        $result = [];
        
        if (!empty($profiles)) {
            $userIds = array_column($profiles, 'user_id');
            
            try {
                // Fetch all user roles in a single query
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $userStmt = $userDb->prepare("SELECT id, role FROM users WHERE id IN ($placeholders)");
                $userStmt->execute($userIds);
                $userRoles = $userStmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => role mapping
                
                // Filter profiles by role - only include 'alumni' or 'alumni_board'
                foreach ($profiles as $profile) {
                    $userId = $profile['user_id'];
                    $userRole = $userRoles[$userId] ?? null;
                    
                    if (in_array($userRole, ['alumni', 'alumni_board'])) {
                        $result[] = $profile;
                    }
                }
            } catch (Exception $e) {
                // Log error but continue
                error_log("Error checking user roles in getOutdatedProfiles: " . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    /**
     * Verify profile by updating last_verified_at to current timestamp
     * 
     * @param int $userId The user ID
     * @return bool True on success
     */
    public static function verifyProfile(int $userId): bool {
        $db = Database::getContentDB();
        $stmt = $db->prepare("
            UPDATE alumni_profiles 
            SET last_verified_at = NOW() 
            WHERE user_id = ?
        ");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Mark that a reminder email was sent to this user
     * 
     * @param int $userId The user ID
     * @return bool True on success
     */
    public static function markReminderSent(int $userId): bool {
        $db = Database::getContentDB();
        $stmt = $db->prepare("
            UPDATE alumni_profiles 
            SET last_reminder_sent_at = NOW() 
            WHERE user_id = ?
        ");
        return $stmt->execute([$userId]);
    }
}
