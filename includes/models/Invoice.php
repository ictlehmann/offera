<?php
/**
 * Invoice Model
 * Manages invoice data and operations with security controls
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/MailService.php';

class Invoice {
    
    /**
     * Upload directory for invoices
     */
    private const UPLOAD_DIR = 'uploads/invoices/';
    
    /**
     * Allowed MIME types for invoice uploads (PDF, JPG, PNG, HEIC)
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/heic',
        'image/heif'
    ];
    
    /**
     * Allowed file extensions for invoice uploads (whitelist)
     */
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif'
    ];
    
    /**
     * Maximum file size for invoices (10MB)
     */
    private const MAX_FILE_SIZE = 10485760;
    
    /**
     * Create a new invoice
     * 
     * @param int $userId User ID who is creating the invoice
     * @param array $data Invoice data (description, amount)
     * @param array $file The $_FILES array element
     * @return array ['success' => bool, 'id' => int|null, 'error' => string|null]
     */
    public static function create($userId, $data, $file) {
        // Validate file upload
        $uploadResult = self::handleFileUpload($file);
        if (!$uploadResult['success']) {
            return [
                'success' => false,
                'id' => null,
                'error' => $uploadResult['error']
            ];
        }
        
        try {
            // Insert into database
            $db = Database::getConnection('rech');
            $stmt = $db->prepare("
                INSERT INTO invoices (user_id, description, amount, file_path, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $userId,
                $data['description'] ?? '',
                $data['amount'] ?? 0,
                $uploadResult['path']
            ]);
            
            $invoiceId = $db->lastInsertId();
            
            // Send notification email
            self::sendNotification($data, $uploadResult['path']);
            
            return [
                'success' => true,
                'id' => $invoiceId,
                'error' => null
            ];
            
        } catch (Exception $e) {
            error_log("Error creating invoice: " . $e->getMessage());
            // Clean up uploaded file if database insertion failed
            if (isset($uploadResult['path']) && file_exists(__DIR__ . '/../../' . $uploadResult['path'])) {
                unlink(__DIR__ . '/../../' . $uploadResult['path']);
            }
            return [
                'success' => false,
                'id' => null,
                'error' => 'Fehler beim Erstellen der Rechnung'
            ];
        }
    }
    
    /**
     * Handle file upload with validation
     * 
     * @param array $file The $_FILES array element
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    private static function handleFileUpload($file) {
        // Check if file was uploaded
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Keine Datei hochgeladen oder Upload-Fehler'
            ];
        }
        
        // Validate file size (10MB)
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist zu groß. Maximum: 10MB'
            ];
        }
        
        // Validate file extension using strict whitelist (use basename() to prevent path traversal)
        $originalExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        if (!in_array($originalExtension, self::ALLOWED_EXTENSIONS)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültige Dateiendung. Erlaubt: PDF, JPG, JPEG, PNG, HEIC, HEIF.'
            ];
        }
        
        // Validate MIME type using finfo_file() - NOT $_FILES['type'] which can be faked
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültiger Dateityp. Erlaubt: JPG, PNG, HEIC, PDF. Erkannt: ' . $mimeType
            ];
        }
        
        // Determine upload directory
        $uploadDir = __DIR__ . '/../../' . self::UPLOAD_DIR;
        
        // Ensure upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Upload-Verzeichnis konnte nicht erstellt werden'
                ];
            }
        }
        
        if (!is_writable($uploadDir)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Upload-Verzeichnis ist nicht beschreibbar'
            ];
        }
        
        // Get file extension from MIME type
        $extension = self::getExtensionFromMimeType($mimeType);
        
        // Generate secure random filename with timestamp for tracking
        $timestamp = date('Ymd_His');
        $randomFilename = 'invoice_' . $timestamp . '_' . bin2hex(random_bytes(8)) . $extension;
        $uploadPath = $uploadDir . $randomFilename;
        
        // Move uploaded file to destination
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Fehler beim Hochladen der Datei'
            ];
        }
        
        // Set proper permissions
        chmod($uploadPath, 0644);
        
        // Return relative path for database storage
        $relativePath = self::UPLOAD_DIR . $randomFilename;
        
        return [
            'success' => true,
            'path' => $relativePath,
            'error' => null
        ];
    }
    
    /**
     * Get file extension from MIME type
     * 
     * @param string $mimeType MIME type
     * @return string File extension with dot
     */
    private static function getExtensionFromMimeType($mimeType) {
        $extensions = [
            'application/pdf' => '.pdf',
            'image/jpeg' => '.jpg',
            'image/jpg' => '.jpg',
            'image/png' => '.png',
            'image/heic' => '.heic',
            'image/heif' => '.heic'
        ];
        
        return $extensions[$mimeType] ?? '.bin';
    }
    
    /**
     * Send notification email about new invoice
     * 
     * @param array $data Invoice data
     * @param string $filePath Path to uploaded file
     * @return bool Success status
     */
    private static function sendNotification($data, $filePath) {
        try {
            $description = $data['description'] ?? 'N/A';
            $amount = $data['amount'] ?? 0;
            
            $subject = 'Neue Rechnung eingereicht: ' . $description . ' - ' . number_format($amount, 2, ',', '.') . '€';
            
            // Build email body
            $bodyContent = '<p class="email-text">Eine neue Rechnung wurde eingereicht:</p>';
            $bodyContent .= '<table class="info-table">';
            $bodyContent .= '<tr><td><strong>Beschreibung</strong></td><td>' . htmlspecialchars($description) . '</td></tr>';
            $bodyContent .= '<tr><td><strong>Betrag</strong></td><td>' . number_format($amount, 2, ',', '.') . '€</td></tr>';
            $bodyContent .= '</table>';
            
            $htmlBody = MailService::getTemplate('Neue Rechnung', $bodyContent);
            
            // Get absolute file path for attachment
            $absoluteFilePath = __DIR__ . '/../../' . $filePath;
            
            // Send email with attachment
            return MailService::sendEmailWithFileAttachment(
                INVOICE_NOTIFICATION_EMAIL,
                $subject,
                $htmlBody,
                $absoluteFilePath
            );
            
        } catch (Exception $e) {
            error_log('Failed to send invoice notification email: ' . $e->getMessage());
            // Don't fail the invoice creation if email fails
            return false;
        }
    }
    
    /**
     * Get all invoices with role-based filtering
     * 
     * @param string $userRole User role (board, alumni_board, head, member)
     * @param int $currentUserId Current user ID
     * @return array Array of invoices
     */
    public static function getAll($userRole, $currentUserId) {
        $db = Database::getConnection('rech');
        
        // Board roles (board_finance, board_internal, board_external), alumni_board, and alumni_auditor see all invoices
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_board', 'alumni_auditor']))) {
            // Join with users table from user database
            $userDb = Database::getUserDB();
            
            // Get all invoices
            $stmt = $db->prepare("
                SELECT 
                    i.id,
                    i.user_id,
                    i.description,
                    i.amount,
                    i.status,
                    i.file_path,
                    i.rejection_reason,
                    i.paid_at,
                    i.paid_by_user_id,
                    i.created_at,
                    i.updated_at
                FROM invoices i
                ORDER BY 
                    CASE i.status 
                        WHEN 'pending' THEN 1 
                        WHEN 'approved' THEN 2 
                        WHEN 'rejected' THEN 3 
                    END,
                    i.created_at DESC
            ");
            $stmt->execute();
            $invoices = $stmt->fetchAll();
            
            // Fetch user emails for each invoice
            foreach ($invoices as &$invoice) {
                $userStmt = $userDb->prepare("SELECT email FROM users WHERE id = ?");
                $userStmt->execute([$invoice['user_id']]);
                $user = $userStmt->fetch();
                $invoice['user_email'] = $user ? $user['email'] : 'Unknown';
            }
            
            return $invoices;
        }
        
        // Head role sees only their own invoices
        if ($userRole === 'head') {
            $stmt = $db->prepare("
                SELECT 
                    id,
                    user_id,
                    description,
                    amount,
                    status,
                    file_path,
                    rejection_reason,
                    paid_at,
                    paid_by_user_id,
                    created_at,
                    updated_at
                FROM invoices
                WHERE user_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$currentUserId]);
            return $stmt->fetchAll();
        }
        
        // Other roles see no invoices
        return [];
    }
    
    /**
     * Update invoice status
     * Only board members are allowed to update invoice status
     * 
     * @param int $invoiceId Invoice ID
     * @param string $status New status (pending, approved, rejected)
     * @param string|null $reason Optional reason for status change
     * @param string $userRole Current user's role (must be 'board')
     * @return bool Success status
     */
    public static function updateStatus($invoiceId, $status, $reason = null, $userRole = null) {
        // Only board members can update status
        if (!in_array($userRole, Auth::BOARD_ROLES)) {
            error_log("Unauthorized attempt to update invoice status by role: " . ($userRole ?? 'unknown'));
            return false;
        }
        
        try {
            $db = Database::getConnection('rech');
            
            // Validate status
            $validStatuses = ['pending', 'approved', 'rejected'];
            if (!in_array($status, $validStatuses)) {
                return false;
            }
            
            $stmt = $db->prepare("
                UPDATE invoices
                SET status = ?, rejection_reason = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([$status, $reason, $invoiceId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error updating invoice status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate invoice statistics
     * 
     * @return array ['total_pending' => float, 'total_paid' => float, 'monthly_paid' => float]
     */
    public static function getStats() {
        try {
            $db = Database::getConnection('rech');
            
            // Calculate total pending amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM invoices
                WHERE status = 'pending'
            ");
            $stmt->execute();
            $pendingResult = $stmt->fetch();
            $totalPending = (float) $pendingResult['total'];
            
            // Calculate total paid/approved amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM invoices
                WHERE status = 'approved'
            ");
            $stmt->execute();
            $paidResult = $stmt->fetch();
            $totalPaid = (float) $paidResult['total'];
            
            // Calculate this month's approved amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM invoices
                WHERE status = 'approved'
                AND YEAR(updated_at) = YEAR(CURRENT_DATE)
                AND MONTH(updated_at) = MONTH(CURRENT_DATE)
            ");
            $stmt->execute();
            $monthlyResult = $stmt->fetch();
            $monthlyPaid = (float) $monthlyResult['total'];
            
            return [
                'total_pending' => $totalPending,
                'total_paid' => $totalPaid,
                'monthly_paid' => $monthlyPaid
            ];
            
        } catch (Exception $e) {
            error_log("Error calculating invoice stats: " . $e->getMessage());
            return [
                'total_pending' => 0,
                'total_paid' => 0,
                'monthly_paid' => 0
            ];
        }
    }
    
    /**
     * Get invoice by ID
     * 
     * @param int $id Invoice ID
     * @return array|null Invoice data or null if not found
     */
    public static function getById($id) {
        try {
            $db = Database::getConnection('rech');
            $stmt = $db->prepare("
                SELECT 
                    id,
                    user_id,
                    description,
                    amount,
                    status,
                    file_path,
                    rejection_reason,
                    paid_at,
                    paid_by_user_id,
                    created_at,
                    updated_at
                FROM invoices
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error fetching invoice: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Mark invoice as paid
     * Can only be called by board members with 'Finanzen und Recht' position
     * 
     * @param int $invoiceId The invoice ID
     * @param int $userId The user ID marking as paid
     * @return bool True on success
     */
    public static function markAsPaid($invoiceId, $userId) {
        try {
            $db = Database::getConnection('rech');
            
            $stmt = $db->prepare("
                UPDATE invoices
                SET status = 'paid', 
                    paid_at = NOW(), 
                    paid_by_user_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            return $stmt->execute([$userId, $invoiceId]);
            
        } catch (Exception $e) {
            error_log("Error marking invoice as paid: " . $e->getMessage());
            return false;
        }
    }
}
