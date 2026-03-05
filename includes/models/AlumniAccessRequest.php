<?php
/**
 * AlumniAccessRequest Model
 * Manages alumni e-mail recovery requests
 */

class AlumniAccessRequest {

    // ---------------------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------------------

    /**
     * Get a single request by its ID.
     */
    public static function getById(int $id): array|false {
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT * FROM alumni_access_requests WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Return all requests, optionally filtered by status.
     *
     * @param string|null $status  'pending' | 'approved' | 'rejected' | null (= all)
     */
    public static function getAll(?string $status = null): array {
        $db = Database::getContentDB();

        if ($status !== null) {
            $stmt = $db->prepare(
                "SELECT * FROM alumni_access_requests WHERE status = ? ORDER BY created_at DESC"
            );
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query(
                "SELECT * FROM alumni_access_requests ORDER BY created_at DESC"
            );
        }

        return $stmt->fetchAll();
    }

    /**
     * Count requests grouped by status – useful for admin dashboards.
     *
     * @return array{pending: int, approved: int, rejected: int, total: int}
     */
    public static function countByStatus(): array {
        $db   = Database::getContentDB();
        $stmt = $db->query(
            "SELECT status, COUNT(*) AS cnt FROM alumni_access_requests GROUP BY status"
        );
        $rows   = $stmt->fetchAll();
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];

        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['cnt'];
            }
            $counts['total'] += (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Check whether a pending request for the given e-mail address already exists.
     *
     * The method normalises $newEmail internally (trim + lower-case).
     *
     * @param string $newEmail  New e-mail address to look up (normalisation applied internally)
     * @return bool  true = a pending duplicate exists, false = no duplicate
     */
    public static function hasPendingRequest(string $newEmail): bool {
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM alumni_access_requests WHERE new_email = ? AND status = 'pending'"
        );
        $stmt->execute([strtolower(trim($newEmail))]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ---------------------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------------------

    /**
     * Create a new alumni access request.
     *
     * @param array $data  Keys: first_name, last_name, new_email, old_email,
     *                           graduation_semester, study_program
     * @return int|false   The inserted row ID, or false on failure.
     */
    public static function create(array $data): int|false {
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "INSERT INTO alumni_access_requests
                (first_name, last_name, new_email, old_email, graduation_semester, study_program)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $ok = $stmt->execute([
            trim($data['first_name']),
            trim($data['last_name']),
            strtolower(trim($data['new_email'])),
            isset($data['old_email']) && $data['old_email'] !== ''
                ? strtolower(trim($data['old_email']))
                : null,
            trim($data['graduation_semester']),
            trim($data['study_program']),
        ]);

        return $ok ? (int) $db->lastInsertId() : false;
    }

    /**
     * Update the status of a request (approve / reject).
     *
     * @param int    $id           Request ID
     * @param string $status       'approved' or 'rejected'
     * @param int    $processedBy  User ID of the admin performing the action
     */
    public static function updateStatus(int $id, string $status, int $processedBy): bool {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return false;
        }

        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "UPDATE alumni_access_requests
             SET status = ?, processed_at = NOW(), processed_by = ?
             WHERE id = ?"
        );

        return $stmt->execute([$status, $processedBy, $id]);
    }

    /**
     * Delete a request by ID.
     */
    public static function delete(int $id): bool {
        $db   = Database::getContentDB();
        $stmt = $db->prepare("DELETE FROM alumni_access_requests WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
