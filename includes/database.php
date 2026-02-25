<?php
/**
 * Database Connection Handler
 * Manages connections to both User and Content databases
 */

require_once __DIR__ . '/../config/config.php';

class Database {
    private static $userConnection = null;
    private static $contentConnection = null;
    private static $rechConnection = null;

    /**
     * Get User Database Connection
     */
    public static function getUserDB() {
        if (self::$userConnection === null) {
            try {
                self::$userConnection = new PDO(
                    "mysql:host=" . DB_USER_HOST . ";dbname=" . DB_USER_NAME . ";charset=utf8mb4",
                    DB_USER_USER,
                    DB_USER_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        return self::$userConnection;
    }

    /**
     * Get Content Database Connection
     */
    public static function getContentDB() {
        if (self::$contentConnection === null) {
            try {
                self::$contentConnection = new PDO(
                    "mysql:host=" . DB_CONTENT_HOST . ";dbname=" . DB_CONTENT_NAME . ";charset=utf8mb4",
                    DB_CONTENT_USER,
                    DB_CONTENT_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        return self::$contentConnection;
    }

    /**
     * Get Invoice/Rech Database Connection
     * 
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getRechDB() {
        if (self::$rechConnection === null) {
            try {
                self::$rechConnection = new PDO(
                    "mysql:host=" . DB_RECH_HOST . ";port=" . DB_RECH_PORT . ";dbname=" . DB_RECH_NAME . ";charset=utf8mb4",
                    DB_RECH_USER,
                    DB_RECH_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        return self::$rechConnection;
    }

    /**
     * Get database connection by name
     * 
     * @param string $name Connection name ('user', 'content', 'rech', or 'invoice')
     * @return PDO Database connection
     * @throws Exception If connection name is invalid
     */
    public static function getConnection($name) {
        switch ($name) {
            case 'user':
                return self::getUserDB();
            case 'content':
                return self::getContentDB();
            case 'rech':
            case 'invoice':
                return self::getRechDB();
            default:
                throw new Exception("Invalid connection name: $name");
        }
    }

    /**
     * Close all database connections
     */
    public static function closeAll() {
        self::$userConnection = null;
        self::$contentConnection = null;
        self::$rechConnection = null;
    }
}
