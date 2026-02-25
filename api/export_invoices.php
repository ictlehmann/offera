<?php
/**
 * API: Export Invoices
 * Creates a ZIP file containing all invoice files for board members
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';

// Check authentication (Auth::check() calls init_session() which ensures secure session)
if (!Auth::check()) {
    header('Location: ../pages/auth/login.php');
    exit;
}

$user = Auth::user();

// Only board members, alumni_board, and alumni_auditor can export invoices
// Check if user has permission to view invoices
$hasInvoiceAccess = Auth::isBoard() || Auth::hasRole(['alumni_board', 'alumni_auditor']);
if (!$hasInvoiceAccess) {
    header('Location: ../pages/dashboard/index.php');
    exit;
}

$userRole = $user['role'] ?? '';

// Get all invoices
$invoices = Invoice::getAll($userRole, $user['id']);

// Session is available for error messages (initialized by Auth::check())
if (empty($invoices)) {
    $_SESSION['error_message'] = 'Keine Rechnungen zum Exportieren vorhanden';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Create a temporary directory for the ZIP file
$tempDir = sys_get_temp_dir();
$zipFileName = 'rechnungen_export_' . date('Y-m-d_H-i-s') . '.zip';
$zipFilePath = $tempDir . '/' . $zipFileName;

// Create ZIP archive
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    $_SESSION['error_message'] = 'Fehler beim Erstellen der ZIP-Datei';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Add files to ZIP
$fileCount = 0;
foreach ($invoices as $invoice) {
    if (!empty($invoice['file_path'])) {
        $filePath = __DIR__ . '/../' . $invoice['file_path'];
        
        if (file_exists($filePath)) {
            // Create a meaningful filename
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $safeDescription = preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($invoice['description'], 0, 50));
            $amountCents = (int)($invoice['amount'] * 100);
            $newFileName = sprintf(
                '%s_%s_%s_%dc.%s',
                date('Y-m-d', strtotime($invoice['created_at'])),
                $invoice['id'],
                $safeDescription,
                $amountCents,
                $extension
            );
            
            $zip->addFile($filePath, $newFileName);
            $fileCount++;
        }
    }
}

$zip->close();

// Check if any files were added
if ($fileCount === 0) {
    unlink($zipFilePath);
    $_SESSION['error_message'] = 'Keine Dateien zum Exportieren gefunden';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Send ZIP file to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
header('Content-Length: ' . filesize($zipFilePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

readfile($zipFilePath);

// Clean up temporary file
unlink($zipFilePath);
exit;
