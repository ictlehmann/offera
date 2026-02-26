<?php
/**
 * EasyVereinInventory Service
 * Manages inventory items and member assignments via the EasyVerein API v2.0.
 *
 * Uses the same authentication pattern as EasyVereinSync.php.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../database.php';

class EasyVereinInventory {

    private const API_BASE  = 'https://easyverein.com/api/v2.0';
    private const CACHE_TTL = 300; // seconds (5 minutes)

    /** Request-level in-memory cache to avoid repeated file reads within one PHP request.
     *  This is a static property, so it persists only for the duration of the current
     *  PHP process / request and is automatically reset between separate HTTP requests.
     */
    private static ?array $requestCache = null;

    /**
     * Runtime token override – set by refreshToken() after a successful refresh.
     * Takes priority over the DB and .env-sourced constant within the current PHP process.
     */
    private static ?string $currentToken = null;

    /**
     * Return the path to the inventory cache file.
     * The filename includes a hash of the installation path to avoid
     * collisions between multiple application instances on the same server.
     */
    private function getCacheFile(): string {
        return sys_get_temp_dir() . '/easyverein_inventory_' . md5(__DIR__) . '_cache.json';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Return the Bearer token for the EasyVerein API.
     *
     * Priority order (highest first):
     *   1. In-memory runtime override set by refreshToken()
     *   2. system_settings DB table (key: easyverein_api_token)
     *   3. EASYVEREIN_API_TOKEN constant sourced from .env
     *
     * The resolved token is cached in self::$currentToken so that subsequent
     * calls within the same PHP process do not repeat the DB lookup.
     *
     * @throws Exception If no API token can be found.
     */
    private function getApiToken(): string {
        // 1. In-memory override (set by refreshToken or a previous call)
        if (self::$currentToken !== null) {
            return self::$currentToken;
        }

        // 2. Check DB system_settings for a previously refreshed token
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'easyverein_api_token' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                self::$currentToken = $row['setting_value'];
                return self::$currentToken;
            }
        } catch (Exception $e) {
            // DB unavailable – fall through to constant
        }

        // 3. Constant from .env
        $token = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        if (empty($token)) {
            throw new Exception('EasyVerein API token not configured');
        }
        self::$currentToken = $token;
        return $token;
    }

    /**
     * Execute a cURL request and return the decoded JSON body.
     *
     * After each successful response the method inspects the response headers.
     * If the EasyVerein API signals that a token refresh is needed
     * (header "tokenRefreshNeeded: true"), refreshToken() is called automatically
     * so that the new token is available for the next API call within this process.
     *
     * @param string     $method           HTTP method (GET, PATCH, PUT, DELETE, …)
     * @param string     $endpoint         Full URL to call
     * @param array|null $body             Request body (will be JSON-encoded); null for no body
     * @param bool       $skipTokenRefresh When true the auto-refresh check is skipped (used
     *                                     internally by refreshToken() to avoid recursion)
     * @return array Decoded JSON response (may be empty for 204 No Content)
     * @throws Exception On cURL error or non-2xx HTTP status
     */
    private function request(string $method, string $endpoint, ?array $body = null, bool $skipTokenRefresh = false): array {
        $token = $this->getApiToken();

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Collect response headers for token-refresh detection
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $len   = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('cURL error: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = "EasyVerein API [{$method} {$endpoint}] returned HTTP {$httpCode} - Details: " . $response;
            if ($httpCode === 403) {
                if (strpos($endpoint, 'contact-details') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Mitglieder zu suchen. Bitte setze das Modul [Adressen] im easyVerein Token auf [Lesen].';
                } elseif (strpos($endpoint, 'lending') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Ausleihen anzulegen. Bitte setze [Inventar] und [Ausleihen] auf [Lesen & Schreiben].';
                } elseif (strpos($endpoint, 'custom-fields') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Individualfelder zu bearbeiten. Bitte setze [Individuelle Felder] auf [Lesen & Schreiben].';
                }
            }
            throw new Exception($msg);
        }

        // Automatic token refresh when the API signals it is needed
        if (!$skipTokenRefresh
            && isset($responseHeaders['tokenrefreshneeded'])
            && strtolower($responseHeaders['tokenrefreshneeded']) === 'true'
        ) {
            try {
                $this->refreshToken();
            } catch (Exception $e) {
                error_log('EasyVereinInventory: Token-Refresh nach API-Aufruf fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // 204 No Content – return an empty array
        if ($httpCode === 204 || $response === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Refresh the EasyVerein API token.
     *
     * Calls GET /api/v2.0/refresh-token and persists the new token:
     *   1. Updates self::$currentToken immediately so the next API call in this
     *      process uses the fresh token without any further DB or file I/O.
     *   2. Saves the token to the system_settings DB table
     *      (key: easyverein_api_token) with priority.
     *   3. Falls back to rewriting the EASYVEREIN_API_TOKEN line in the .env
     *      file if the DB write fails and the file is writable.
     *
     * @throws Exception If the refresh request fails or the API does not return
     *                   a token, indicating that manual intervention is required.
     */
    private function refreshToken(): void {
        $url = self::API_BASE . '/refresh-token';

        try {
            $data = $this->request('GET', $url, null, true);
        } catch (Exception $e) {
            $msg = 'EasyVerein Token-Refresh fehlgeschlagen: ' . $e->getMessage()
                . ' — Manueller Token-Eingriff in der .env Datei oder Datenbank (system_settings) notwendig.';
            error_log($msg);
            throw new Exception($msg);
        }

        $newToken = $data['token'] ?? null;
        if (empty($newToken)) {
            $msg = 'EasyVerein Token-Refresh: Kein Token in der API-Antwort erhalten'
                . ' — Manueller Token-Eingriff in der .env Datei oder Datenbank (system_settings) notwendig.';
            error_log($msg);
            throw new Exception($msg);
        }

        // Update in-memory token immediately for the rest of this request
        self::$currentToken = $newToken;

        // Persist to DB (priority)
        $savedToDb = false;
        try {
            $db = Database::getContentDB();
            $db->exec(
                "CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key   VARCHAR(100) PRIMARY KEY,
                    setting_value TEXT,
                    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by    INT
                )"
            );
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES ('easyverein_api_token', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([$newToken]);
            $savedToDb = true;
            error_log('EasyVereinInventory: Token erfolgreich in Datenbank (system_settings) gespeichert.');
        } catch (Exception $e) {
            error_log('EasyVereinInventory: Token konnte nicht in Datenbank gespeichert werden: ' . $e->getMessage());
        }

        // Fall back to .env file if DB save failed
        if (!$savedToDb) {
            $this->updateEnvToken($newToken);
        }
    }

    /**
     * Update the EASYVEREIN_API_TOKEN value in the .env file.
     *
     * The method is a best-effort helper: it logs a descriptive error but does
     * not throw when the file cannot be read or written so that the caller can
     * handle missing persistence gracefully.
     *
     * @param string $newToken The new API token value to write.
     */
    private function updateEnvToken(string $newToken): void {
        $envFile = __DIR__ . '/../../.env';

        if (!file_exists($envFile) || !is_writable($envFile)) {
            error_log(
                'EasyVereinInventory: .env Datei nicht beschreibbar'
                . ' — Manueller Token-Eingriff notwendig. Bitte EASYVEREIN_API_TOKEN manuell aktualisieren.'
            );
            return;
        }

        $content = file_get_contents($envFile);
        if ($content === false) {
            error_log('EasyVereinInventory: .env Datei konnte nicht gelesen werden — Manueller Token-Eingriff notwendig.');
            return;
        }

        $count      = 0;
        $newContent = preg_replace(
            '/^EASYVEREIN_API_TOKEN=.*/m',
            'EASYVEREIN_API_TOKEN=' . $newToken,
            $content,
            -1,
            $count
        );

        if ($count === 0 || $newContent === null) {
            error_log(
                'EasyVereinInventory: EASYVEREIN_API_TOKEN nicht in .env gefunden'
                . ' — Manueller Token-Eingriff notwendig.'
            );
            return;
        }

        if (file_put_contents($envFile, $newContent) === false) {
            error_log(
                'EasyVereinInventory: .env Datei konnte nicht geschrieben werden'
                . ' — Manueller Token-Eingriff notwendig.'
            );
            return;
        }

        error_log('EasyVereinInventory: Token erfolgreich in .env Datei aktualisiert.');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch all inventory items from EasyVerein.
     *
     * Calls GET /api/v2.0/inventory-object and follows pagination links until
     * all items have been retrieved (handles the standard EasyVerein
     * results/next/data wrapper).
     *
     * @return array Array of inventory-item objects as returned by the API
     * @throws Exception On API or network errors
     */
    public function getItems(): array {
        // Return in-memory cache if populated within this PHP request
        if (self::$requestCache !== null) {
            return self::$requestCache;
        }

        $cacheFile = $this->getCacheFile();

        // Return cached data if the file exists and is younger than CACHE_TTL seconds
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (is_array($cached)) {
                    self::$requestCache = $cached;
                    return $cached;
                }
            }
        }

        $allItems = [];
        $url      = self::API_BASE . '/inventory-object?limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                throw new Exception('Unexpected API response format for inventory-object');
            }

            $allItems = array_merge($allItems, $items);

            // Follow the `next` pagination link if present
            $url = $data['next'] ?? null;
        }

        // Persist the fresh result to the cache file (best-effort; ignore failures)
        @file_put_contents($cacheFile, json_encode($allItems));

        self::$requestCache = $allItems;
        return $allItems;
    }

    /**
     * Assign an inventory item to a member in EasyVerein.
     *
     * Reads the current item, verifies availability, then calls
     * PATCH /api/v1.7/inventory-items/{itemId} to store the member
     * assignment and decrement the available quantity by $quantity.
     *
     * Note: The EasyVerein REST API does not offer atomic compare-and-swap
     * operations, so a small time-of-check / time-of-use gap exists between
     * the availability read and the PATCH write. Callers should enforce
     * higher-level locking (e.g. a database transaction) when concurrent
     * assignments of the same item are possible.
     *
     * @param int    $itemId    EasyVerein inventory-item ID
     * @param int    $memberId  EasyVerein member ID to assign to
     * @param int    $quantity  Number of units to assign
     * @param string $purpose   Free-text reason / purpose of the assignment
     * @param string $userName  Display name of the borrower (used to update 'Aktuelle Ausleiher' custom field)
     * @param string $userEmail E-mail address of the borrower (used to update 'Entra E-Mail' custom field)
     * @return array API response data
     * @throws Exception On API or validation errors
     */
    public function assignItem(int $itemId, int $memberId, int $quantity, string $purpose, string $userName = '', string $userEmail = ''): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        // First, read the current item to obtain its stock and validate availability
        $url  = self::API_BASE . '/inventory-object/' . $itemId;
        $item = $this->request('GET', $url);

        $currentPieces = (int)($item['pieces'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);
        if ($currentPieces < $quantity) {
            throw new Exception(
                "Insufficient stock: requested {$quantity}, available {$currentPieces}"
            );
        }

        // Build a timestamped log entry and prepend it to the existing note so that
        // the full checkout history is preserved in EasyVerein.
        $timestamp    = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
        $logEntry     = "⏳ [{$timestamp}] AUSGELIEHEN: {$quantity}x an {$memberId}";
        if ($purpose !== '') {
            $logEntry .= ". {$purpose}";
        }
        $existingNote = $item['note'] ?? $item['description'] ?? '';
        $updatedNote  = $logEntry . ($existingNote !== '' ? "\n" . $existingNote : '');

        // Build the PATCH payload:
        //   – assign the item to the member
        //   – reduce the stored quantity by the checked-out amount
        //   – store the updated log in the note field
        $payload = [
            'member'   => $memberId,
            'note'     => $updatedNote,
            'pieces'   => $currentPieces - $quantity,
        ];

        $result = $this->request('PATCH', $url, $payload);

        // Invalidate the inventory cache so the next page load fetches fresh data
        self::$requestCache = null;
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        // Update the EasyVerein custom fields on the inventory object:
        //   – 'Entra E-Mail'              → borrower's e-mail address
        //   – 'Aktuelle Ausleiher'        → borrower's display name
        //   – 'Zustand der letzten Rückgabe' → cleared (new checkout)
        if ($userName !== '' || $userEmail !== '') {
            try {
                $cfUrl        = $url . '/custom-fields?query={id,value,customField{id,name}}';
                $cfData       = $this->request('GET', $cfUrl);
                $customFields = $cfData['results'] ?? $cfData['data'] ?? $cfData;

                $nameEntry  = $userName  !== '' ? "{$userName} ({$quantity}x)"  : '';
                $emailEntry = $userEmail !== '' ? "{$userEmail} ({$quantity}x)" : '';

                $fieldsToUpdate = [];
                foreach ($customFields as $field) {
                    $fieldId   = $field['id'] ?? null;
                    $fieldName = $field['customField']['name'] ?? '';

                    if ($fieldId === null) {
                        continue;
                    }

                    if ($fieldName === 'Aktuelle Ausleiher') {
                        $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $nameEntry];
                    } elseif ($fieldName === 'Entra E-Mail') {
                        $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $emailEntry];
                    } elseif ($fieldName === 'Zustand der letzten Rückgabe') {
                        $fieldsToUpdate[] = ['id' => $fieldId, 'value' => ''];
                    }
                }

                if (!empty($fieldsToUpdate)) {
                    $this->request('PATCH', $url . '/custom-fields/bulk-update', $fieldsToUpdate);
                }
            } catch (Exception $cfEx) {
                error_log('EasyVereinInventory::assignItem: custom fields update failed: ' . $cfEx->getMessage());
            }
        }

        error_log(sprintf(
            'EasyVereinInventory: item %d assigned to member %d (qty %d, purpose: %s)',
            $itemId, $memberId, $quantity, $purpose
        ));

        return $result;
    }

    /**
     * Get all inventory objects currently lent to the given user.
     *
     * Makes a GET request to /lending?futureReturnDate=true&limit=100 with a
     * query that includes the parentInventoryObject and its customFields.
     * For each active lending the customFields of the parentInventoryObject are
     * inspected: if the field named 'Entra E-Mail' or 'Aktuelle Ausleiher'
     * contains $userIdentifier (case-insensitive substring match), the
     * parentInventoryObject is included in the returned array.
     *
     * @param int|string $userIdentifier User e-mail or identifier to match
     * @return array parentInventoryObject records assigned to the user
     * @throws Exception On API or network errors
     */
    public function getMyAssignedItems($userIdentifier): array {
        $url  = self::API_BASE . '/lending?futureReturnDate=true&limit=100&query={id,parentInventoryObject{*,customFields{id,value,customField{id,name}}}}';
        $data  = $this->request('GET', $url);
        $items = $data['results'] ?? $data['data'] ?? $data;

        if (!is_array($items)) {
            return [];
        }

        $lc      = strtolower((string)$userIdentifier);
        $myItems = [];

        foreach ($items as $lending) {
            $obj = $lending['parentInventoryObject'] ?? null;
            if (!is_array($obj)) {
                continue;
            }

            $customFields = $obj['customFields'] ?? [];
            foreach ($customFields as $cf) {
                $fieldName = strtolower($cf['customField']['name'] ?? '');
                if ($fieldName !== 'entra e-mail' && $fieldName !== 'aktuelle ausleiher') {
                    continue;
                }
                $fieldValue = (string)($cf['value'] ?? '');
                foreach (explode("\n", $fieldValue) as $line) {
                    if (str_starts_with(strtolower(trim($line)), $lc . ' (')) {
                        $myItems[] = $obj;
                        break 2;
                    }
                }
            }
        }

        return $myItems;
    }

    /**
     * Fetch all inventory objects from EasyVerein (GET /api/v2.0/inventory-object).
     *
     * Follows pagination links until all items have been retrieved. Each item
     * contains at least the `name` and `pieces` fields as returned by the API.
     *
     * @return array Array of inventory-object records as returned by the API
     * @throws Exception On API or network errors
     */
    public function getInventoryObjects(): array {
        $allItems = [];
        $url      = self::API_BASE . '/inventory-object?limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                throw new Exception('Unexpected API response format for inventory-object');
            }

            $allItems = array_merge($allItems, $items);
            $url      = $data['next'] ?? null;
        }

        return $allItems;
    }

    /**
     * Fetch all currently active lendings for a given inventory object.
     *
     * Calls GET /api/v2.0/lending?parentInventoryObject={id}&futureReturnDate=true
     * and follows pagination until all records have been retrieved.
     *
     * @param int|string $inventoryObjectId EasyVerein inventory-object ID
     * @return array Array of lending records as returned by the API
     * @throws Exception On API or network errors
     */
    public function getActiveLendings($inventoryObjectId): array {
        $allLendings = [];
        $url         = self::API_BASE . '/lending?parentInventoryObject='
            . urlencode((string)$inventoryObjectId) . '&futureReturnDate=true&limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                error_log('EasyVereinInventory::getActiveLendings: unexpected API response format for lending endpoint');
                break;
            }

            $allLendings = array_merge($allLendings, $items);
            $url         = $data['next'] ?? null;
        }

        return $allLendings;
    }

    /**
     * Calculate the number of available units for a given inventory object and
     * date range, taking into account both EasyVerein active lendings and
     * locally stored inventory requests.
     *
     * The formula is:
     *   available = pieces
     *             – count of EasyVerein lendings that overlap [startDate, endDate]
     *             – SUM(quantity) of local inventory_requests with status
     *               'pending' or 'approved' that overlap [startDate, endDate]
     *
     * Overlap condition: lending.startDate <= endDate AND lending.endDate >= startDate
     *
     * @param int|string $inventoryObjectId EasyVerein inventory-object ID
     * @param string     $startDate         Start date of the requested period (YYYY-MM-DD)
     * @param string     $endDate           End date of the requested period (YYYY-MM-DD)
     * @return int Number of available units (minimum 0)
     * @throws Exception On API errors
     */
    public function getAvailableQuantity($inventoryObjectId, string $startDate, string $endDate): int {
        // 1. Fetch total pieces from the inventory object
        $url  = self::API_BASE . '/inventory-object/' . urlencode((string)$inventoryObjectId);
        $item = $this->request('GET', $url);
        $totalPieces = (int)($item['pieces'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);

        // 2. Count EasyVerein active lendings that overlap the requested period
        $activeLendings = $this->getActiveLendings($inventoryObjectId);
        $evLent = 0;
        foreach ($activeLendings as $lending) {
            // Try multiple possible field names for lending start / end dates
            $lendStart = $lending['startDate']    ?? $lending['lendingStart'] ?? $lending['start_date'] ?? $lending['dateFrom'] ?? null;
            $lendEnd   = $lending['returnDate']   ?? $lending['lendingEnd']   ?? $lending['end_date']   ?? $lending['dateTo']   ?? $lending['dueDate'] ?? null;

            $overlaps = false;
            if ($lendStart !== null && $lendEnd !== null) {
                // Normalise to YYYY-MM-DD for string comparison (ISO dates sort lexicographically)
                $lendStart = substr($lendStart, 0, 10);
                $lendEnd   = substr($lendEnd,   0, 10);
                if ($lendStart <= $endDate && $lendEnd >= $startDate) {
                    $overlaps = true;
                }
            } else {
                // No date information available – conservatively treat as overlapping
                $overlaps = true;
            }

            if ($overlaps) {
                // Use the quantity field from the lending record; fall back to 1 if absent
                $lendingQty = (int)($lending['quantity'] ?? $lending['pieces'] ?? $lending['amount'] ?? 1);
                $evLent    += max(1, $lendingQty);
            }
        }

        // 3. Sum quantities from local inventory_requests overlapping the period
        $localReserved = 0;
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(quantity), 0) AS reserved
                 FROM inventory_requests
                 WHERE inventory_object_id = ?
                   AND status IN ('pending', 'approved')
                   AND start_date <= ?
                   AND end_date   >= ?"
            );
            $stmt->execute([(string)$inventoryObjectId, $endDate, $startDate]);
            $row           = $stmt->fetch(PDO::FETCH_ASSOC);
            $localReserved = (int)($row['reserved'] ?? 0);
        } catch (Exception $e) {
            error_log('EasyVereinInventory::getAvailableQuantity DB query failed: ' . $e->getMessage());
        }

        return max(0, $totalPieces - $evLent - $localReserved);
    }

    /**
     * Return an inventory item in EasyVerein.
     *
     * Reads the current item, then calls
     * PATCH /api/v1.7/inventory-items/{itemId} to clear the member
     * assignment and restore the quantity by $quantity.
     *
     * Note: The EasyVerein REST API does not offer atomic compare-and-swap
     * operations, so a small time-of-check / time-of-use gap exists between
     * the quantity read and the PATCH write. Callers should enforce
     * higher-level locking when concurrent operations on the same item are
     * possible.
     *
     * @param int $itemId   EasyVerein inventory-item ID
     * @param int $quantity Number of units being returned
     * @return array API response data
     * @throws Exception On API errors
     */
    public function returnItem(int $itemId, int $quantity): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        // Read the current item to obtain its stock
        $url  = self::API_BASE . '/inventory-object/' . $itemId;
        $item = $this->request('GET', $url);

        $currentPieces = (int)($item['pieces'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);

        // Determine who is returning the item for the log entry.
        $memberRaw = $item['member'] ?? null;
        if (is_array($memberRaw)) {
            $memberRef = $memberRaw['username'] ?? $memberRaw['name'] ?? $memberRaw['id'] ?? 'Unbekannt';
        } else {
            $memberRef = $memberRaw ?? 'Unbekannt';
        }

        // Build a timestamped return log entry and prepend it to the existing note.
        $timestamp    = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
        $logEntry     = "✅ [{$timestamp}] ZURÜCKGEGEBEN: {$quantity}x von {$memberRef}.";
        $existingNote = $item['note'] ?? $item['description'] ?? '';
        $updatedNote  = $logEntry . ($existingNote !== '' ? "\n" . $existingNote : '');

        // Build the PATCH payload:
        //   – clear the member assignment
        //   – restore the stored quantity
        //   – keep the updated log in the note field
        $payload = [
            'member' => null,
            'note'   => $updatedNote,
            'pieces' => $currentPieces + $quantity,
        ];

        $result = $this->request('PATCH', $url, $payload);

        // Invalidate the inventory cache so the next page load fetches fresh data
        self::$requestCache = null;
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        error_log(sprintf(
            'EasyVereinInventory: item %d returned (qty %d restored)',
            $itemId, $quantity
        ));

        return $result;
    }

    /**
     * Approve a rental request.
     *
     * 1. Loads the pending request from the local DB.
     * 2. Resolves the borrower's EasyVerein contact ID via getContactIdByName().
     * 3. Creates the official lending record in EasyVerein via POST /api/v2.0/lending.
     * 4. Queries all currently active (approved) rentals for this item from the local DB
     *    (including the request being approved now) and builds multiline strings for the
     *    custom fields 'Aktuelle Ausleiher' and 'Entra E-Mail'.
     * 5. Fetches the individual fields via GET /api/v2.0/inventory-object/{id}/custom-fields,
     *    updates 'Aktuelle Ausleiher' and 'Entra E-Mail' with the multiline strings, and
     *    clears 'Zustand der letzten Rückgabe'.
     *    Sends all field updates as a JSON array to PATCH /api/v2.0/inventory-object/{id}/custom-fields/bulk-update.
     * 6. Updates the local DB status to 'approved'.
     *
     * @param int    $requestId  Local inventory_requests row ID
     * @param string $userName   Display name of the borrower
     * @param string $userEmail  E-mail address of the borrower
     * @param int    $quantity   Number of units approved for lending
     * @return void
     * @throws Exception On database or API errors
     */
    public function approveRental(int $requestId, string $userName, string $userEmail, int $quantity): void {
        // 1. Load the pending request from the local DB
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT * FROM inventory_requests WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Inventory request #{$requestId} not found or not pending");
        }

        // 2. Resolve the borrower's EasyVerein contact ID
        $evContactId = $this->getContactIdByName($userName);

        // 3. Create the official lending record in EasyVerein
        $this->createLending(
            $req['inventory_object_id'],
            $evContactId,
            $quantity,
            $req['start_date'],
            $req['end_date']
        );

        // 4. Build multiline custom-field values from all active rentals for this item
        //    (currently approved ones + the request being approved now)
        $userDb = Database::getUserDB();
        $activeStmt = $db->prepare(
            "SELECT ir.user_id, ir.quantity
               FROM inventory_requests ir
              WHERE ir.inventory_object_id = ?
                AND (ir.status = 'approved' OR ir.id = ?)"
        );
        $activeStmt->execute([$req['inventory_object_id'], $requestId]);
        $activeRequests = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate quantities per user
        $userQtyMap = [];
        foreach ($activeRequests as $ar) {
            $uid = (int)$ar['user_id'];
            $userQtyMap[$uid] = ($userQtyMap[$uid] ?? 0) + (int)$ar['quantity'];
        }

        // Fetch user details from the user DB
        $nameLines  = [];
        $emailLines = [];
        if (!empty($userQtyMap)) {
            $placeholders = implode(',', array_fill(0, count($userQtyMap), '?'));
            $uStmt = $userDb->prepare(
                "SELECT id, first_name, last_name, email FROM users WHERE id IN ({$placeholders})"
            );
            $uStmt->execute(array_keys($userQtyMap));
            $userRows = $uStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($userRows as $uRow) {
                $uid  = (int)$uRow['id'];
                $qty  = $userQtyMap[$uid] ?? 1;
                $name = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                if ($name === '') {
                    $name = $uRow['email'] ?? 'Unbekannt';
                }
                $nameLines[]  = "{$name} ({$qty}x)";
                $emailLines[] = ($uRow['email'] ?? '') . " ({$qty}x)";
            }
        }

        $namesValue  = implode("\n", $nameLines);
        $emailsValue = implode("\n", $emailLines);

        // 5. Update individual fields on the inventory object
        $objectUrl        = self::API_BASE . '/inventory-object/' . urlencode((string)$req['inventory_object_id']);
        $customFieldsUrl  = $objectUrl . '/custom-fields?query={id,value,customField{id,name}}';
        $cfData           = $this->request('GET', $customFieldsUrl);
        $customFields     = $cfData['results'] ?? $cfData['data'] ?? $cfData;

        $fieldsToUpdate = [];
        foreach ($customFields as $field) {
            $fieldId   = $field['id']   ?? null;
            $fieldName = $field['customField']['name'] ?? '';

            if ($fieldId === null) {
                continue;
            }

            if ($fieldName === 'Aktuelle Ausleiher') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $namesValue];
            } elseif ($fieldName === 'Entra E-Mail') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $emailsValue];
            } elseif ($fieldName === 'Zustand der letzten Rückgabe') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => ''];
            }
        }

        if (!empty($fieldsToUpdate)) {
            $this->request('PATCH', $objectUrl . '/custom-fields/bulk-update', $fieldsToUpdate);
        }

        // 6. Update local DB status to 'approved' (only after all API calls succeed)
        $upd = $db->prepare(
            "UPDATE inventory_requests SET status = 'approved' WHERE id = ?"
        );
        $upd->execute([$requestId]);

        error_log(sprintf(
            'EasyVereinInventory: request %d approved for %s (%s), inventory object %s',
            $requestId, $userName, $userEmail, $req['inventory_object_id']
        ));
    }

    /**
     * Verify the return of a rental request.
     *
     * 1. Finds the active EasyVerein lending for the inventory object and sets
     *    its returnDate to today via PATCH /api/v2.0/lending/{id}.
     * 2. Queries all remaining active (approved) rentals for this item from the local DB
     *    (excluding the request being returned) and builds multiline strings for the
     *    custom fields 'Aktuelle Ausleiher' and 'Entra E-Mail'. Sets both to '' if nobody
     *    still has the item.
     * 3. Finds the individual field 'Zustand der letzten Rückgabe' and writes
     *    '$condition - Geprüft am [DATE] durch $adminName. Notiz: $notes'.
     *    Sends all field updates as a JSON array to PATCH /api/v2.0/inventory-object/{id}/custom-fields/bulk-update.
     * 4. Updates the local DB status to 'returned'.
     *
     * @param int    $requestId  Local inventory_requests row ID
     * @param string $adminName  Display name of the board member performing the verification
     * @param string $condition  Condition label of the returned item
     * @param string $notes      Optional notes about the return
     * @return void
     * @throws Exception On database or API errors
     */
    public function verifyReturn(int $requestId, string $adminName, string $condition, string $notes): void {
        // 1. Load the approved (or pending_return) request from the local DB
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT * FROM inventory_requests WHERE id = ? AND status IN ('approved', 'pending_return')"
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Inventory request #{$requestId} not found or not approved");
        }

        $tz             = new DateTimeZone('Europe/Berlin');
        $today          = (new DateTime('now', $tz))->format('Y-m-d');
        $todayFormatted = (new DateTime('now', $tz))->format('d.m.Y');

        // 2. End the active lending in EasyVerein by patching its returnDate to today
        $activeLendings  = $this->getActiveLendings($req['inventory_object_id']);
        $lendingPatched  = false;
        foreach ($activeLendings as $lending) {
            $lendingId = $lending['id'] ?? null;
            if ($lendingId === null) {
                continue;
            }
            $this->request('PATCH', self::API_BASE . '/lending/' . $lendingId, ['returnDate' => $today]);
            $lendingPatched = true;
            break;
        }
        if (!$lendingPatched) {
            error_log(sprintf(
                'EasyVereinInventory::verifyReturn: no active lending found for inventory object %s (request %d)',
                $req['inventory_object_id'], $requestId
            ));
        }

        // 3. Build multiline custom-field values from remaining active rentals for this item
        //    (all approved requests for this item except the one being returned now)
        $remainingStmt = $db->prepare(
            "SELECT ir.user_id, ir.quantity
               FROM inventory_requests ir
              WHERE ir.inventory_object_id = ?
                AND ir.status = 'approved'
                AND ir.id != ?"
        );
        $remainingStmt->execute([$req['inventory_object_id'], $requestId]);
        $remainingRequests = $remainingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate quantities per user
        $userQtyMap = [];
        foreach ($remainingRequests as $rr) {
            $uid = (int)$rr['user_id'];
            $userQtyMap[$uid] = ($userQtyMap[$uid] ?? 0) + (int)$rr['quantity'];
        }

        $namesValue  = '';
        $emailsValue = '';
        if (!empty($userQtyMap)) {
            $userDb = Database::getUserDB();
            $placeholders = implode(',', array_fill(0, count($userQtyMap), '?'));
            $uStmt = $userDb->prepare(
                "SELECT id, first_name, last_name, email FROM users WHERE id IN ({$placeholders})"
            );
            $uStmt->execute(array_keys($userQtyMap));
            $userRows = $uStmt->fetchAll(PDO::FETCH_ASSOC);

            $nameLines  = [];
            $emailLines = [];
            foreach ($userRows as $uRow) {
                $uid  = (int)$uRow['id'];
                $qty  = $userQtyMap[$uid] ?? 1;
                $name = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                if ($name === '') {
                    $name = $uRow['email'] ?? 'Unbekannt';
                }
                $nameLines[]  = "{$name} ({$qty}x)";
                $emailLines[] = ($uRow['email'] ?? '') . " ({$qty}x)";
            }
            $namesValue  = implode("\n", $nameLines);
            $emailsValue = implode("\n", $emailLines);
        }

        // 4. Update individual fields on the inventory object
        $objectUrl        = self::API_BASE . '/inventory-object/' . urlencode((string)$req['inventory_object_id']);
        $customFieldsUrl  = $objectUrl . '/custom-fields?query={id,value,customField{id,name}}';
        $cfData           = $this->request('GET', $customFieldsUrl);
        $customFields     = $cfData['results'] ?? $cfData['data'] ?? $cfData;

        $conditionText  = "{$condition} - Geprüft am {$todayFormatted} durch {$adminName}."
            . ($notes !== '' ? " Notiz: {$notes}" : '');
        $fieldsToUpdate = [];
        foreach ($customFields as $field) {
            $fieldId   = $field['id']   ?? null;
            $fieldName = $field['customField']['name'] ?? '';

            if ($fieldId === null) {
                continue;
            }

            if ($fieldName === 'Aktuelle Ausleiher') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $namesValue];
            } elseif ($fieldName === 'Entra E-Mail') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $emailsValue];
            } elseif ($fieldName === 'Zustand der letzten Rückgabe') {
                $fieldsToUpdate[] = ['id' => $fieldId, 'value' => $conditionText];
            }
        }

        if (!empty($fieldsToUpdate)) {
            $this->request('PATCH', $objectUrl . '/custom-fields/bulk-update', $fieldsToUpdate);
        }

        // 5. Update local DB status to 'returned' (only after all API calls succeed)
        $upd = $db->prepare(
            "UPDATE inventory_requests
                SET status = 'returned', returned_condition = ?, return_notes = ?, returned_at = NOW()
              WHERE id = ?"
        );
        $upd->execute([$condition, $notes !== '' ? $notes : null, $requestId]);

        error_log(sprintf(
            'EasyVereinInventory: request %d verified as returned by %s (condition: %s), inventory object %s',
            $requestId, $adminName, $condition, $req['inventory_object_id']
        ));
    }

    /**
     * Resolve a display name to an EasyVerein contact ID.
     *
     * Calls GET /api/v2.0/contact-details?search={name} and returns the id of
     * the first matching contact as an integer.
     *
     * @param string $name Display name to look up
     * @return int EasyVerein contact ID
     * @throws Exception If no contact is found for the given name
     */
    private function getContactIdByName(string $name): int {
        $url  = self::API_BASE . '/contact-details?search=' . urlencode($name);
        $data = $this->request('GET', $url);

        $results = $data['results'] ?? $data['data'] ?? [];

        if (!empty($results) && isset($results[0]['id'])) {
            return (int)$results[0]['id'];
        }

        throw new Exception(
            'Nutzer nicht im easyVerein gefunden. Der Name (' . $name . ') muss in easyVerein existieren.'
        );
    }

    /**
     * Create a lending record in EasyVerein.
     *
     * Calls POST /api/v2.0/lending with the required fields for a new loan.
     *
     * @param int|string $parentInventoryObject EasyVerein inventory-object ID
     * @param int        $borrowAddress         EasyVerein contact ID of the borrower
     * @param int        $quantity              Number of units to lend
     * @param string     $borrowingDate         Start date of the loan (YYYY-MM-DD)
     * @param string     $returnDate            Expected return date (YYYY-MM-DD)
     * @return array API response data
     * @throws Exception On API or validation errors
     */
    public function createLending($parentInventoryObject, int $borrowAddress, int $quantity, string $borrowingDate, string $returnDate): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        $url     = self::API_BASE . '/lending';
        $payload = [
            'parentInventoryObject' => (string)$parentInventoryObject,
            'borrowAddress'         => $borrowAddress,
            'quantity'              => $quantity,
            'borrowingDate'         => substr($borrowingDate, 0, 10),
            'returnDate'            => substr($returnDate, 0, 10),
        ];

        $result = $this->request('POST', $url, $payload);

        error_log(sprintf(
            'EasyVereinInventory: lending created for item %s, borrower %s (qty %d, %s – %s)',
            $parentInventoryObject, $borrowAddress, $quantity, $borrowingDate, $returnDate
        ));

        return $result;
    }
}
