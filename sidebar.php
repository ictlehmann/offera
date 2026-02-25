<?php
/**
 * Responsive Sidebar Component
 *
 * Usage: include this file inside any page that needs the sidebar.
 * Expected session variables: user_name, user_email, user_role
 */

require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/models/Alumni.php';

$userName  = Auth::getUserName()  ?? 'Gast';
$userEmail = Auth::getUserEmail() ?? '';
$userRole  = $_SESSION['user_role'] ?? 'Benutzer';

$_sidebarUserId = Auth::getUserId();
$_sidebarProfileImageUrl = null;
if ($_sidebarUserId) {
    try {
        $_sidebarProfile = Alumni::getProfileByUserId($_sidebarUserId);
        if ($_sidebarProfile && !empty($_sidebarProfile['image_path'])) {
            $_defaultImg = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';
            $_resolved   = getProfileImageUrl($_sidebarProfile['image_path']);
            if ($_resolved !== $_defaultImg) {
                $_sidebarProfileImageUrl = $_resolved;
            }
        }
    } catch (Exception $_e) {
        // Ignore – show initials avatar as fallback
    }
}

// Generate initials from user name for the avatar fallback
$_sidebarInitials = 'U';
if (!empty($userName)) {
    $_parts = explode(' ', trim($userName));
    if (count($_parts) >= 2) {
        $_sidebarInitials = strtoupper(substr($_parts[0], 0, 1) . substr($_parts[count($_parts) - 1], 0, 1));
    } else {
        $_sidebarInitials = strtoupper(substr($userName, 0, 1));
    }
}
?>
<aside
    id="sidebar"
    class="
        flex flex-col h-full bg-gray-900 text-white
        w-56 md:w-64
        overflow-y-auto
    "
>
    <!-- ===================== LOGO ===================== -->
    <div class="flex items-center justify-center py-4 px-2 border-b border-gray-700 shrink-0">
        <!-- Mobile: w-16, Desktop (md+): w-32 -->
        <img
            src="/assets/img/ibc-logo.png"
            alt="IBC Logo"
            class="w-16 md:w-32 h-auto object-contain"
        />
    </div>

    <!-- ===================== NAVIGATION ===================== -->
    <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
        <a href="/dashboard"
           class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium hover:bg-gray-700 transition-colors">
            <!-- Dashboard icon -->
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/>
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="/angebote"
           class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium hover:bg-gray-700 transition-colors">
            <!-- Offers icon -->
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>Angebote</span>
        </a>
    </nav>

    <!-- ===================== USER PROFILE ===================== -->
    <div class="shrink-0 border-t border-gray-700 px-2 py-2 md:px-3 md:py-3">
        <!-- User info -->
        <div class="flex items-center gap-3 mb-2">
            <!-- Profile image or initials avatar -->
            <?php if (!empty($_sidebarProfileImageUrl)): ?>
            <img
                src="<?= htmlspecialchars($_sidebarProfileImageUrl) ?>"
                alt="Profilbild"
                class="w-9 h-9 rounded-full object-cover shrink-0"
            >
            <?php else: ?>
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-emerald-400 to-cyan-500 flex items-center justify-center text-white font-bold text-xs shrink-0">
                <?= htmlspecialchars($_sidebarInitials) ?>
            </div>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="text-sm font-semibold truncate leading-tight"><?= htmlspecialchars($userName) ?></p>
                <p class="text-xs text-gray-400 truncate leading-tight"><?= htmlspecialchars($userEmail) ?></p>
                <p class="text-xs text-gray-500 leading-tight"><?= htmlspecialchars($userRole) ?></p>
            </div>
        </div>

        <!--
            Buttons: on small screens show a 2×2 icon grid (icons only),
            on md+ screens show full-width buttons with icon + label.
        -->

        <!-- Mobile: 2×2 icon grid (hidden on md+) -->
        <div class="grid grid-cols-2 gap-1 md:hidden">
            <a href="/profil" title="Mein Profil"
               class="flex items-center justify-center rounded-md p-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M5.121 17.804A10.97 10.97 0 0112 15c2.59 0 4.974.894 6.879 2.375M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <a href="/einstellungen" title="Einstellungen"
               class="flex items-center justify-center rounded-md p-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <button type="button" id="theme-toggle-mobile" title="Lightmode"
                    class="flex items-center justify-center rounded-md p-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 7a5 5 0 000 10 5 5 0 000-10z"/>
                </svg>
            </button>
            <a href="/logout" title="Abmelden"
               class="flex items-center justify-center rounded-md p-2 text-gray-300 hover:bg-red-700 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/>
                </svg>
            </a>
        </div>

        <!-- Desktop (md+): full buttons with icon + label (hidden on mobile) -->
        <div class="hidden md:flex md:flex-col md:gap-1">
            <a href="/profil"
               class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M5.121 17.804A10.97 10.97 0 0112 15c2.59 0 4.974.894 6.879 2.375M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Mein Profil
            </a>
            <a href="/einstellungen"
               class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Einstellungen
            </a>
            <button type="button" id="theme-toggle-desktop"
                    class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors w-full text-left">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 7a5 5 0 000 10 5 5 0 000-10z"/>
                </svg>
                Lightmode
            </button>
            <a href="/logout"
               class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-gray-300 hover:bg-red-700 hover:text-white transition-colors">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/>
                </svg>
                Abmelden
            </a>
        </div>
    </div>
</aside>
