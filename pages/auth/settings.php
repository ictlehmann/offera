<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../src/MailService.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$message = '';
$error = '';
$showQRCode = false;
$secret = '';
$qrCodeUrl = '';

// Check for session messages (from email confirmation, etc.)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_notifications'])) {
        $notifyNewProjects = isset($_POST['notify_new_projects']) ? true : false;
        $notifyNewEvents = isset($_POST['notify_new_events']) ? true : false;
        
        if (User::updateNotificationPreferences($user['id'], $notifyNewProjects, $notifyNewEvents)) {
            $message = 'Benachrichtigungseinstellungen erfolgreich aktualisiert';
            $user = Auth::user(); // Reload user data
        } else {
            $error = 'Fehler beim Aktualisieren der Benachrichtigungseinstellungen';
        }
    } else if (isset($_POST['update_theme'])) {
        $theme = $_POST['theme'] ?? 'auto';
        
        // Validate theme value
        if (!in_array($theme, ['light', 'dark', 'auto'])) {
            $theme = 'auto';
        }
        
        if (User::updateThemePreference($user['id'], $theme)) {
            $message = 'Design-Einstellungen erfolgreich gespeichert';
            $user = Auth::user(); // Reload user data
        } else {
            $error = 'Fehler beim Speichern der Design-Einstellungen';
        }
    } elseif (isset($_POST['enable_2fa'])) {
        $ga = new PHPGangsta_GoogleAuthenticator();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
        $showQRCode = true;
    } elseif (isset($_POST['confirm_2fa'])) {
        $secret = $_POST['secret'] ?? '';
        $code = $_POST['code'] ?? '';
        
        $ga = new PHPGangsta_GoogleAuthenticator();
        if ($ga->verifyCode($secret, $code, 2)) {
            if (User::enable2FA($user['id'], $secret)) {
                $message = '2FA erfolgreich aktiviert';
                $user = Auth::user(); // Reload user
            } else {
                $error = 'Fehler beim Aktivieren von 2FA';
            }
        } else {
            $error = 'Ungültiger Code. Bitte versuche es erneut.';
            $secret = $_POST['secret'];
            $ga = new PHPGangsta_GoogleAuthenticator();
            $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
            $showQRCode = true;
        }
    } elseif (isset($_POST['disable_2fa'])) {
        if (User::disable2FA($user['id'])) {
            $message = '2FA erfolgreich deaktiviert';
            $user = Auth::user(); // Reload user
        } else {
            $error = 'Fehler beim Deaktivieren von 2FA';
        }
    } elseif (isset($_POST['submit_change_request'])) {
        try {
            $requestType = trim($_POST['request_type'] ?? '');
            $requestReason = trim($_POST['request_reason'] ?? '');

            $allowedTypes = ['Rollenänderung', 'E-Mail-Adressenänderung'];
            if (!in_array($requestType, $allowedTypes, true)) {
                throw new Exception('Ungültiger Änderungstyp. Bitte wählen Sie eine gültige Option.');
            }

            if (strlen($requestReason) < 10) {
                throw new Exception('Bitte geben Sie eine ausführlichere Begründung an (mindestens 10 Zeichen).');
            }
            if (strlen($requestReason) > 1000) {
                throw new Exception('Die Begründung ist zu lang (maximal 1000 Zeichen).');
            }

            $currentRole = translateRole($user['role'] ?? '');

            $emailBody = MailService::getTemplate(
                'Änderungsantrag',
                '<p>Ein Benutzer hat einen Änderungsantrag gestellt:</p>
                <table class="info-table">
                    <tr>
                        <td class="info-label">E-Mail:</td>
                        <td class="info-value">' . htmlspecialchars($user['email']) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Aktuelle Rolle:</td>
                        <td class="info-value">' . htmlspecialchars($currentRole) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Art der Änderung:</td>
                        <td class="info-value">' . htmlspecialchars($requestType) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Begründung / Neuer Wert:</td>
                        <td class="info-value">' . nl2br(htmlspecialchars($requestReason)) . '</td>
                    </tr>
                </table>'
            );

            $emailSent = MailService::sendEmail(
                'it@business-consulting.de',
                'Änderungsantrag: ' . $requestType . ' von ' . $user['email'],
                $emailBody
            );

            if ($emailSent) {
                $message = 'Ihr Änderungsantrag wurde erfolgreich eingereicht!';
            } else {
                $error = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
            }
        } catch (Exception $e) {
            error_log('Error submitting change request: ' . $e->getMessage());
            $error = htmlspecialchars($e->getMessage());
        }
    }
}

