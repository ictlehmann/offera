<?php
/**
 * Alumni E-Mail Recovery – public request form
 * Access: public (no authentication required)
 *
 * Form submission is handled client-side via fetch → api/public/submit_alumni_recovery.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

$title = 'Alumni E-Mail Recovery – IBC Intranet';

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

    <!-- Success message (shown by JavaScript after successful fetch submission) -->
    <div id="alumniSuccessMessage" class="hidden rounded-xl bg-green-900/40 border border-green-600/50 p-6 text-center text-green-300">
        <i class="fas fa-check-circle text-3xl mb-3 text-green-400"></i>
        <p class="font-semibold text-lg mb-1">Anfrage erfolgreich gesendet!</p>
        <p class="text-sm text-green-400">
            Wir haben deine Anfrage erhalten und werden sie so schnell wie möglich bearbeiten.
            Du wirst an deine neue E-Mail-Adresse benachrichtigt.
        </p>
    </div>

    <!-- Error box (shown by JavaScript when the API returns an error) -->
    <div id="alumniErrorBox" class="hidden rounded-xl bg-red-900/40 border border-red-600/50 p-4 mb-6">
        <ul id="alumniErrorList" class="list-disc list-inside space-y-1 text-sm text-red-300"></ul>
    </div>

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

</div>

<?php if (RECAPTCHA_SITE_KEY !== ''): ?>
<!-- Google reCAPTCHA v3 -->
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var form    = document.getElementById('alumniRecoveryForm');
    var btn     = document.getElementById('submitBtn');
    var siteKey = <?php echo json_encode(RECAPTCHA_SITE_KEY); ?>;

    if (!form || !btn) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Clear any previous error
        var errorBox  = document.getElementById('alumniErrorBox');
        var errorList = document.getElementById('alumniErrorList');
        errorBox.classList.add('hidden');
        errorList.innerHTML = '';

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Wird gesendet…';

        // grecaptcha.execute() is called ONLY at this exact moment (button click),
        // so the token is always freshly minted and can never have expired.
        grecaptcha.ready(function () {
            grecaptcha.execute(siteKey, { action: 'alumni_recovery' }).then(function (token) {
                document.getElementById('recaptcha_token').value = token;

                var payload = {
                    recaptcha_token:     token,
                    first_name:          document.getElementById('first_name').value,
                    last_name:           document.getElementById('last_name').value,
                    new_email:           document.getElementById('new_email').value,
                    old_email:           document.getElementById('old_email').value,
                    graduation_semester: document.getElementById('graduation_semester').value,
                    study_program:       document.getElementById('study_program').value,
                };

                fetch('/api/public/submit_alumni_recovery.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                })
                .then(function (response) {
                    // Always parse JSON; the API returns a JSON body even for error
                    // status codes, and we need the message field from it.
                    var ok = response.ok;
                    return response.json().then(function (data) {
                        if (!ok && !data.message) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return data;
                    });
                })
                .then(function (result) {
                    if (result.success) {
                        form.classList.add('hidden');
                        document.getElementById('alumniSuccessMessage').classList.remove('hidden');
                    } else {
                        var msg = result.message || 'Ein unbekannter Fehler ist aufgetreten. Bitte versuche es erneut.';
                        var li = document.createElement('li');
                        li.textContent = msg;
                        errorList.appendChild(li);
                        errorBox.classList.remove('hidden');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Anfrage absenden';
                    }
                })
                .catch(function (err) {
                    console.error('Alumni recovery submission error:', err);
                    var li = document.createElement('li');
                    li.textContent = 'Es ist ein Netzwerkfehler aufgetreten. Bitte überprüfe deine Verbindung und versuche es erneut.';
                    errorList.appendChild(li);
                    errorBox.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Anfrage absenden';
                });
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
