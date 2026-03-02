<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow all logged-in users with members page access
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

if (!Auth::canAccessPage('members')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$user = Auth::user();

// Get profile ID from URL
$profileId = $_GET['id'] ?? null;

if (!$profileId) {
    header('Location: index.php');
    exit;
}

// Get profile data (members and alumni share the same alumni_profiles table)
$profile = Alumni::getProfileById((int)$profileId);

if (!$profile) {
    $_SESSION['error_message'] = 'Profil nicht gefunden';
    header('Location: index.php');
    exit;
}

// Get the user's role from the users table
$profileUser = User::findById($profile['user_id']);
if (!$profileUser) {
    $_SESSION['error_message'] = 'Benutzer nicht gefunden';
    header('Location: index.php');
    exit;
}

// Get role information - prioritize Entra roles over internal role
$profileUserRole = $profileUser['role'];
$profileUserEntraRoles = $profileUser['entra_roles'] ?? null;

// Calculate profile completeness (only for alumni roles)
$profileCompletenessPercent = 0;
$isAlumniProfile = isAlumniRole($profileUserRole);
if ($isAlumniProfile) {
    $completenessFields = [
        'image_path'    => $profile['image_path'] ?? null,
        'mobile_phone'  => $profile['mobile_phone'] ?? null,
        'about_me'      => $profileUser['about_me'] ?? null,
        'study_program' => $profile['study_program'] ?? null,
    ];
    $filledCount = 0;
    foreach ($completenessFields as $value) {
        if (!empty($value)) {
            $filledCount++;
        }
    }
    $profileCompletenessPercent = (int)round(($filledCount / count($completenessFields)) * 100);
}

$title = htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) . ' - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors font-medium">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zum Mitgliederverzeichnis
        </a>
    </div>

    <!-- Profile Header Card -->
    <div class="card p-8 mb-6">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Profile Image -->
            <div class="flex justify-center md:justify-start flex-shrink-0">
                <?php 
                $initials = strtoupper(substr($profile['first_name'], 0, 1) . substr($profile['last_name'], 0, 1));
                $imagePath = asset(getProfileImageUrl($profile['image_path'] ?? '', $profileUser['entra_photo_path'] ?? null));
                ?>
                <div class="w-32 h-32 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-4xl font-bold overflow-hidden shadow-lg">
                    <?php if (!empty($imagePath)): ?>
                        <img 
                            src="<?php echo htmlspecialchars($imagePath); ?>" 
                            alt="<?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>"
                            class="w-full h-full object-cover"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >
                        <div style="display:none;" class="w-full h-full flex items-center justify-center text-4xl bg-gradient-to-br from-blue-400 to-blue-600">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                    <?php else: ?>
                        <?php echo htmlspecialchars($initials); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Info -->
            <div class="flex-1 min-w-0">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                    <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>
                </h1>

                <!-- Role Badge -->
                <?php
                $roleBadgeColors = [
                    'vorstand_finanzen'   => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'vorstand_intern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'vorstand_extern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'ressortleiter'       => 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900 dark:text-blue-200 dark:border-blue-700',
                    'mitglied'            => 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-200 dark:border-green-700',
                    'anwaerter'           => 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900 dark:text-yellow-200 dark:border-yellow-700',
                    'alumni'              => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'alumni_vorstand'     => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-300 dark:border-indigo-500',
                    'alumni_finanz'       => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-300 dark:border-indigo-500',
                    'ehrenmitglied'       => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900 dark:text-amber-200 dark:border-amber-700',
                ];
                $displayRole = getFormattedRoleName($profileUserRole);
                $badgeClass = $roleBadgeColors[$profileUserRole] ?? 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600';
                ?>
                <div class="mb-3">
                    <span class="inline-block px-4 py-1.5 text-sm font-semibold rounded-full border <?php echo $badgeClass; ?>">
                        <?php echo htmlspecialchars($displayRole); ?>
                    </span>
                </div>

                <!-- Position / Company snippet -->
                <?php if (!empty($profile['position']) || !empty($profileUser['job_title'])): ?>
                <p class="text-base text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-2">
                    <i class="fas fa-briefcase text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['position'] ?? $profileUser['job_title'] ?? ''); ?></span>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['company']) || !empty($profileUser['company'])): ?>
                <p class="text-sm text-gray-500 mb-2 flex items-center gap-2">
                    <i class="fas fa-building text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['company'] ?? $profileUser['company'] ?? ''); ?></span>
                </p>
                <?php endif; ?>

                <?php if (!empty($profile['study_program'])): ?>
                <p class="text-sm text-gray-500 flex items-center gap-2">
                    <i class="fas fa-graduation-cap text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['study_program']); ?><?php if (!empty($profile['semester'])): ?> &middot; <?php echo htmlspecialchars($profile['semester']); ?>. Semester<?php endif; ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Completeness (only for alumni roles) -->
        <?php if ($isAlumniProfile && $profileCompletenessPercent < 100): ?>
        <div class="mt-6 p-4 rounded-xl" style="background-color: var(--bg-card); border-left: 4px solid #a855f7">
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-xs font-semibold text-gray-500">Profil-Fortschritt</span>
                <span class="text-xs font-bold" style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</span>
            </div>
            <div class="w-full rounded-full h-2.5 overflow-hidden bg-gray-200">
                <div class="h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $profileCompletenessPercent; ?>%; background: linear-gradient(90deg, #a855f7, #ec4899)"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Über mich -->
    <?php if (!empty($profileUser['about_me'])): ?>
    <div class="card p-6 mb-6">
        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600">
                <i class="fas fa-quote-left text-sm"></i>
            </span>
            Über mich
        </h2>
        <p class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line break-words"><?php echo htmlspecialchars($profileUser['about_me']); ?></p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- Kontaktinformationen -->
        <div class="card p-6">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-address-card text-sm"></i>
                </span>
                Kontakt
            </h2>
            <div class="space-y-3">
                <!-- E-Mail -->
                <?php if (!empty($profile['email'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 flex-shrink-0">
                        <i class="fas fa-envelope text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">E-Mail</p>
                        <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm truncate block">
                            <?php echo htmlspecialchars($profile['email']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Zweite E-Mail -->
                <?php if (!empty($profile['secondary_email'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 flex-shrink-0">
                        <i class="fas fa-envelope text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">Zweite E-Mail</p>
                        <a href="mailto:<?php echo htmlspecialchars($profile['secondary_email']); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm truncate block">
                            <?php echo htmlspecialchars($profile['secondary_email']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Telefon -->
                <?php if (!empty($profile['mobile_phone'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center text-green-600 flex-shrink-0">
                        <i class="fas fa-phone text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">Telefon</p>
                        <a href="tel:<?php echo htmlspecialchars($profile['mobile_phone']); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            <?php echo htmlspecialchars($profile['mobile_phone']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Geburtstag (only if show_birthday) -->
                <?php if (!empty($profileUser['birthday']) && !empty($profileUser['show_birthday'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-pink-100 flex items-center justify-center text-pink-600 flex-shrink-0">
                        <i class="fas fa-birthday-cake text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">Geburtstag</p>
                        <p class="font-medium text-gray-800 dark:text-gray-200 text-sm"><?php echo date('d.m.Y', strtotime($profileUser['birthday'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- LinkedIn -->
                <?php if (!empty($profile['linkedin_url'])):
                    $linkedinUrl = $profile['linkedin_url'];
                    $isValidLinkedIn = (
                        strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'http://www.linkedin.com') === 0
                    );
                    if ($isValidLinkedIn): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-blue-700 flex items-center justify-center text-white flex-shrink-0">
                        <i class="fab fa-linkedin-in text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">LinkedIn</p>
                        <a href="<?php echo htmlspecialchars($linkedinUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            Profil ansehen <i class="fas fa-external-link-alt text-xs ml-1"></i>
                        </a>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Xing -->
                <?php if (!empty($profile['xing_url'])):
                    $xingUrl = $profile['xing_url'];
                    $isValidXing = (
                        strpos($xingUrl, 'https://xing.com') === 0 ||
                        strpos($xingUrl, 'https://www.xing.com') === 0 ||
                        strpos($xingUrl, 'http://xing.com') === 0 ||
                        strpos($xingUrl, 'http://www.xing.com') === 0
                    );
                    if ($isValidXing): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                    <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white flex-shrink-0">
                        <i class="fab fa-xing text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">Xing</p>
                        <a href="<?php echo htmlspecialchars($xingUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            Profil ansehen <i class="fas fa-external-link-alt text-xs ml-1"></i>
                        </a>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (empty($profile['email']) && empty($profile['mobile_phone']) && empty($profile['linkedin_url']) && empty($profile['secondary_email']) && empty($profile['xing_url']) && (empty($profileUser['birthday']) || empty($profileUser['show_birthday']))): ?>
                <p class="text-sm text-gray-400 italic">Keine Kontaktinformationen hinterlegt</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Aktuelles Studium -->
        <?php if (!empty($profile['study_program']) || !empty($profile['semester']) || !empty($profile['angestrebter_abschluss']) || !empty($profile['graduation_year'])): ?>
        <div class="card p-6">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-600">
                    <i class="fas fa-graduation-cap text-sm"></i>
                </span>
                Aktuelles Studium
            </h2>
            <div class="space-y-3">
                <?php if (!empty($profile['study_program'])): ?>
                <div class="p-3 rounded-xl bg-gray-50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Studiengang</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['study_program']); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['semester'])): ?>
                <div class="p-3 rounded-xl bg-gray-50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Semester</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['semester']); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['angestrebter_abschluss'])): ?>
                <div class="p-3 rounded-xl bg-gray-50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Master-Studiengang</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['angestrebter_abschluss']); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['graduation_year'])): ?>
                <div class="p-3 rounded-xl bg-gray-50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Master-Semester</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['graduation_year']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