$title = 'Einstellungen';
ob_start();
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">
            <i class="fas fa-cog text-purple-600 mr-3"></i>
            Einstellungen
        </h1>
        <p class="text-gray-600">Verwalte deine persönlichen Einstellungen</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-start">
            <i class="fas fa-check-circle mt-0.5 mr-3"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-start">
            <i class="fas fa-exclamation-circle mt-0.5 mr-3"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Microsoft Notice -->
    <div class="mb-6 p-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl mr-4 mt-1"></i>
            <div>
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">
                    Zentral verwaltetes Profil
                </h3>
                <p class="text-blue-800 dark:text-blue-200">
                    Ihr Profil wird zentral über Microsoft verwaltet. Änderungen an E-Mail oder Rolle können über das untenstehende Formular beantragt werden.
                </p>
            </div>
        </div>
    </div>

    <!-- Current Profile (Read-Only) -->
    <div class="mb-6">
        <div class="card p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-user text-blue-600 mr-2"></i>
                Aktuelles Profil
            </h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">E-Mail-Adresse</label>
                    <input 
                        type="email" 
                        readonly
                        value="<?php echo htmlspecialchars($user['email']); ?>"
                        class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-300 rounded-lg cursor-not-allowed"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rolle</label>
                    <input 
                        type="text" 
                        readonly
                        value="<?php echo htmlspecialchars(translateRole($user['role'])); ?>"
                        class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-300 rounded-lg cursor-not-allowed"
                    >
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- 2FA Settings -->
        <div class="lg:col-span-2">
            <div class="card p-6">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                    <i class="fas fa-shield-alt text-green-600 mr-2"></i>
                    Zwei-Faktor-Authentifizierung (2FA)
                </h2>

                <?php if (!$showQRCode): ?>
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <p class="text-gray-700 dark:text-gray-300 mb-2">
                            Status: 
                            <?php if ($user['tfa_enabled']): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-semibold">
                                <i class="fas fa-check-circle mr-1"></i>Aktiviert
                            </span>
                            <?php else: ?>
                            <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-full font-semibold">
                                <i class="fas fa-times-circle mr-1"></i>Deaktiviert
                            </span>
                            <?php endif; ?>
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Schütze dein Konto mit einer zusätzlichen Sicherheitsebene
                        </p>
                    </div>
                    <div>
                        <?php if ($user['tfa_enabled']): ?>
                        <form method="POST" onsubmit="return confirm('Möchtest du 2FA wirklich deaktivieren?');">
                            <button type="submit" name="disable_2fa" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                <i class="fas fa-times mr-2"></i>2FA deaktivieren
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST">
                            <button type="submit" name="enable_2fa" class="btn-primary">
                                <i class="fas fa-plus mr-2"></i>2FA aktivieren
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-400 dark:border-blue-500 p-4">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Empfehlung:</strong> Aktiviere 2FA für zusätzliche Sicherheit. Du benötigst eine Authenticator-App wie Google Authenticator oder Authy.
                    </p>
                </div>
                <?php else: ?>
                <!-- QR Code Setup -->
                <div class="max-w-md mx-auto">
                    <div class="text-center mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">2FA einrichten</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                            Scanne den QR-Code mit deiner Authenticator-App und gib den generierten Code ein
                        </p>
                        <div id="qrcode" class="mx-auto mb-4 inline-block"></div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            Geheimer Schlüssel (manuell): <code class="bg-gray-100 dark:bg-gray-700 dark:text-gray-300 px-2 py-1 rounded"><?php echo htmlspecialchars($secret); ?></code>
                        </p>
                    </div>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="secret" value="<?php echo htmlspecialchars($secret); ?>">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">6-stelliger Code</label>
                            <input 
                                type="text" 
                                name="code" 
                                required 
                                maxlength="6"
                                pattern="[0-9]{6}"
                                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg text-center text-2xl tracking-widest"
                                placeholder="000000"
                                autofocus
                            >
                        </div>
                        <div class="flex space-x-4">
                            <a href="settings.php" class="flex-1 text-center px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                Abbrechen
                            </a>
                            <button type="submit" name="confirm_2fa" class="flex-1 btn-primary">
                                <i class="fas fa-check mr-2"></i>Bestätigen
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notification Settings -->
        <div class="lg:col-span-2">
            <div class="card p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-bell text-orange-600 mr-2"></i>
                    Benachrichtigungen
                </h2>
                <p class="text-gray-600 mb-6">
                    Wähle aus, über welche Ereignisse du per E-Mail an <strong><?php echo htmlspecialchars($user['email']); ?></strong> benachrichtigt werden möchtest
                </p>
                
                <form method="POST" class="space-y-4">
                    <div class="space-y-4">
                        <!-- New Projects Notification -->
                        <div class="flex items-start p-4 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600">
                            <input 
                                type="checkbox" 
                                name="notify_new_projects" 
                                id="notify_new_projects"
                                <?php echo ($user['notify_new_projects'] ?? true) ? 'checked' : ''; ?>
                                class="mt-1 h-5 w-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                            >
                            <label for="notify_new_projects" class="ml-3 flex-1 cursor-pointer">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Neue Projekte</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">
                                    Erhalte eine E-Mail-Benachrichtigung, wenn ein neues Projekt veröffentlicht wird
                                </span>
                            </label>
                        </div>

                        <!-- New Events Notification -->
                        <div class="flex items-start p-4 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600">
                            <input 
                                type="checkbox" 
                                name="notify_new_events" 
                                id="notify_new_events"
                                <?php echo ($user['notify_new_events'] ?? false) ? 'checked' : ''; ?>
                                class="mt-1 h-5 w-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                            >
                            <label for="notify_new_events" class="ml-3 flex-1 cursor-pointer">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Neue Events</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">
                                    Erhalte eine E-Mail-Benachrichtigung, wenn ein neues Event erstellt wird
                                </span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="update_notifications" class="w-full btn-primary">
                        <i class="fas fa-save mr-2"></i>Benachrichtigungseinstellungen speichern
                    </button>
                </form>
            </div>
        </div>

        <!-- Theme Settings -->
        <div class="lg:col-span-2">
            <div class="card p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-palette text-purple-600 mr-2"></i>
                    Design-Einstellungen
                </h2>
                <p class="text-gray-600 mb-6">
                    Wähle dein bevorzugtes Design-Theme
                </p>
                
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Light Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'border-purple-500 bg-purple-50' : 'border-gray-200'; ?>">
                            <input type="radio" name="theme" value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-sun text-4xl text-yellow-500 mb-2"></i>
                            <span class="font-medium text-gray-800">Hellmodus</span>
                            <span class="text-sm text-gray-600 text-center mt-1">Immer helles Design</span>
                        </label>

                        <!-- Dark Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'border-purple-500 bg-purple-50' : 'border-gray-200'; ?>">
                            <input type="radio" name="theme" value="dark" <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-moon text-4xl text-indigo-600 mb-2"></i>
                            <span class="font-medium text-gray-800">Dunkelmodus</span>
                            <span class="text-sm text-gray-600 text-center mt-1">Immer dunkles Design</span>
                        </label>

                        <!-- Auto Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'border-purple-500 bg-purple-50' : 'border-gray-200'; ?>">
                            <input type="radio" name="theme" value="auto" <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-adjust text-4xl text-gray-600 mb-2"></i>
                            <span class="font-medium text-gray-800">Automatisch</span>
                            <span class="text-sm text-gray-600 text-center mt-1">Folgt Systemeinstellung</span>
                        </label>
                    </div>

                    <button type="submit" name="update_theme" class="w-full btn-primary">
                        <i class="fas fa-save mr-2"></i>Design-Einstellungen speichern
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- Change Request Section -->
    <div class="mt-6">
        <div class="card p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-edit text-green-600 mr-2"></i>
                Änderungsantrag
            </h2>
            <p class="text-gray-600 dark:text-gray-300 mb-6">
                Beantragen Sie Änderungen an Ihrer Rolle oder E-Mail-Adresse
            </p>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Art der Änderung *</label>
                    <select
                        name="request_type"
                        required
                        class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                    >
                        <option value="">Bitte wählen...</option>
                        <option value="Rollenänderung">Rollenänderung</option>
                        <option value="E-Mail-Adressenänderung">E-Mail-Adressenänderung</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Begründung / Neuer Wert *</label>
                    <textarea
                        name="request_reason"
                        required
                        minlength="10"
                        maxlength="1000"
                        rows="4"
                        placeholder="Bitte geben Sie eine Begründung oder den neuen gewünschten Wert an..."
                        class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                    ></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mindestens 10, maximal 1000 Zeichen</p>
                </div>

                <button
                    type="submit"
                    name="submit_change_request"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200"
                >
                    <i class="fas fa-paper-plane mr-2"></i>
                    Beantragen
                </button>
            </form>
        </div>
    </div>

    <!-- Support Section -->
    <div class="mt-6">
        <div class="card p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-life-ring text-blue-600 mr-2"></i>
                Änderungen &amp; Support
            </h2>
            <p class="text-gray-600 dark:text-gray-300 mb-6">
                Wende dich bei Fragen oder Problemen direkt an die IT-Ressortleitung
            </p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="mailto:ressortleitung-it@business-consulting.de?subject=E-Mail%2FName%20%C3%A4ndern"
                   class="flex items-center p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/40 transition">
                    <i class="fas fa-envelope text-blue-600 dark:text-blue-400 text-2xl mr-4"></i>
                    <div>
                        <span class="block font-semibold text-gray-800 dark:text-gray-100">E-Mail/Name ändern</span>
                        <span class="block text-sm text-gray-600 dark:text-gray-400">Anfrage per E-Mail senden</span>
                    </div>
                </a>
                <a href="mailto:ressortleitung-it@business-consulting.de?subject=2FA%20zur%C3%BCcksetzen"
                   class="flex items-center p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition">
                    <i class="fas fa-shield-alt text-yellow-600 dark:text-yellow-400 text-2xl mr-4"></i>
                    <div>
                        <span class="block font-semibold text-gray-800 dark:text-gray-100">2FA zurücksetzen</span>
                        <span class="block text-sm text-gray-600 dark:text-gray-400">Anfrage per E-Mail senden</span>
                    </div>
                </a>
                <a href="mailto:ressortleitung-it@business-consulting.de?subject=Bug%20melden"
                   class="flex items-center p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                    <i class="fas fa-bug text-red-600 dark:text-red-400 text-2xl mr-4"></i>
                    <div>
                        <span class="block font-semibold text-gray-800 dark:text-gray-100">Bug melden</span>
                        <span class="block text-sm text-gray-600 dark:text-gray-400">Fehler per E-Mail melden</span>
                    </div>
                </a>
            </div>
        </div>
    </div>

