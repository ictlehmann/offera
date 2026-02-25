<?php
/**
 * API: Upload Avatar / Profile Picture
 * Accepts a base64-encoded JPEG from Cropper.js, saves it to
 * uploads/profile_photos/ and updates the user's image_path in the DB.
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/Member.php';
require_once __DIR__ . '/../includes/models/Alumni.php';
require_once __DIR__ . '/../includes/utils/SecureImageUpload.php';

try {
    // Authentication check
    if (!Auth::check()) {
        echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    // Read JSON body
    $body = json_decode(file_get_contents('php://input'), true);
    $base64Data = $body['image'] ?? '';

    if (empty($base64Data)) {
        echo json_encode(['success' => false, 'message' => 'Kein Bild übermittelt']);
        exit;
    }

    // Validate format: data:image/<type>;base64,<data>
    if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/s', $base64Data, $matches)) {
        echo json_encode(['success' => false, 'message' => 'Ungültiges Bildformat']);
        exit;
    }

    $imageData = base64_decode($matches[2]);
    if ($imageData === false || strlen($imageData) === 0) {
        echo json_encode(['success' => false, 'message' => 'Bildverarbeitung fehlgeschlagen']);
        exit;
    }

    // Enforce 5 MB size limit
    if (strlen($imageData) > 5242880) {
        echo json_encode(['success' => false, 'message' => 'Bild ist zu groß. Maximum: 5MB']);
        exit;
    }

    // Write to a temp file for MIME validation
    $tmpFile = tempnam(sys_get_temp_dir(), 'avatar_');
    file_put_contents($tmpFile, $imageData);

    $uploadPath = null;
    try {
        // Validate actual MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($actualMime, $allowedMimes, true)) {
            throw new RuntimeException('Ungültiger Bildtyp');
        }

        // Ensure it is a real image
        if (@getimagesize($tmpFile) === false) {
            throw new RuntimeException('Datei ist kein gültiges Bild');
        }

        // Prepare upload directory
        $uploadDir = __DIR__ . '/../uploads/profile_photos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $ext = $extMap[$actualMime] ?? 'jpg';
        $filename = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $uploadPath = $uploadDir . $filename;

        if (!copy($tmpFile, $uploadPath)) {
            throw new RuntimeException('Fehler beim Speichern des Profilbildes');
        }
        chmod($uploadPath, 0644);

        // Build relative path for DB storage (relative to project root)
        $projectRoot = realpath(__DIR__ . '/..');
        $realUploadPath = realpath($uploadPath);
        $relativePath = str_replace('\\', '/', substr($realUploadPath, strlen($projectRoot) + 1));

        // Load current profile to delete old image
        $user = Auth::user();
        $userId = $user['id'];
        $userRole = $user['role'] ?? '';

        $existingProfile = Member::getProfileByUserId($userId);
        if ($existingProfile && !empty($existingProfile['image_path'])) {
            SecureImageUpload::deleteImage($existingProfile['image_path']);
        }

        // Update image_path using the model appropriate for the user's role
        if (isMemberRole($userRole)) {
            $updateSuccess = Member::updateProfile($userId, ['image_path' => $relativePath]);
        } else {
            $updateSuccess = Alumni::updateOrCreateProfile($userId, ['image_path' => $relativePath]);
        }
        if (!$updateSuccess) {
            throw new RuntimeException('Datenbankfehler beim Aktualisieren des Profilbildes');
        }

        echo json_encode(['success' => true, 'image_path' => $relativePath]);

    } catch (RuntimeException $e) {
        // Clean up the uploaded file if the DB update failed
        if ($uploadPath !== null && file_exists($uploadPath)) {
            @unlink($uploadPath);
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } finally {
        @unlink($tmpFile);
    }

} catch (Exception $e) {
    error_log('upload_avatar.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server-Fehler']);
}

