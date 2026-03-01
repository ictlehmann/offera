<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Accessible by ALL active roles (admin, board, head, member, candidate)
// Use Auth::check() which is the standard authentication method in this codebase
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to access members page
// Allowed: board members, head, member, candidate
$hasMembersAccess = Auth::canAccessPage('members');
if (!$hasMembersAccess) {
    header('Location: ../dashboard/index.php');
    exit;
}

// Get search filters
$searchKeyword = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';

// Get members using Member model
$members = Member::getAllActive(
    !empty($searchKeyword) ? $searchKeyword : null,
    !empty($roleFilter) ? $roleFilter : null
);

$title = 'Mitgliederverzeichnis - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Success Message -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); ?>
    </div>
    <?php 
        unset($_SESSION['success_message']); 
    endif; 
    ?>

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 mb-2">
                <i class="fas fa-users mr-3 text-blue-600"></i>
                Mitgliederverzeichnis
            </h1>
            <p class="text-gray-600">Entdecken und vernetzen Sie sich mit unseren aktiven Mitgliedern</p>
        </div>
        
        <!-- Edit My Profile Button - Only for Vorstand (all types), Resortleiter, Mitglied, Anwärter -->
        <?php if (Auth::isBoard() || Auth::hasRole(['head', 'member', 'candidate'])): ?>
        <a href="../auth/profile.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
            <i class="fas fa-user-edit mr-2"></i>
            Profil bearbeiten
        </a>
        <?php endif; ?>
    </div>

    <!-- Filter/Search Bar -->
    <div class="card p-6 mb-8">
        <form method="GET" action="" class="space-y-4 sm:space-y-0 sm:flex sm:gap-4">
            <!-- Search Input (Text) -->
            <div class="flex-1">
                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Nach Name suchen
                </label>
                <div class="directory-search-wrapper">
                    <i class="fas fa-search directory-search-icon" aria-hidden="true"></i>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($searchKeyword); ?>"
                        placeholder="Name eingeben..."
                        class="w-full py-3 bg-white text-gray-900 dark:bg-gray-800 dark:text-white transition-all"
                    >
                </div>
            </div>
            
            <!-- Role Filter (Dropdown) -->
            <div class="flex-1">
                <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    <i class="fas fa-filter mr-1 text-blue-600"></i>
                    Nach Rolle filtern
                </label>
                <select 
                    id="role" 
                    name="role"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 transition-all"
                >
                    <option value="">Alle</option>
                    <option value="candidate" <?php echo $roleFilter === 'candidate' ? 'selected' : ''; ?>>Anwärter</option>
                    <option value="member" <?php echo $roleFilter === 'member' ? 'selected' : ''; ?>>Mitglieder</option>
                    <option value="honorary_member" <?php echo $roleFilter === 'honorary_member' ? 'selected' : ''; ?>>Ehrenmitglieder</option>
                    <option value="head" <?php echo $roleFilter === 'head' ? 'selected' : ''; ?>>Ressortleiter</option>
                    <option value="alumni" <?php echo $roleFilter === 'alumni' ? 'selected' : ''; ?>>Alumni</option>
                    <option value="alumni_board" <?php echo $roleFilter === 'alumni_board' ? 'selected' : ''; ?>>Alumni-Vorstand</option>
                    <option value="alumni_auditor" <?php echo $roleFilter === 'alumni_auditor' ? 'selected' : ''; ?>>Alumni-Finanzprüfer</option>
                    <option value="board_finance" <?php echo $roleFilter === 'board_finance' ? 'selected' : ''; ?>>Vorstand Finanzen und Recht</option>
                    <option value="board_internal" <?php echo $roleFilter === 'board_internal' ? 'selected' : ''; ?>>Vorstand Intern</option>
                    <option value="board_external" <?php echo $roleFilter === 'board_external' ? 'selected' : ''; ?>>Vorstand Extern</option>
                </select>
            </div>
            
            <!-- Search Button -->
            <div class="sm:flex sm:items-end">
                <button 
                    type="submit"
                    class="w-full sm:w-auto px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl"
                >
                    <i class="fas fa-search mr-2"></i>
                    Suchen
                </button>
            </div>
        </form>
        
        <!-- Clear Filters -->
        <?php if (!empty($searchKeyword) || !empty($roleFilter)): ?>
            <div class="mt-4">
                <a href="index.php" class="text-sm text-blue-600 hover:text-blue-800 transition-colors">
                    <i class="fas fa-times-circle mr-1"></i>
                    Alle Filter zurücksetzen
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Results Count -->
    <div class="mb-6">
        <p class="text-gray-600">
            <strong><?php echo count($members); ?></strong> 
            <?php echo count($members) === 1 ? 'Mitglied' : 'Mitglieder'; ?> gefunden
        </p>
    </div>

    <!-- Results Grid: Responsive (1 col mobile, 3 cols desktop) -->
    <?php if (empty($members)): ?>
        <div class="card p-12 text-center">
            <i class="fas fa-user-slash text-6xl text-gray-300 mb-4"></i>
            <p class="text-xl text-gray-600 mb-2">Keine Mitglieder gefunden</p>
            <p class="text-gray-500">Bitte Suchfilter anpassen</p>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3">
            <?php foreach ($members as $member): ?>
                <?php
                // Determine role badge color
                $roleBadgeColors = [
                    'board_finance' => 'bg-purple-100 text-purple-800 border-purple-300',
                    'board_internal' => 'bg-purple-100 text-purple-800 border-purple-300',
                    'board_external' => 'bg-purple-100 text-purple-800 border-purple-300',
                    'head' => 'bg-blue-100 text-blue-800 border-blue-300',
                    'member' => 'bg-green-100 text-green-800 border-green-300',
                    'candidate' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                    'alumni' => 'bg-gray-100 text-gray-800 border-gray-300',
                    'alumni_board' => 'bg-indigo-100 text-indigo-800 border-indigo-300',
                    'alumni_auditor' => 'bg-indigo-100 text-indigo-800 border-indigo-300',
                    'honorary_member' => 'bg-amber-100 text-amber-800 border-amber-300',
                ];
                
                // Display role: prefer Entra-derived display_role, fall back to local role label
                $displayRoleKey = Auth::getPrimaryEntraRoleKey($member['entra_roles'] ?? null, $member['role']);
                $badgeClass = $roleBadgeColors[$displayRoleKey] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                $displayRole = htmlspecialchars($member['display_role'] ?? Auth::getRoleLabel($member['role']));
                
                // Generate initials for fallback
                $initials = getMemberInitials($member['first_name'], $member['last_name']);
                
                // Use default profile image if image_path is empty
                $imageSrc = !empty($member['image_path'])
                    ? '../../uploads/profile_photos/' . str_replace("\0", '', basename($member['image_path']))
                    : '../../assets/img/default_profil.png';
                
                // Info snippet: Show position, or study_program + degree
                $infoSnippet = '';
                if (!empty($member['position'])) {
                    $infoSnippet = $member['position'];
                } else {
                    // If position is empty, try study_program and degree
                    $studyParts = [];
                    // Check both study_program and studiengang fields
                    $studyProgram = !empty($member['study_program']) ? $member['study_program'] : 
                                    (!empty($member['studiengang']) ? $member['studiengang'] : '');
                    // Check both degree and angestrebter_abschluss fields
                    $degree = !empty($member['degree']) ? $member['degree'] : 
                              (!empty($member['angestrebter_abschluss']) ? $member['angestrebter_abschluss'] : '');
                    
                    if (!empty($studyProgram)) {
                        $studyParts[] = $studyProgram;
                    }
                    if (!empty($degree)) {
                        $studyParts[] = $degree;
                    }
                    
                    if (!empty($studyParts)) {
                        $infoSnippet = implode(' - ', $studyParts);
                    }
                }
                ?>
                <div class="col">
                <div class="card directory-card p-3 d-flex flex-column h-100 position-relative">
                    <!-- Role Badge: Different colors for each role - Top Right Corner -->
                    <div class="position-absolute top-0 end-0 mt-3 me-3">
                        <span class="inline-block px-3 py-1 text-xs font-semibold directory-role-badge border <?php echo $badgeClass; ?>">
                            <?php echo $displayRole; ?>
                        </span>
                    </div>
                    
                    <!-- Profile Image (Circle, top center) -->
                    <div class="flex justify-center mb-3 mt-2">
                        <?php
                        $avatarColor = getAvatarColor($member['first_name'] . ' ' . $member['last_name']);
                        ?>
                        <!-- Image with fallback to placeholder on error -->
                        <div class="flex items-center justify-center text-white font-bold overflow-hidden"
                             style="background-color:<?php echo htmlspecialchars($avatarColor); ?>; width:72px; height:72px; border-radius:9999px; font-size:1.5rem;">
                            <img 
                                src="<?php echo htmlspecialchars($imageSrc); ?>" 
                                alt="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>"
                                class="rounded-full object-cover border-4 border-white shadow-md mx-auto"
                                style="width:72px;height:72px;"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                            <div style="display:none;" class="w-full h-full flex items-center justify-center">
                                <?php echo htmlspecialchars($initials); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Name (Bold) -->
                    <h3 class="fs-6 directory-card-name text-gray-800 text-center mb-2">
                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                    </h3>
                    
                    <!-- Info Snippet: 'Position' or 'Studium + Degree' -->
                    <?php if (!empty($infoSnippet)): ?>
                    <div class="text-center mb-3 flex-grow-1 d-flex align-items-center justify-content-center" style="min-height:3rem;">
                        <p class="small text-secondary mb-0">
                            <i class="fas fa-briefcase me-1 text-muted"></i>
                            <?php echo htmlspecialchars($infoSnippet); ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="flex-grow-1" style="min-height:3rem;"></div>
                    <?php endif; ?>
                    
                    <!-- Contact Icons: Round buttons for Mail, LinkedIn and Xing (if set) -->
                    <div class="flex justify-center gap-3 mt-4 mb-3">
                        <!-- Mail Icon -->
                        <?php if (!empty($member['email'])): ?>
                            <a 
                                href="mailto:<?php echo htmlspecialchars($member['email']); ?>" 
                                class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"
                                title="E-Mail senden"
                            >
                                <i class="fas fa-envelope"></i>
                            </a>
                        <?php endif; ?>
                        
                        <!-- LinkedIn Icon (if set) -->
                        <?php if (!empty($member['linkedin_url'])): ?>
                            <?php
                            // Validate LinkedIn URL to prevent XSS attacks
                            $linkedinUrl = $member['linkedin_url'];
                            $isValidLinkedIn = (
                                strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                                strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                                strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                                strpos($linkedinUrl, 'http://www.linkedin.com') === 0
                            );
                            ?>
                            <?php if ($isValidLinkedIn): ?>
                            <a 
                                href="<?php echo htmlspecialchars($linkedinUrl); ?>" 
                                target="_blank"
                                rel="noopener noreferrer"
                                class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"
                                title="LinkedIn Profil"
                            >
                                <i class="fab fa-linkedin-in"></i>
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Xing Icon (if set) -->
                        <?php if (!empty($member['xing_url'])): ?>
                            <?php
                            // Validate Xing URL to prevent XSS attacks
                            $xingUrl = $member['xing_url'];
                            $isValidXing = (
                                strpos($xingUrl, 'https://xing.com') === 0 ||
                                strpos($xingUrl, 'https://www.xing.com') === 0 ||
                                strpos($xingUrl, 'http://xing.com') === 0 ||
                                strpos($xingUrl, 'http://www.xing.com') === 0
                            );
                            ?>
                            <?php if ($isValidXing): ?>
                            <a 
                                href="<?php echo htmlspecialchars($xingUrl); ?>" 
                                target="_blank"
                                rel="noopener noreferrer"
                                class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"
                                title="Xing Profil"
                            >
                                <i class="fab fa-xing"></i>
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Action: 'Profil ansehen' Button -->
                    <a 
                        href="../alumni/view.php?id=<?php echo $member['profile_id']; ?>&return_to=members"
                        class="btn btn-primary w-100 fw-semibold shadow-sm"
                    >
                        <i class="fas fa-user me-2"></i>
                        Profil ansehen
                    </a>
                </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
