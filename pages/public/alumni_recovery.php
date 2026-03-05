<?php
/**
 * Alumni E-Mail Recovery – public request form
 * Access: public (no authentication required)
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

$title = 'Alumni E-Mail Recovery – IBC Intranet';

/* -----------------------------------------------------------------------
 * Handle POST submission
 * ----------------------------------------------------------------------- */
$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- reCAPTCHA v3 verification ---
    $recaptchaToken  = trim($_POST['recaptcha_token'] ?? '');
    $recaptchaSecret = RECAPTCHA_SECRET_KEY;

    if ($recaptchaSecret !== '' && $recaptchaToken !== '') {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => $recaptchaSecret,
                'response' => $recaptchaToken,
            ]),
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $verifyResponse = curl_exec($ch);
        $curlError      = curl_errno($ch);
        curl_close($ch);

        $verifyData = (!$curlError && $verifyResponse) ? json_decode($verifyResponse, true) : null;

        if (!$verifyData || !($verifyData['success'] ?? false) || ($verifyData['score'] ?? 0) < 0.5) {
            $errors[] = 'Die reCAPTCHA-Prüfung ist fehlgeschlagen. Bitte versuche es erneut.';
        }
    } elseif ($recaptchaSecret !== '' && $recaptchaToken === '') {
        $errors[] = 'reCAPTCHA-Token fehlt. Bitte aktiviere JavaScript und versuche es erneut.';
    }

    // --- Field validation ---
    $firstName          = trim($_POST['first_name']          ?? '');
    $lastName           = trim($_POST['last_name']           ?? '');
    $newEmail           = trim($_POST['new_email']           ?? '');
    $oldEmail           = trim($_POST['old_email']           ?? '');
    $graduationSemester = trim($_POST['graduation_semester'] ?? '');
    $studyProgram       = trim($_POST['study_program']       ?? '');

    if ($firstName === '') {
        $errors[] = 'Bitte gib deinen Vornamen ein.';
    }
    if ($lastName === '') {
        $errors[] = 'Bitte gib deinen Nachnamen ein.';
    }
    if ($newEmail === '') {
        $errors[] = 'Bitte gib eine neue E-Mail-Adresse an.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Die neue E-Mail-Adresse ist ungültig.';
    }
    if ($oldEmail !== '' && !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Die alte E-Mail-Adresse ist ungültig.';
    }
    if ($graduationSemester === '') {
        $errors[] = 'Bitte gib dein Abschlusssemester an.';
    }
    if ($studyProgram === '') {
        $errors[] = 'Bitte gib deinen Studiengang an.';
    }

    // --- Save to database if no errors ---
    if (empty($errors)) {
        require_once __DIR__ . '/../../src/Database.php';
        require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';

        $id = AlumniAccessRequest::create([
            'first_name'          => $firstName,
            'last_name'           => $lastName,
            'new_email'           => $newEmail,
            'old_email'           => $oldEmail,
            'graduation_semester' => $graduationSemester,
            'study_program'       => $studyProgram,
        ]);

        if ($id !== false) {
            $success = true;
        } else {
            $errors[] = 'Beim Speichern deiner Anfrage ist ein Fehler aufgetreten. Bitte versuche es später erneut.';
        }
    }
}

ob_start();
?>
<!-- ============================================================
     Alumni E-Mail Recovery – Content (rendered inside auth_layout)
     ============================================================ -->