</div>

<!-- QR Code Library -->
<?php if ($showQRCode): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>

<script>
// Make theme selection more interactive
document.querySelectorAll('input[name="theme"]').forEach(radio => {
    radio.addEventListener('change', function() {
        // Remove highlight from all labels
        document.querySelectorAll('label[class*="border-2"]').forEach(label => {
            label.classList.remove('border-purple-500', 'bg-purple-50');
            label.classList.add('border-gray-200');
        });
        
        // Add highlight to selected label
        const selectedLabel = this.closest('label');
        selectedLabel.classList.remove('border-gray-200');
        selectedLabel.classList.add('border-purple-500', 'bg-purple-50');
    });
});

// Sync theme preference with localStorage after successful save
<?php if ($message && strpos($message, 'Design-Einstellungen') !== false): ?>
// Theme was just saved, update data-user-theme attribute and apply theme immediately
const newTheme = '<?php echo htmlspecialchars($user['theme_preference'] ?? 'auto'); ?>';
document.body.setAttribute('data-user-theme', newTheme);
localStorage.setItem('theme', newTheme);

// Apply theme immediately
// Note: Both 'dark-mode' and 'dark' classes are required:
// - 'dark-mode' is used by custom CSS rules for sidebar and specific components
// - 'dark' is used by Tailwind's dark mode (darkMode: 'class' in config)
if (newTheme === 'dark') {
    document.body.classList.add('dark-mode', 'dark');
} else if (newTheme === 'light') {
    document.body.classList.remove('dark-mode', 'dark');
} else { // auto
    // Check system preference
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.body.classList.add('dark-mode', 'dark');
    } else {
        document.body.classList.remove('dark-mode', 'dark');
    }
}
<?php endif; ?>

// Generate QR Code for 2FA if needed
<?php if ($showQRCode): ?>
document.addEventListener('DOMContentLoaded', function() {
    const qrcodeElement = document.getElementById('qrcode');
    if (qrcodeElement) {
        qrcodeElement.innerHTML = '';
        new QRCode(qrcodeElement, {
            text: <?php echo json_encode($qrCodeUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>,
            width: 200,
            height: 200,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
