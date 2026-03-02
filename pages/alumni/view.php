<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow all logged-in users
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Get profile ID from URL
$profileId = $_GET['id'] ?? null;

// Get return location (default to alumni index)
// Check GET parameter return_to first, then check referrer URL
$returnTo = 'alumni'; // Default value

// Check GET parameter return_to
if (isset($_GET['return_to'])) {
    // If return_to is explicitly set, use it (only 'members' is valid, anything else defaults to 'alumni')
    $returnTo = ($_GET['return_to'] === 'members') ? 'members' : 'alumni';
} 
// Check referrer URL if return_to parameter is not set
elseif (isset($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    $parsedUrl = parse_url($referer);
    // Check if parse_url succeeded and the path contains '/pages/members/' to ensure it's specifically the members page
    if ($parsedUrl !== false && isset($parsedUrl['path']) && 
        strpos($parsedUrl['path'], '/pages/members/') !== false) {
        $returnTo = 'members';
    }
}

if (!$profileId) {
    header('Location: index.php');
    exit;
}

// Get profile data
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
        <?php if ($returnTo === 'members'): ?>
            <a href="../members/index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Zurück zum Mitgliederverzeichnis
            </a>
        <?php else: ?>
            <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Zurück zum Alumni-Verzeichnis
            </a>
        <?php endif; ?>
    </div>

    <!-- Profile Header Card -->
    <div class="card p-8 mb-6">
        <div class="flex flex-col md:flex-row gap-6 mb-6">
            <!-- Profile Image -->
            <div class="flex justify-center md:justify-start">
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
            <div class="flex-1">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">
                    <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>
                </h1>

                <!-- Role Badge -->
                <?php
                $roleBadgeColors = [
                    'vorstand_finanzen'   => 'bg-purple-100 text-purple-800 border-purple-300',
                    'vorstand_intern'     => 'bg-purple-100 text-purple-800 border-purple-300',
                    'vorstand_extern'     => 'bg-purple-100 text-purple-800 border-purple-300',
                    'resortleiter'        => 'bg-blue-100 text-blue-800 border-blue-300',
                    'mitglied'            => 'bg-green-100 text-green-800 border-green-300',
                    'anwaerter'           => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                    'alumni'              => 'bg-purple-100 text-purple-800 border-purple-300',
                    'alumni_vorstand'     => 'bg-indigo-100 text-indigo-800 border-indigo-300',
                    'alumni_finanzpruefer'=> 'bg-indigo-100 text-indigo-800 border-indigo-300',
                    'ehrenmitglied'       => 'bg-amber-100 text-amber-800 border-amber-300',
                ];
                $displayRole = getFormattedRoleName($profileUserRole);
                $badgeClass = $roleBadgeColors[$profileUserRole] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                ?>
                <div class="mb-4">
                    <span class="inline-block px-4 py-2 text-sm font-semibold rounded-full border <?php echo $badgeClass; ?>">
                        <?php echo htmlspecialchars($displayRole); ?>
                    </span>
                </div>

                <!-- Position / Company snippet -->
                <?php if (!empty($profile['position'])): ?>
                <p class="text-lg text-gray-700 mb-1">
                    <i class="fas fa-briefcase mr-2 text-gray-500"></i>
                    <?php echo htmlspecialchars($profile['position']); ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['company'])): ?>
                <p class="text-md text-gray-600 mb-2">
                    <i class="fas fa-building mr-2 text-gray-500"></i>
                    <?php echo htmlspecialchars($profile['company']); ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['industry'])): ?>
                <p class="text-sm text-gray-600 mb-2">
                    <i class="fas fa-industry mr-2 text-gray-500"></i>
                    <?php echo htmlspecialchars($profile['industry']); ?>
                </p>
                <?php endif; ?>

                <!-- About Me -->
                <?php if (!empty($profileUser['about_me'])): ?>
                <p class="text-sm text-gray-600 mt-2">
                    <?php echo nl2br(htmlspecialchars($profileUser['about_me'])); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Completeness (only for alumni roles) -->
        <?php if ($isAlumniProfile && $profileCompletenessPercent < 100): ?>
        <div class="mb-4 p-4 rounded-xl" style="background-color: var(--bg-card); border-left: 4px solid #a855f7">
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-xs font-semibold text-gray-500">Profil-Fortschritt</span>
                <span class="text-xs font-bold" style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</span>
            </div>
            <div class="w-full rounded-full h-3 overflow-hidden bg-gray-200">
                <div class="h-3 rounded-full transition-all duration-500" style="width: <?php echo $profileCompletenessPercent; ?>%; background: linear-gradient(90deg, #a855f7, #ec4899)"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Kontaktinformationen -->
    <div class="card p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fas fa-address-card mr-2 text-blue-600"></i>
            Kontaktinformationen
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- E-Mail -->
            <?php if (!empty($profile['email'])): ?>
            <div class="flex items-center">
                <div class="w-10 h-10 bg-gray-600 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-envelope"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">E-Mail</p>
                    <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                        <?php echo htmlspecialchars($profile['email']); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Zweite E-Mail -->
            <?php if (!empty($profile['secondary_email'])): ?>
            <div class="flex items-center">
                <div class="w-10 h-10 bg-gray-400 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-envelope"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Zweite E-Mail</p>
                    <a href="mailto:<?php echo htmlspecialchars($profile['secondary_email']); ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                        <?php echo htmlspecialchars($profile['secondary_email']); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Telefon -->
            <?php if (!empty($profile['mobile_phone'])): ?>
            <div class="flex items-center">
                <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-phone"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Telefon</p>
                    <a href="tel:<?php echo htmlspecialchars($profile['mobile_phone']); ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                        <?php echo htmlspecialchars($profile['mobile_phone']); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- LinkedIn -->
            <?php if (!empty($profile['linkedin_url'])): ?>
                <?php
                $linkedinUrl = $profile['linkedin_url'];
                $isValidLinkedIn = (
                    strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                    strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                    strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                    strpos($linkedinUrl, 'http://www.linkedin.com') === 0
                );
                ?>
                <?php if ($isValidLinkedIn): ?>
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white mr-3">
                        <i class="fab fa-linkedin-in"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">LinkedIn</p>
                        <a href="<?php echo htmlspecialchars($linkedinUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium">
                            Profil ansehen
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Xing -->
            <?php if (!empty($profile['xing_url'])): ?>
                <?php
                $xingUrl = $profile['xing_url'];
                $isValidXing = (
                    strpos($xingUrl, 'https://xing.com') === 0 ||
                    strpos($xingUrl, 'https://www.xing.com') === 0 ||
                    strpos($xingUrl, 'http://xing.com') === 0 ||
                    strpos($xingUrl, 'http://www.xing.com') === 0
                );
                ?>
                <?php if ($isValidXing): ?>
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-teal-600 rounded-full flex items-center justify-center text-white mr-3">
                        <i class="fab fa-xing"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Xing</p>
                        <a href="<?php echo htmlspecialchars($xingUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium">
                            Profil ansehen
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Geburtstag (only if show_birthday) -->
            <?php if (!empty($profileUser['birthday']) && !empty($profileUser['show_birthday'])): ?>
            <div class="flex items-center">
                <div class="w-10 h-10 bg-pink-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-birthday-cake"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Geburtstag</p>
                    <p class="font-medium text-gray-800">
                        <?php echo date('d.m.Y', strtotime($profileUser['birthday'])); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Absolviertes Studium -->
    <?php if (!empty($profile['study_program']) || !empty($profile['semester']) || !empty($profile['angestrebter_abschluss']) || !empty($profile['graduation_year'])): ?>
    <div class="card p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fas fa-graduation-cap mr-2 text-blue-600"></i>
            Absolviertes Studium
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php if (!empty($profile['study_program'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Bachelor-Studiengang</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['study_program']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['semester'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Bachelor-Abschlussjahr</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['semester']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['angestrebter_abschluss'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Master-Studiengang</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['angestrebter_abschluss']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['graduation_year'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Master-Abschlussjahr</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['graduation_year']); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Berufliche Informationen (optional, shown when any field is set) -->
    <?php if (!empty($profile['company']) || !empty($profile['position']) || !empty($profile['industry'])): ?>
    <div class="card p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fas fa-briefcase mr-2 text-blue-600"></i>
            Berufliche Informationen
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php if (!empty($profile['company'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Aktueller Arbeitgeber</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['company']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['position'])): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1">Position</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['position']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['industry'])): ?>
            <div class="md:col-span-2">
                <p class="text-xs text-gray-500 mb-1">Branche</p>
                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($profile['industry']); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