<div class="w-full max-w-lg mx-auto px-4 py-8">

    <!-- Logo -->
    <div class="flex justify-center mb-6">
        <img
            src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>"
            alt="IBC Logo"
            class="h-16 w-auto drop-shadow-lg"
            decoding="async"
        >
    </div>

    <!-- Heading -->
    <div class="text-center mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-white mb-2">
            Alumni E-Mail Recovery
        </h1>
        <p class="text-gray-400 text-sm sm:text-base">
            Du hast keinen Zugriff mehr auf deine Alumni-E-Mail-Adresse?<br>
            Fülle das folgende Formular aus, damit wir dir helfen können.
        </p>
    </div>

    <?php if ($success): ?>
    <!-- Success message -->
    <div class="rounded-xl bg-green-900/40 border border-green-600/50 p-6 text-center text-green-300">
        <i class="fas fa-check-circle text-3xl mb-3 text-green-400"></i>
        <p class="font-semibold text-lg mb-1">Anfrage erfolgreich gesendet!</p>
        <p class="text-sm text-green-400">
            Wir haben deine Anfrage erhalten und werden sie so schnell wie möglich bearbeiten.
            Du wirst an deine neue E-Mail-Adresse benachrichtigt.
        </p>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <!-- Error list -->
    <div class="rounded-xl bg-red-900/40 border border-red-600/50 p-4 mb-6">
        <ul class="list-disc list-inside space-y-1 text-sm text-red-300">
            <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form
        id="alumniRecoveryForm"
        method="POST"
        action=""
        class="space-y-5"
        novalidate
    >
        <input type="hidden" name="recaptcha_token" id="recaptcha_token">

        <!-- First name / Last name -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="first_name" class="block text-sm font-medium text-gray-300 mb-1">
                    Vorname <span class="text-red-400">*</span>
                </label>
                <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    required
                    autocomplete="given-name"
                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                           px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                           focus:border-ibc-green/60 transition"
                    placeholder="Max"
                >
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium text-gray-300 mb-1">
                    Nachname <span class="text-red-400">*</span>
                </label>
                <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    required
                    autocomplete="family-name"
                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                           px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                           focus:border-ibc-green/60 transition"
                    placeholder="Mustermann"
                >
            </div>
        </div>

        <!-- New e-mail -->
        <div>
            <label for="new_email" class="block text-sm font-medium text-gray-300 mb-1">
                Neue E-Mail-Adresse <span class="text-red-400">*</span>
            </label>
            <input
                type="email"
                id="new_email"
                name="new_email"
                required
                autocomplete="email"
                value="<?php echo htmlspecialchars($_POST['new_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                       px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                       focus:border-ibc-green/60 transition"
                placeholder="max.mustermann@example.com"
            >
            <p class="mt-1 text-xs text-gray-500">Die E-Mail-Adresse, auf die dein Zugang übertragen werden soll.</p>
        </div>

        <!-- Old e-mail (optional) -->
        <div>
            <label for="old_email" class="block text-sm font-medium text-gray-300 mb-1">
                Alte E-Mail-Adresse
                <span class="text-gray-500 font-normal">(optional)</span>
            </label>
            <input
                type="email"
                id="old_email"
                name="old_email"
                autocomplete="off"
                value="<?php echo htmlspecialchars($_POST['old_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                       px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                       focus:border-ibc-green/60 transition"
                placeholder="alte.adresse@example.com"
            >
            <p class="mt-1 text-xs text-gray-500">Falls noch bekannt – hilft uns bei der Identifikation.</p>
        </div>

        <!-- Graduation semester -->
        <div>
            <label for="graduation_semester" class="block text-sm font-medium text-gray-300 mb-1">
                Abschlusssemester <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                id="graduation_semester"
                name="graduation_semester"
                required
                value="<?php echo htmlspecialchars($_POST['graduation_semester'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                       px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                       focus:border-ibc-green/60 transition"
                placeholder="z. B. WS 2019/20"
            >
        </div>

        <!-- Study program -->
        <div>
            <label for="study_program" class="block text-sm font-medium text-gray-300 mb-1">
                Studiengang <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                id="study_program"
                name="study_program"
                required
                value="<?php echo htmlspecialchars($_POST['study_program'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                class="w-full rounded-lg bg-white/10 border border-white/20 text-white placeholder-gray-500
                       px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                       focus:border-ibc-green/60 transition"
                placeholder="z. B. Betriebswirtschaftslehre (B.Sc.)"
            >
        </div>

        <!-- Submit -->
        <button
            type="submit"
            id="submitBtn"
            class="w-full py-3 px-6 rounded-xl font-semibold text-white text-sm
                   bg-ibc-green hover:bg-ibc-green/90 active:scale-[0.98]
                   focus:outline-none focus:ring-2 focus:ring-ibc-green/60
                   transition-all duration-150 flex items-center justify-center gap-2"
        >
            <i class="fas fa-paper-plane"></i>
            Anfrage absenden
        </button>

        <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
        <p class="text-xs text-center text-gray-600">
            Diese Seite ist durch reCAPTCHA geschützt.
            Es gelten die
            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener"
               class="underline hover:text-gray-400">Datenschutzbestimmungen</a>
            und
            <a href="https://policies.google.com/terms" target="_blank" rel="noopener"
               class="underline hover:text-gray-400">Nutzungsbedingungen</a>
            von Google.
        </p>
        <?php endif; ?>
    </form>

    <?php endif; ?>
</div>

<?php if (RECAPTCHA_SITE_KEY !== ''): ?>
<!-- Google reCAPTCHA v3 -->
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var form  = document.getElementById('alumniRecoveryForm');
    var btn   = document.getElementById('submitBtn');
    var siteKey = <?php echo json_encode(RECAPTCHA_SITE_KEY); ?>;

    if (!form || !btn) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Wird gesendet…';

        grecaptcha.ready(function () {
            grecaptcha.execute(siteKey, { action: 'alumni_recovery' }).then(function (token) {
                document.getElementById('recaptcha_token').value = token;
                form.submit();
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Anfrage absenden';
            });
        });
    });
}());
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();

// Use auth_layout – no login required
require_once __DIR__ . '/../../includes/templates/auth_layout.php';
?>
