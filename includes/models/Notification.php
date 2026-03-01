<?php
/**
 * Notification Model
 * Manages on-site user notifications
 */

class Notification {

    /**
     * Get unread notification count for a user
     */
    public static function getUnreadCount(int $userId): int {
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get latest notifications for a user (most recent first)
     */
    public static function getLatest(int $userId, int $limit = 10): array {
        $db = Database::getUserDB();
        $stmt = $db->prepare(
            "SELECT id, title, message, link, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a single notification as read (must belong to user)
     */
    public static function markAsRead(int $notificationId, int $userId): bool {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }

    /**
     * Mark all notifications of a user as read
     */
    public static function markAllAsRead(int $userId): bool {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    }

    /**
     * Create a new notification for a user
     */
    public static function create(int $userId, string $title, string $message, ?string $link = null): int {
        $db = Database::getUserDB();
        $stmt = $db->prepare(
            "INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $title, $message, $link]);
        return (int) $db->lastInsertId();
    }
}
