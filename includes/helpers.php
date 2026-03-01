<?php
/**
 * Helper Functions
 */

/**
 * Initialize PHP session with secure parameters
 * Only starts the session if it has not been started yet
 */
function init_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Get base URL path
 */
function getBasePath() {
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
}

/**
 * Generate URL relative to document root using BASE_URL
 * Uses BASE_URL constant for robust URL generation regardless of subdirectory depth
 */
function url($path) {
    // Remove trailing slashes from BASE_URL
    $baseUrl = rtrim(BASE_URL, '/');
    
    // Remove leading slashes from path
    $path = ltrim($path, '/');
    
    // Combine with exactly one slash
    return $baseUrl . '/' . $path;
}

/**
 * Redirect helper
 */
function redirect($path, $absolute = false) {
    if ($absolute) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return number_format($amount, 2, ',', '.') . ' €';
}

/**
 * Format date
 */
function formatDate($date, $format = 'd.m.Y') {
    if (empty($date)) return '-';
    return date($format, is_numeric($date) ? $date : strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($date, $format = 'd.m.Y H:i') {
    if (empty($date)) return '-';
    return date($format, is_numeric($date) ? $date : strtotime($date));
}

/**
 * Format name from Entra ID (e.g., "tom.lehmann" -> "Tom Lehmann")
 * Replaces dots with spaces and capitalizes first letters of each word
 * 
 * Note: This function is idempotent and safe to apply to any name for display purposes.
 * It's designed for Entra ID names that may use lowercase with dots (e.g., "tom.lehmann"),
 * but can be safely applied to names already in proper format.
 * 
 * Limitation: Special name patterns like "McDonald" will become "Mcdonald" and 
 * "O'Brien" will become "O'brien". This is acceptable for Entra ID names which 
 * typically use simple lowercase format.
 * 
 * @param string $name The name to format
 * @return string The formatted name
 */
function formatEntraName($name) {
    if (empty($name)) {
        return '';
    }
    
    // Replace dots with spaces
    $name = str_replace('.', ' ', $name);
    
    // Convert to lowercase first, then capitalize first letter of each word
    $name = mb_strtolower($name, 'UTF-8');
    return ucwords($name);
}

/**
 * Escape HTML
 */
function e($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Check if current page is active
 */
function isActive($page) {
    return strpos($_SERVER['REQUEST_URI'], $page) !== false ? 'active' : '';
}

/**
 * Generate asset URL with BASE_URL
 * Ensures exactly one slash between BASE_URL and path
 */
function asset_url($path) {
    // Remove trailing slashes from BASE_URL
    $baseUrl = rtrim(BASE_URL, '/');
    
    // Remove leading slashes from path
    $path = ltrim($path, '/');
    
    // Combine with exactly one slash
    return $baseUrl . '/' . $path;
}

/**
 * Generate asset path with BASE_URL
 * Ensures no double slash by using rtrim on BASE_URL
 * This is an alias for asset_url() for convenience
 */
function asset($path) {
    return asset_url($path);
}

/**
 * Translate role from English to German
 * All board sub-roles (vorstand_*) are displayed as 'Vorstand'
 * 
 * @param string $role Role identifier
 * @return string German translation of the role
 */
function translateRole($role) {
    $roleTranslations = [
        'admin' => 'Administrator',
        'board_finance' => 'Vorstand Finanzen und Recht',
        'board_internal' => 'Vorstand Intern',
        'board_external' => 'Vorstand Extern',
        'head' => 'Ressortleiter',
        'member' => 'Mitglied',
        'alumni' => 'Alumni',
        'candidate' => 'Anwärter',
        'alumni_board' => 'Alumni-Vorstand',
        'alumni_auditor' => 'Alumni-Finanzprüfer',
        'honorary_member' => 'Ehrenmitglied',
        'manager' => 'Ressortleiter'
    ];
    
    return $roleTranslations[$role] ?? ucfirst($role);
}

/**
 * Translate Azure/Entra ID role to German display name
 * Maps the original Azure role names to their German equivalents
 * 
 * @param string $azureRole Azure role identifier (e.g., 'anwaerter', 'mitglied')
 * @return string German display name
 */
function translateAzureRole($azureRole) {
    $azureRoleTranslations = [
        'anwaerter' => 'Anwärter',
        'mitglied' => 'Mitglied',
        'ressortleiter' => 'Ressortleiter',
        'vorstand_finanzen' => 'Vorstand Finanzen und Recht',
        'vorstand_intern' => 'Vorstand Intern',
        'vorstand_extern' => 'Vorstand Extern',
        'alumni' => 'Alumni',
        'alumni_vorstand' => 'Alumni-Vorstand',
        'alumni_finanz' => 'Alumni-Finanzprüfer',
        'ehrenmitglied' => 'Ehrenmitglied'
    ];
    
    // If role not found in mapping, log it for manual addition and return formatted version
    if (!isset($azureRoleTranslations[$azureRole])) {
        error_log("Unknown Azure role encountered: '$azureRole'. Consider adding translation to translateAzureRole()");
        return ucfirst(str_replace('_', ' ', $azureRole));
    }
    
    return $azureRoleTranslations[$azureRole];
}

/**
 * Check if role is an active member role
 * Active member roles: candidate, member, head, board (and board variants)
 * 
 * Note: This matches Auth::BOARD_ROLES plus candidate, member, head.
 * Keep this in sync with Member::ACTIVE_ROLES constant.
 * 
 * @param string $role Role identifier
 * @return bool True if role is an active member role
 */
function isMemberRole($role) {
    // Active roles: board roles + candidate, member, head
    // Matches Member::ACTIVE_ROLES constant
    return in_array($role, ['candidate', 'member', 'head', 'board_finance', 'board_internal', 'board_external']);
}

/**
 * Check if role is an alumni role
 * Alumni roles: alumni, alumni_board, honorary_member
 * 
 * @param string $role Role identifier
 * @return bool True if role is an alumni role
 */
function isAlumniRole($role) {
    return in_array($role, ['alumni', 'alumni_board', 'honorary_member']);
}

/**
 * Extract initials from a full name string
 * Returns the first letter of each of the first two name parts, uppercased
 * Example: "Tom Lehmann" -> "TL"
 *
 * @param string $name Full name (e.g. "Tom Lehmann")
 * @return string Up to two uppercase initials, or '?' if name is empty
 */
function getInitials($name) {
    if (empty($name)) {
        return '?';
    }
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(mb_substr($part, 0, 1, 'UTF-8'));
    }
    return $initials !== '' ? $initials : '?';
}

/**
 * Extract initials from first and last name
 *
 * @param string $firstName
 * @param string $lastName
 * @return string Two-letter uppercase initials, or '?' if both are empty
 */
function getMemberInitials($firstName, $lastName) {
    return getInitials($firstName . ' ' . $lastName);
}

/**
 * Generate a consistent background color for an avatar based on a name
 * Uses a hash of the name to select from a palette of accessible colors
 *
 * @param string $name Full name or any string
 * @return string Hex color code (e.g. '#0066b3')
 */
function getAvatarColor($name) {
    $colors = [
        '#0066b3', '#4f46e5', '#0891b2', '#059669', '#d97706',
        '#dc2626', '#7c3aed', '#065f46', '#92400e', '#1e3a5f',
    ];
    if (empty($name)) {
        return $colors[0];
    }
    $index = abs(crc32($name)) % count($colors);
    return $colors[$index];
}

/**
 * Resolve a single image path: returns the path only if it is non-empty
 * AND the file actually exists on the server (path-traversal safe).
 *
 * @param string|null $imagePath Relative path to check
 * @return string|null The path if valid, null otherwise
 */
function resolveImagePath(?string $imagePath): ?string {
    if (empty($imagePath)) {
        return null;
    }
    $basePath = realpath(__DIR__ . '/..');
    if ($basePath === false) {
        return null;
    }
    $fullPath = realpath($basePath . '/' . ltrim($imagePath, '/'));
    if ($fullPath !== false && str_starts_with($fullPath, $basePath) && is_file($fullPath)) {
        return $imagePath;
    }
    return null;
}

/**
 * Get the profile image URL with fallback hierarchy:
 *  1. User-uploaded image ($imagePath from alumni_profiles.image_path)
 *  2. Entra ID cached photo ($entraPhotoPath from users.entra_photo_path)
 *  3. Default profile image (assets/img/default_profil.png)
 *
 * @param string|null $imagePath        User-uploaded image path from the database
 * @param string|null $entraPhotoPath   Entra ID cached photo path from users table
 * @return string URL-ready image path
 */
function getProfileImageUrl(?string $imagePath, ?string $entraPhotoPath = null): string {
    $default = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';

    // 1. User-uploaded image has highest priority
    $resolved = resolveImagePath($imagePath);
    if ($resolved !== null) {
        return $resolved;
    }

    // 2. Fall back to Entra ID cached photo
    $resolved = resolveImagePath($entraPhotoPath);
    if ($resolved !== null) {
        return $resolved;
    }

    // 3. Default profile image
    return $default;
}

/**
 * Extract display names from group objects
 * Groups can be either strings (legacy format) or objects with id and displayName
 * 
 * @param array $groups Array of groups (can be strings or objects)
 * @return array Array of display names
 */
function extractGroupDisplayNames($groups) {
    if (!is_array($groups)) {
        return [];
    }
    
    return array_filter(array_map(function($group) {
        return is_array($group) && isset($group['displayName']) ? $group['displayName'] : $group;
    }, $groups));
}

