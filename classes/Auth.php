<?php

class Auth
{
    /**
     * Starts the session if not already started.
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Returns true when a user is currently logged in.
     */
    public static function isLoggedIn(): bool
    {
        self::ensureSession();
        return !empty($_SESSION['user_id']);
    }

    /**
     * Returns the ID of the logged-in user, or null.
     */
    public static function getUserId(): ?int
    {
        self::ensureSession();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Returns the full name of the logged-in user, or null.
     */
    public static function getUserName(): ?string
    {
        self::ensureSession();
        return $_SESSION['user_name'] ?? null;
    }

    /**
     * Returns the e-mail address of the logged-in user, or null.
     */
    public static function getUserEmail(): ?string
    {
        self::ensureSession();
        return $_SESSION['user_email'] ?? null;
    }
}
