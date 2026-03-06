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
    private static $shopConnection = null;
    /** @var bool Tracks whether content-DB schema migration has run this request */
    private static $contentMigrated = false;

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
        if (!self::$contentMigrated) {
            self::migrateContentSchema(self::$contentConnection);
            self::$contentMigrated = true;
        }
        return self::$contentConnection;
    }

    /**
     * Ensure optional columns added after the initial deployment exist in alumni_profiles.
     * Runs at most once per request. Safe to call even when the table already has the columns.
     */
    private static function migrateContentSchema(PDO $db): void {
        // Columns to add if they are missing, keyed by column name
        $pending = [
            'skills'  => "ALTER TABLE alumni_profiles ADD COLUMN skills TEXT DEFAULT NULL COMMENT 'Comma-separated list of skills/competencies' AFTER bio",
            'cv_path' => "ALTER TABLE alumni_profiles ADD COLUMN cv_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded CV/resume PDF' AFTER skills",
        ];
        foreach ($pending as $column => $alterSql) {
            try {
                $stmt = $db->prepare(
                    "SELECT COLUMN_NAME
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'alumni_profiles'
                       AND COLUMN_NAME  = ?"
                );
                $stmt->execute([$column]);
                if (!$stmt->fetch()) {
                    $db->exec($alterSql);
                    error_log("Content schema migration applied: added column '$column' to alumni_profiles");
                }
            } catch (PDOException $e) {
                // Table may not exist yet on a brand-new install, or the DB user may
                // lack ALTER TABLE permission.  Log and continue – the existing
                // query-level fallbacks in Alumni/Member models will still protect
                // against hard failures.
                error_log("Content schema migration skipped for column '$column': " . $e->getMessage());
            }
        }
    }

    /**
     * Get Shop Database Connection
     * Exclusive connection for the shop (dbs15381315), strictly separated from the Content DB.
     *
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getShopDB() {
        if (self::$shopConnection === null) {
            try {
                self::$shopConnection = new PDO(
                    "mysql:host=" . DB_SHOP_HOST . ";dbname=" . DB_SHOP_NAME . ";charset=utf8mb4",
                    DB_SHOP_USER,
                    DB_SHOP_PASS,
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
        return self::$shopConnection;
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
            case 'shop':
                return self::getShopDB();
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
        self::$shopConnection = null;
    }
}
