<?php
/**
 * EasyVereinSync Service
 * Handles one-way synchronization from EasyVerein (External) to Intranet (Local)
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/MailService.php';

class EasyVereinSync {
    
    /**
     * Fetch data from EasyVerein API
     * 
     * @return array Array of inventory items from EasyVerein API
     * @throws Exception If API call fails
     */
    public function fetchDataFromEasyVerein() {
        $apiUrl = 'https://easyverein.com/api/v2.0/inventory-object?limit=100';
        // Get API token from config
        $apiToken = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        
        if (empty($apiToken)) {
            throw new Exception('EasyVerein API token not configured');
        }
        
        try {
            // Initialize cURL
            $ch = curl_init();
            
            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            // Execute the request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Check for cURL errors
            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }
            
            // Check HTTP status code
            if ($httpCode !== 200) {
                $errorMsg = "API returned HTTP {$httpCode}";
                if ($httpCode === 401) {
                    $errorMsg .= ' - Unauthorized: Invalid API token';
                }
                throw new Exception($errorMsg);
            }
            
            // Parse JSON response
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
            }
            
            // EasyVerein API typically returns data in a wrapper
            // Adjust based on actual API response structure
            $items = $data['results'] ?? $data['data'] ?? $data;
            
            if (!is_array($items)) {
                throw new Exception('Invalid API response format: expected array of items');
            }
            
            return $items;
            
        } catch (Exception $e) {
            // Log the error
            error_log('EasyVerein API Error: ' . $e->getMessage());
            
            // Send critical alert email
            $this->sendCriticalAlert($e->getMessage());
            
            // Re-throw the exception
            throw $e;
        }
    }
    
    /**
     * Send critical alert email when API sync fails
     * 
     * @param string $errorMessage The error message to include in email
     */
    private function sendCriticalAlert($errorMessage) {
        $subject = 'CRITICAL: EasyVerein Sync Failed';
        
        $bodyContent = '<p class="email-text">The EasyVerein API synchronization has failed.</p>';
        $bodyContent .= '<p class="email-text"><strong>Error Details:</strong></p>';
        $bodyContent .= '<div style="background-color: #fee; padding: 15px; border-left: 4px solid #c00; margin: 15px 0;">';
        $bodyContent .= '<pre style="margin: 0; font-family: monospace; white-space: pre-wrap;">' . htmlspecialchars($errorMessage) . '</pre>';
        $bodyContent .= '</div>';
        $bodyContent .= '<p class="email-text">Time: ' . date('Y-m-d H:i:s') . '</p>';
        $bodyContent .= '<p class="email-text">Please investigate and resolve this issue as soon as possible.</p>';
        
        // Get email template
        $htmlBody = MailService::getTemplate('EasyVerein Sync Failure', $bodyContent);
        
        // Send email
        try {
            MailService::sendEmail(defined('INVENTORY_BOARD_EMAIL') ? INVENTORY_BOARD_EMAIL : SMTP_FROM, $subject, $htmlBody);
        } catch (Exception $e) {
            error_log('Failed to send critical alert email: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract image URL from EasyVerein API item data
     * 
     * @param array $evItem EasyVerein API item data
     * @return string|null EasyVerein image URL or null if no image
     */
    private function extractImageUrl($evItem) {
        // Check for image in various possible field names
        $imageUrl = null;
        
        // Check 'picture' field first (as per requirements)
        if (isset($evItem['picture']) && !empty($evItem['picture'])) {
            $imageUrl = $evItem['picture'];
        }
        // Check common field names for image
        elseif (isset($evItem['image']) && !empty($evItem['image'])) {
            $imageUrl = $evItem['image'];
        } elseif (isset($evItem['avatar']) && !empty($evItem['avatar'])) {
            $imageUrl = $evItem['avatar'];
        } elseif (isset($evItem['image_path']) && !empty($evItem['image_path'])) {
            $imageUrl = $evItem['image_path'];
        } elseif (isset($evItem['image_url']) && !empty($evItem['image_url'])) {
            $imageUrl = $evItem['image_url'];
        }
        
        // Check in custom_fields if exists
        if (!$imageUrl && isset($evItem['custom_fields']) && is_array($evItem['custom_fields'])) {
            foreach ($evItem['custom_fields'] as $field) {
                if (isset($field['name']) && in_array(strtolower($field['name']), ['picture', 'image', 'avatar', 'bild', 'foto'])) {
                    if (isset($field['value']) && !empty($field['value'])) {
                        $imageUrl = $field['value'];
                        break;
                    }
                }
            }
        }
        
        // Return the EasyVerein URL directly (not downloaded)
        return $imageUrl;
    }
    
    /**
     * Synchronize inventory from EasyVerein to local database
     * 
     * This method:
     * 1. Fetches data from EasyVerein
     * 2. For each item:
     *    - If exists locally (by easyverein_id): Updates master data fields
     *    - If not exists: Creates new inventory record
     * 3. For deletions: Marks items with easyverein_id not in fetch result as archived
     * 
     * @param int $userId User ID performing the sync (for audit trail)
     * @return array Result with statistics (created, updated, archived, errors)
     */
    public function sync($userId = null) {
        $db = Database::getContentDB();
        
        // If no userId provided, use system user (0)
        if ($userId === null) {
            $userId = 0;
        }
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'archived' => 0,
            'errors' => []
        ];
        
        try {
            // Fetch data from EasyVerein
            $easyvereinItems = $this->fetchDataFromEasyVerein();
            
            // Track EasyVerein IDs that are present in this sync
            $currentEasyVereinIds = [];
            
            // Process each item from EasyVerein
            foreach ($easyvereinItems as $evItem) {
                try {
                    // Map API fields to our expected format
                    // Map: name -> name, note -> description, pieces -> quantity (DB: quantity), 
                    // acquisitionPrice -> unit_price, locationName -> location (for future mapping), picture -> image_path
                    $easyvereinId = $evItem['id'] ?? $evItem['EasyVereinID'] ?? null;
                    $name = $evItem['name'] ?? $evItem['Name'] ?? 'Unnamed Item';
                    $description = $evItem['note'] ?? $evItem['description'] ?? $evItem['Description'] ?? '';
                    $totalQuantity = $evItem['pieces'] ?? $evItem['quantity'] ?? $evItem['total_stock'] ?? $evItem['TotalQuantity'] ?? 0;
                    // Use acquisitionPrice as primary (original purchase price per requirements), fall back to price
                    $unitPrice = $evItem['acquisitionPrice'] ?? $evItem['price'] ?? $evItem['unit_price'] ?? 0;
                    $serialNumber = $evItem['serial_number'] ?? $evItem['SerialNumber'] ?? null;
                    // Extract location name (stored for future use - requires mapping to location_id)
                    $locationName = $evItem['locationName'] ?? $evItem['location'] ?? null;
                    
                    // Extract image URL (do NOT download - save URL directly)
                    $imageUrl = $this->extractImageUrl($evItem);
                    
                    if (!$easyvereinId) {
                        $stats['errors'][] = "Skipping item without ID: " . ($name ?? 'Unknown');
                        continue;
                    }
                    
                    $currentEasyVereinIds[] = $easyvereinId;
                    
                    // Check if item exists locally by easyverein_id
                    $stmt = $db->prepare("
                        SELECT id, name, description, quantity, serial_number
                        FROM inventory_items
                        WHERE easyverein_id = ?
                    ");
                    $stmt->execute([$easyvereinId]);
                    $existingItem = $stmt->fetch();
                    
                    if ($existingItem) {
                        // Update existing item using Inventory model with sync flag
                        // This allows the update to bypass Master Data protection
                        $updateData = [
                            'name' => $name,
                            'description' => $description,
                            'quantity' => $totalQuantity,
                            'unit_price' => $unitPrice,
                            'serial_number' => $serialNumber,
                            'is_archived_in_easyverein' => 0
                        ];
                        
                        // Always update image URL if provided (EasyVerein URL, not downloaded)
                        if ($imageUrl) {
                            $updateData['image_path'] = $imageUrl;
                        }
                        
                        // Use Inventory::update() with $isSyncUpdate = true to bypass protection
                        Inventory::update($existingItem['id'], $updateData, $userId, true);
                        
                        // Update last_synced_at separately using MySQL NOW() for timezone consistency
                        $stmt = $db->prepare("UPDATE inventory_items SET last_synced_at = NOW() WHERE id = ?");
                        $stmt->execute([$existingItem['id']]);
                        
                        $stats['updated']++;
                        
                        // Log update in history
                        $this->logSyncHistory(
                            $existingItem['id'],
                            $userId,
                            'sync_update',
                            $existingItem['quantity'],
                            $totalQuantity,
                            'Synchronized from EasyVerein',
                            json_encode([
                                'old_name' => $existingItem['name'],
                                'new_name' => $name,
                                'easyverein_id' => $easyvereinId
                            ])
                        );
                        
                    } else {
                        // Create new item with explicit field list for security
                        $stmt = $db->prepare("
                            INSERT INTO inventory_items (
                                easyverein_id,
                                name,
                                description,
                                serial_number,
                                quantity,
                                unit_price,
                                image_path,
                                is_archived_in_easyverein,
                                last_synced_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
                        ");
                        
                        $stmt->execute([
                            $easyvereinId,
                            $name,
                            $description,
                            $serialNumber,
                            $totalQuantity,
                            $unitPrice,
                            $imageUrl
                        ]);
                        
                        $newItemId = $db->lastInsertId();
                        $stats['created']++;
                        
                        // Log creation in history
                        $this->logSyncHistory(
                            $newItemId,
                            $userId,
                            'sync_create',
                            null,
                            $totalQuantity,
                            'Created from EasyVerein sync',
                            json_encode([
                                'easyverein_id' => $easyvereinId,
                                'name' => $name
                            ])
                        );
                    }
                    
                } catch (Exception $e) {
                    $stats['errors'][] = "Error processing item '" . ($name ?? 'Unknown') . "' (EV-ID: " . ($easyvereinId ?? 'N/A') . "): " . $e->getMessage();
                }
            }
            
            // Handle deletions: Mark items with easyverein_id NOT in current fetch as archived
            // This should run even if currentEasyVereinIds is empty (i.e., EasyVerein returns no items)
            if (!empty($currentEasyVereinIds)) {
                $placeholders = str_repeat('?,', count($currentEasyVereinIds) - 1) . '?';
                
                // Find items with easyverein_id that are not in the current sync
                $stmt = $db->prepare("
                    SELECT id, easyverein_id, name
                    FROM inventory_items
                    WHERE easyverein_id IS NOT NULL
                    AND easyverein_id NOT IN ($placeholders)
                    AND is_archived_in_easyverein = 0
                ");
                $stmt->execute($currentEasyVereinIds);
                $itemsToArchive = $stmt->fetchAll();
            } else {
                // If EasyVerein returns no items, archive all items with easyverein_id
                $stmt = $db->prepare("
                    SELECT id, easyverein_id, name
                    FROM inventory_items
                    WHERE easyverein_id IS NOT NULL
                    AND is_archived_in_easyverein = 0
                ");
                $stmt->execute();
                $itemsToArchive = $stmt->fetchAll();
            }
            
            // Archive items not found in current sync
            foreach ($itemsToArchive as $item) {
                // Mark as archived (soft delete)
                $stmt = $db->prepare("
                    UPDATE inventory_items
                    SET is_archived_in_easyverein = 1,
                        last_synced_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$item['id']]);
                
                $stats['archived']++;
                
                // Log archival
                $this->logSyncHistory(
                    $item['id'],
                    $userId,
                    'sync_archive',
                    null,
                    null,
                    'Archived - no longer in EasyVerein',
                    json_encode([
                        'easyverein_id' => $item['easyverein_id'],
                        'name' => $item['name']
                    ])
                );
            }
            
        } catch (Exception $e) {
            $stats['errors'][] = "Sync failed: " . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Log synchronization history
     * 
     * @param int $itemId Inventory item ID
     * @param int $userId User ID performing the action
     * @param string $changeType Type of change (sync_create, sync_update, sync_archive)
     * @param mixed $oldStock Old stock value
     * @param mixed $newStock New stock value
     * @param string $reason Reason for the change
     * @param string $comment Additional comment/data
     */
    private function logSyncHistory($itemId, $userId, $changeType, $oldStock, $newStock, $reason, $comment) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO inventory_history (
                item_id,
                user_id,
                change_type,
                old_stock,
                new_stock,
                change_amount,
                reason,
                comment
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $changeAmount = null;
        if ($oldStock !== null && $newStock !== null) {
            $changeAmount = $newStock - $oldStock;
        }
        
        $stmt->execute([
            $itemId,
            $userId,
            $changeType,
            $oldStock,
            $newStock,
            $changeAmount,
            $reason,
            $comment
        ]);
    }
    
    /**
     * Update item in EasyVerein (Write-Back)
     * 
     * Sends a PATCH request to EasyVerein API to update an inventory item
     * 
     * @param int $easyvereinId EasyVerein item ID
     * @param array $data Fields to update (name, quantity, note/description)
     * @return array Result with success status and any error messages
     */
    public static function updateItem($easyvereinId, $data) {
        $apiUrl = "https://easyverein.com/api/v2.0/inventory-object/{$easyvereinId}";
        // Get API token from config
        $apiToken = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        
        if (empty($apiToken)) {
            throw new Exception('EasyVerein API token not configured');
        }
        
        try {
            // Map our fields to EasyVerein API fields
            $apiData = [];
            if (isset($data['name'])) {
                $apiData['name'] = $data['name'];
            }
            if (isset($data['quantity'])) {
                $apiData['pieces'] = $data['quantity'];
            }
            if (isset($data['description'])) {
                $apiData['note'] = $data['description'];
            }
            if (isset($data['unit_price'])) {
                $apiData['price'] = $data['unit_price'];
            }
            
            // Initialize cURL
            $ch = curl_init();
            
            // Set cURL options for PATCH request
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            // Execute the request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Check for cURL errors
            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }
            
            // Check HTTP status code (200 or 204 for successful PATCH)
            if ($httpCode !== 200 && $httpCode !== 204) {
                $errorMsg = "API returned HTTP {$httpCode}";
                if ($httpCode === 401) {
                    $errorMsg .= ' - Unauthorized: Invalid API token';
                } elseif ($httpCode === 404) {
                    $errorMsg .= ' - Not Found: Item not found in EasyVerein';
                }
                throw new Exception($errorMsg);
            }
            
            return [
                'success' => true,
                'message' => 'Item updated in EasyVerein successfully'
            ];
            
        } catch (Exception $e) {
            // Log the error
            error_log('EasyVerein API Update Error (ID: ' . $easyvereinId . '): ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
