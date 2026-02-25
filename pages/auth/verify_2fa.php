<?php
/**
 * Two-Factor Authentication Verification Page
 * 
 * This page is shown after successful Microsoft login when 2FA is enabled.
 * Users must enter their 2FA code to complete authentication.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/User.php';

// Start session
AuthHandler::startSession();

// Check if user has a pending 2FA verification
if (!isset($_SESSION['pending_2fa_user_id'])) {
    // No pending 2FA, redirect to login
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// Handle 2FA verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    $code = $_POST['code'] ?? '';
    
    if (empty($code)) {
        $error = 'Bitte geben Sie den 2FA-Code ein.';
    } else {
        // Get user from pending session
        $userId = $_SESSION['pending_2fa_user_id'];
        
        // Fetch user's 2FA secret
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT tfa_secret, two_factor_secret FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $error = 'Benutzer nicht gefunden.';
        } else {
            // Use tfa_secret or two_factor_secret (whichever is set)
            $tfaSecret = $user['tfa_secret'] ?? $user['two_factor_secret'] ?? null;
            
            if (empty($tfaSecret)) {
                $error = '2FA ist nicht korrekt konfiguriert. Bitte kontaktieren Sie den Administrator.';
            } else {
                // Verify 2FA code
                $ga = new PHPGangsta_GoogleAuthenticator();
                
                if ($ga->verifyCode($tfaSecret, $code, 2)) {
                    // 2FA verified successfully
                    // Set session variables from pending data
                    $_SESSION['user_id'] = $_SESSION['pending_2fa_user_id'];
                    $_SESSION['user_email'] = $_SESSION['pending_2fa_email'];
                    $_SESSION['user_role'] = $_SESSION['pending_2fa_role'];
                    $_SESSION['authenticated'] = true;
                    $_SESSION['last_activity'] = time();
                    
                    // Set profile_incomplete flag from pending session data
                    $_SESSION['profile_incomplete'] = (intval($_SESSION['pending_2fa_profile_complete'] ?? 1) === 0);

                    // Clear pending 2FA data
                    unset($_SESSION['pending_2fa_user_id']);
                    unset($_SESSION['pending_2fa_email']);
                    unset($_SESSION['pending_2fa_role']);
                    unset($_SESSION['pending_2fa_profile_complete']);

                    // Update last login
                    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$userId]);

                    // Regenerate session ID to prevent session fixation attacks
                    session_regenerate_id(true);
                    // Store current session ID in database for single-session enforcement
                    $stmt = $db->prepare("UPDATE users SET current_session_id = ? WHERE id = ?");
                    $stmt->execute([session_id(), $userId]);
                    
                    // Log successful 2FA verification
                    AuthHandler::logSystemAction($userId, 'login_2fa_success', 'user', $userId, '2FA verification successful');
                    
                    // Redirect to dashboard
                    $dashboardUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php';
                    header('Location: ' . $dashboardUrl);
                    exit;
                } else {
                    // Invalid 2FA code
                    $error = 'Ungültiger 2FA-Code. Bitte versuchen Sie es erneut.';
                    
                    // Log failed 2FA attempt
                    AuthHandler::logSystemAction($userId, 'login_2fa_failed', 'user', $userId, 'Invalid 2FA code entered');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verifizierung - IBC Intranet</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            max-width: 450px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .content {
            padding: 40px 30px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            font-family: monospace;
            letter-spacing: 3px;
            text-align: center;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .help-text {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
        }

        .help-text a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .help-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">🔐</div>
            <h1>Zwei-Faktor-Authentifizierung</h1>
            <p>Geben Sie den 6-stelligen Code aus Ihrer Authenticator-App ein</p>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="code">Authentifizierungscode</label>
                    <input 
                        type="text" 
                        id="code" 
                        name="code" 
                        maxlength="6" 
                        pattern="[0-9]{6}" 
                        placeholder="000000" 
                        required 
                        autofocus
                        autocomplete="off"
                    >
                </div>
                
                <button type="submit" name="verify_2fa" class="btn">
                    Verifizieren
                </button>
            </form>
            
            <div class="help-text">
                Probleme beim Einloggen?<br>
                <a href="logout.php">Zurück zum Login</a>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit form when 6 digits are entered
        document.getElementById('code').addEventListener('input', function(e) {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Auto-submit when 6 digits are entered
            if (this.value.length === 6) {
                // Small delay for better UX
                setTimeout(() => {
                    this.form.submit();
                }, 300);
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmLWYePkbKfTkJXaJ4pYoEnJaSRh" crossorigin="anonymous"></script>
</body>
</html>
