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

$title = htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) . ' - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zum Mitgliederverzeichnis
        </a>
    </div>

    <!-- Profile Card -->
    <div class="card p-8">
        <!-- Profile Header -->
        <div class="flex flex-col md:flex-row gap-6 mb-8">
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
                // Define role badge colors
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
                
                // Resolve display role: prioritize Entra roles, fall back to database role
                $displayRole = null;
                $displayRoleKey = Auth::getPrimaryEntraRoleKey($profileUserEntraRoles, $profileUserRole);
                if (!empty($profileUserEntraRoles)) {
                    $entraRolesArray = json_decode($profileUserEntraRoles, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($entraRolesArray) && !empty($entraRolesArray)) {
                        $displayNames = [];
                        foreach ($entraRolesArray as $entraRole) {
                            if (is_array($entraRole) && isset($entraRole['displayName'])) {
                                $displayNames[] = $entraRole['displayName'];
                            } elseif (is_array($entraRole) && isset($entraRole['id'])) {
                                $displayNames[] = Auth::getRoleLabel($entraRole['id']);
                            } elseif (is_string($entraRole)) {
                                $displayNames[] = Auth::getRoleLabel($entraRole);
                            }
                        }
                        if (!empty($displayNames)) {
                            $displayRole = implode(', ', $displayNames);
                        }
                    }
                }
                $displayRole = $displayRole ?? getFormattedRoleName($profileUserRole);
                $badgeClass = $roleBadgeColors[$displayRoleKey] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                ?>
                <div class="mb-4">
                    <span class="inline-block px-4 py-2 text-sm font-semibold rounded-full border <?php echo $badgeClass; ?>">
                        <?php echo htmlspecialchars($displayRole); ?>
                    </span>
                </div>

                <!-- Professional Info -->
                <?php if (!empty($profile['position']) || !empty($profile['company'])): ?>
                <div class="mb-4">
                    <?php if (!empty($profile['position'])): ?>
                    <p class="text-lg text-gray-700 mb-1">
                        <i class="fas fa-briefcase mr-2 text-gray-500"></i>
                        <?php echo htmlspecialchars($profile['position']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($profile['company'])): ?>
                    <p class="text-md text-gray-600">
                        <i class="fas fa-building mr-2 text-gray-500"></i>
                        <?php echo htmlspecialchars($profile['company']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Study Info -->
                <?php if (!empty($profile['study_program']) || !empty($profile['semester'])): ?>
                <div class="mb-4">
                    <?php if (!empty($profile['study_program'])): ?>
                    <p class="text-sm text-gray-600 mb-1">
                        <i class="fas fa-graduation-cap mr-2 text-gray-500"></i>
                        <?php echo htmlspecialchars($profile['study_program']); ?>
                        <?php if (!empty($profile['angestrebter_abschluss'])): ?>
                            &ndash; <?php echo htmlspecialchars($profile['angestrebter_abschluss']); ?>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($profile['semester'])): ?>
                    <p class="text-sm text-gray-600">
                        <i class="fas fa-book mr-2 text-gray-500"></i>
                        <?php echo htmlspecialchars($profile['semester']); ?>. Semester
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Industry -->
                <?php if (!empty($profile['industry'])): ?>
                <p class="text-sm text-gray-600 mb-4">
                    <i class="fas fa-industry mr-2 text-gray-500"></i>
                    <?php echo htmlspecialchars($profile['industry']); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contact Section -->
        <div class="border-t pt-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-address-card mr-2 text-blue-600"></i>
                Kontaktinformationen
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Email -->
                <?php if (!empty($profile['email'])): ?>
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-gray-600 rounded-full flex items-center justify-center text-white mr-3">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">E-Mail</p>
                        <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>" 
                           class="text-blue-600 hover:text-blue-800 font-medium">
                            <?php echo htmlspecialchars($profile['email']); ?>
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
                            <a href="<?php echo htmlspecialchars($linkedinUrl); ?>" 
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 font-medium">
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
                            <a href="<?php echo htmlspecialchars($xingUrl); ?>" 
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 font-medium">
                                Profil ansehen
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
