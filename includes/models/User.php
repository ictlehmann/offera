<?php
/**
 * User Model
 * Manages user data and operations
 */

class User {
    
    /**
     * Email change token expiration time in hours
     */
    const EMAIL_CHANGE_TOKEN_EXPIRATION_HOURS = 24;
    
    /**
     * Get user by ID
     */
    public static function getById($id) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT id, email, role, entra_roles, tfa_enabled, is_alumni_validated, last_login, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Find user by ID (alias for getById for compatibility)
     */
    public static function findById($id) {
        return self::getById($id);
    }

    /**
     * Get user by email
     */
    public static function getByEmail($email) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    /**
     * Create new user
     */
    public static function create($email, $password, $role = 'member') {
        $db = Database::getUserDB();
        $passwordHash = password_hash($password, HASH_ALGO);
        
        // Alumni users need manual board approval, so is_alumni_validated is set to FALSE (0)
        // Non-alumni users don't require validation, so it's set to TRUE (1) by default
        // This allows the isAlumniValidated() check to work correctly for all users
        $isAlumniValidated = ($role === 'alumni') ? 0 : 1;
        
        // New users need to complete their profile (first_name + last_name)
        $profileComplete = 0;
        
        $stmt = $db->prepare("INSERT INTO users (email, password, role, is_alumni_validated, profile_complete) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$email, $passwordHash, $role, $isAlumniValidated, $profileComplete]);
        
        return $db->lastInsertId();
    }

    /**
     * Update user
     */
    public static function update($id, $data) {
        $db = Database::getUserDB();
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Update user profile fields (about_me, gender, birthday)
     * @param int $id The user ID
     * @param array $data Profile data to update (about_me, gender, birthday)
     * @return bool Returns true on success
     */
    public static function updateProfile($id, $data) {
        $db = Database::getUserDB();
        $fields = [];
        $values = [];
        
        // Only allow specific profile fields to be updated
        $allowedFields = ['about_me', 'gender', 'birthday', 'show_birthday'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return true; // No fields to update
        }
        
        $values[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Delete user and associated alumni profile
     */
    public static function delete($id) {
        // Remove alumni profile from content DB first (ignore errors if not present)
        try {
            $contentDb = Database::getContentDB();
            $stmt = $contentDb->prepare("DELETE FROM alumni_profiles WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log('Failed to delete alumni profile for user ' . $id . ': ' . $e->getMessage());
        }

        $db = Database::getUserDB();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get all users
     */
    public static function getAll($role = null) {
        $db = Database::getUserDB();
        
        if ($role) {
            $stmt = $db->prepare("SELECT id, email, first_name, last_name, role, user_type, tfa_enabled, is_alumni_validated, last_login, created_at, entra_roles FROM users WHERE role = ? ORDER BY created_at DESC");
            $stmt->execute([$role]);
        } else {
            $stmt = $db->query("SELECT id, email, first_name, last_name, role, user_type, tfa_enabled, is_alumni_validated, last_login, created_at, entra_roles FROM users ORDER BY created_at DESC");
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Enable 2FA for user
     */
    public static function enable2FA($userId, $secret) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET tfa_secret = ?, tfa_enabled = 1 WHERE id = ?");
        return $stmt->execute([$secret, $userId]);
    }

    /**
     * Disable 2FA for user
     */
    public static function disable2FA($userId) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET tfa_secret = NULL, tfa_enabled = 0 WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Change password
     */
    public static function changePassword($userId, $newPassword) {
        $db = Database::getUserDB();
        $passwordHash = password_hash($newPassword, HASH_ALGO);
        
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$passwordHash, $userId]);
    }

    /**
     * Update user email
     * @param int $userId The ID of the user whose email should be updated
     * @param string $newEmail The new email address
     * @return bool Returns true on success
     * @throws Exception If the email is already in use by another user or invalid
     */
    public static function updateEmail($userId, $newEmail) {
        $db = Database::getUserDB();
        
        // Validate email format
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungültige E-Mail-Adresse');
        }
        
        // Check if email is already used by another user
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $userId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Update the email
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        $result = $stmt->execute([$newEmail, $userId]);
        
        // Check if the update actually affected a row
        if ($result && $stmt->rowCount() > 0) {
            return true;
        }
        
        // If no rows were affected, the user ID doesn't exist
        throw new Exception('Benutzer nicht gefunden');
    }
    
    /**
     * Update notification preferences for user
     * @param int $userId The ID of the user
     * @param bool $notifyNewProjects Whether to notify about new projects
     * @param bool $notifyNewEvents Whether to notify about new events
     * @param bool $blogNewsletter Whether to notify about new blog posts
     * @return bool Returns true on success
     */
    public static function updateNotificationPreferences($userId, $notifyNewProjects, $notifyNewEvents, $blogNewsletter = false) {
        $db = Database::getUserDB();
        
        $stmt = $db->prepare("
            UPDATE users 
            SET notify_new_projects = ?, notify_new_events = ?, blog_newsletter = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $notifyNewProjects ? 1 : 0,
            $notifyNewEvents ? 1 : 0,
            $blogNewsletter ? 1 : 0,
            $userId
        ]);
    }

    /**
     * Get all users subscribed to the blog newsletter
     * @return array List of users with id, email, first_name fields
     */
    public static function getNewsletterSubscribers() {
        $db = Database::getUserDB();
        $stmt = $db->query("SELECT id, email, first_name FROM users WHERE blog_newsletter = 1 AND deleted_at IS NULL");
        return $stmt->fetchAll();
    }
    
    /**
     * Update theme preference for user
     * @param int $userId The ID of the user
     * @param string $theme The theme preference ('auto', 'light', or 'dark')
     * @return bool Returns true on success
     */
    public static function updateThemePreference($userId, $theme) {
        $db = Database::getUserDB();
        
        // Validate theme value
        if (!in_array($theme, ['auto', 'light', 'dark'])) {
            return false;
        }
        
        $stmt = $db->prepare("
            UPDATE users 
            SET theme_preference = ? 
            WHERE id = ?
        ");
        
        return $stmt->execute([$theme, $userId]);
    }
    
    /**
     * Create email change request with token
     * @param int $userId The ID of the user requesting email change
     * @param string $newEmail The new email address
     * @return string The generated token
     * @throws Exception If email is invalid or already in use
     */
    public static function createEmailChangeRequest($userId, $newEmail) {
        $db = Database::getUserDB();
        
        // Validate email format
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungültige E-Mail-Adresse');
        }
        
        // Check if email is already used by another user
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $userId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (self::EMAIL_CHANGE_TOKEN_EXPIRATION_HOURS * 60 * 60));
        
        // Delete any existing email change requests for this user
        $stmt = $db->prepare("DELETE FROM email_change_requests WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Insert new request
        $stmt = $db->prepare("
            INSERT INTO email_change_requests (user_id, new_email, token, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $newEmail, $token, $expiresAt]);
        
        return $token;
    }
    
    /**
     * Confirm email change with token
     * @param string $token The confirmation token
     * @return bool Returns true on success
     * @throws Exception If token is invalid or expired
     */
    public static function confirmEmailChange($token) {
        $db = Database::getUserDB();
        
        // Find request by token
        $stmt = $db->prepare("
            SELECT user_id, new_email, expires_at 
            FROM email_change_requests 
            WHERE token = ?
        ");
        $stmt->execute([$token]);
        $request = $stmt->fetch();
        
        if (!$request) {
            throw new Exception('Ungültiger Bestätigungslink');
        }
        
        // Check if expired
        if (strtotime($request['expires_at']) < time()) {
            throw new Exception('Bestätigungslink ist abgelaufen');
        }
        
        // Check if email is still available
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$request['new_email'], $request['user_id']]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Update user email
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        $result = $stmt->execute([$request['new_email'], $request['user_id']]);
        
        if (!$result) {
            throw new Exception('Fehler beim Aktualisieren der E-Mail-Adresse');
        }
        
        // Delete the request
        $stmt = $db->prepare("DELETE FROM email_change_requests WHERE token = ?");
        $stmt->execute([$token]);
        
        return true;
    }
}
