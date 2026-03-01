<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../handlers/AuthHandler.php';
require_once __DIR__ . '/../handlers/CSRFHandler.php';

// DEBUG: Uncomment to force role for testing
// $_SESSION['user_role'] = 'board_finance';

// Check if profile is incomplete and redirect to profile page (unless already on profile page)
if (Auth::check() && isset($_SESSION['profile_incomplete']) && $_SESSION['profile_incomplete'] === true) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    // Allow access only to profile.php and logout
    if ($currentPage !== 'profile.php' && $currentPage !== 'logout.php') {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $baseUrl . '/pages/auth/profile.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0066b3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?php echo $title ?? 'IBC Intranet'; ?></title>
    <link rel="icon" type="image/webp" href="<?php echo asset('assets/img/cropped_maskottchen_32x32.webp'); ?>">
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="//cdn-uicons.flaticon.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <link rel="preload" href="<?php echo asset('assets/css/theme.css'); ?>" as="style">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css'); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false },
            darkMode: ['class', '.dark-mode'],
            theme: {
                extend: {
                    colors: {
                        'ibc-green': 'var(--ibc-green)',
                        'ibc-green-light': 'var(--ibc-green-light)',
                        'ibc-green-dark': 'var(--ibc-green-dark)',
                        'ibc-blue': 'var(--ibc-blue)',
                        'ibc-blue-light': 'var(--ibc-blue-light)',
                        'ibc-blue-dark': 'var(--ibc-blue-dark)',
                        'ibc-accent': 'var(--ibc-accent)',
                        'ibc-accent-light': 'var(--ibc-accent-light)',
                        'ibc-accent-dark': 'var(--ibc-accent-dark)',
                    },
                    fontFamily: {
                        'sans': ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
                    },
                    boxShadow: {
                        'glow': 'var(--shadow-glow-green)',
                        'premium': 'var(--shadow-premium)',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-rounded/css/uicons-regular-rounded.css">
    <style>
        /* Sidebar - Permanent IBC Corporate Blue (#00457D) for both light and dark modes */
        .sidebar {
            background: var(--sidebar-bg-light) !important; /* IBC Corporate Blue */
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }
        
        /* Custom scrollbar styling for sidebar */
        .sidebar::-webkit-scrollbar,
        .sidebar-scroll::-webkit-scrollbar {
            width: 8px;
        }
        
        .sidebar::-webkit-scrollbar-track,
        .sidebar-scroll::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 4px;
        }
        
        .sidebar::-webkit-scrollbar-thumb,
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover,
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        
        /* Firefox scrollbar styling */
        .sidebar,
        .sidebar-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(0, 0, 0, 0.2) rgba(0, 0, 0, 0.05);
        }
        
        /* Sidebar styling for dark mode - dark gray with subtle contrast */
        body.dark-mode .sidebar {
            background: var(--sidebar-bg-dark) !important;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        /* Dark mode sidebar scrollbar */
        body.dark-mode .sidebar::-webkit-scrollbar-track,
        body.dark-mode .sidebar-scroll::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
        }
        
        body.dark-mode .sidebar::-webkit-scrollbar-thumb,
        body.dark-mode .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
        }
        
        body.dark-mode .sidebar::-webkit-scrollbar-thumb:hover,
        body.dark-mode .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }
        
        body.dark-mode .sidebar,
        body.dark-mode .sidebar-scroll {
            scrollbar-color: rgba(255, 255, 255, 0.3) rgba(0, 0, 0, 0.2);
        }

        /* Sidebar navigation items - rounded pill style */
        .sidebar nav > a,
        .sidebar nav .menu-item-with-submenu > a {
            margin: 2px 8px;
            border-radius: 8px;
            padding: 0.625rem 1rem;
        }

        .sidebar nav .submenu a {
            margin: 1px 8px;
            border-radius: 8px;
            padding: 0.5rem 1rem 0.5rem 2.5rem;
        }

        /* Sidebar footer buttons */
        .sidebar-footer-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.4rem 0.625rem;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
            cursor: pointer;
            text-decoration: none;
            margin-bottom: 0.25rem;
        }
        .sidebar-footer-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(255, 255, 255, 0.25);
            color: #fff;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .sidebar-footer-btn:active {
            transform: translateY(0);
            box-shadow: none;
        }
        .sidebar-footer-btn.active-btn {
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.35);
        }
        .sidebar-footer-btn i {
            width: 1.1rem;
            text-align: center;
            font-size: 0.9rem;
        }
        .sidebar-logout-btn {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.3);
            color: rgba(255, 200, 200, 0.95);
            margin-bottom: 0;
        }
        .sidebar-logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            border-color: rgba(239, 68, 68, 0.5);
            color: #fff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        /* Mobile topbar and button styling */
        #mobile-menu-btn {
            border: none;
            outline: none;
        }
        
        #mobile-menu-btn:focus-visible {
            outline: 2px solid rgba(255,255,255,0.8);
            outline-offset: 2px;
        }
        
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg, 16px);
            box-shadow: var(--shadow-card, 0 1px 3px rgba(0,0,0,0.04), 0 6px 16px rgba(0,0,0,0.06));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0, 0, 0, 0.06);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--ibc-green), var(--ibc-blue));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .card:hover {
            box-shadow: var(--shadow-card-hover, 0 4px 12px rgba(0,0,0,0.08), 0 20px 40px rgba(0,0,0,0.12));
            transform: translateY(-4px);
            border-color: rgba(0, 102, 179, 0.12);
        }
        .card:hover::before {
            opacity: 1;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-mid) 50%, var(--ibc-blue) 100%) !important;
            background-size: 200% 200% !important;
            color: white !important;
            padding: 0.75rem 2rem !important;
            border-radius: var(--radius-md, 12px) !important;
            transition: all 0.3s ease !important;
            font-weight: 600 !important;
            letter-spacing: 0.02em !important;
            box-shadow: 0 4px 15px rgba(0, 166, 81, 0.3) !important;
            border: none !important;
        }
        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 25px rgba(0, 166, 81, 0.4) !important;
            color: white !important;
        }
        
        /* Mobile view improvements */
        @media (max-width: 767px) {
            /* Sidebar improvements for mobile */
            .sidebar {
                width: 85% !important;
                max-width: 320px !important;
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.3) !important;
                top: 60px !important; /* Start below the mobile topbar */
                height: calc(100vh - 60px) !important;
                z-index: 1050 !important; /* Above overlay (1040), below topbar (1060) */
            }
            
            .sidebar .sidebar-scroll {
                padding-bottom: 2rem !important;
            }
            
            /* Better logo sizing on mobile */
            .sidebar img[alt="IBC Logo"] {
                max-width: 90% !important;
                margin: 0 auto !important;
            }
            
            .card {
                padding: 1rem !important;
                border-radius: 12px !important;
            }
            
            /* Fix text overflow in cards */
            .card p, .card div, .card span {
                word-wrap: break-word;
                overflow-wrap: break-word;
                hyphens: auto;
            }
            
            /* Better spacing on mobile - add top padding for topbar */
            main {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                padding-bottom: 1rem !important;
                padding-top: calc(var(--topbar-height, 60px) + 1rem) !important; /* Ensure content doesn't hide under topbar */
                margin-left: 0 !important;
            }
            
            /* Prevent horizontal overflow */
            table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 8px;
            }
            
            table thead {
                display: table-header-group;
            }
            
            table tbody {
                display: table-row-group;
            }
            
            /* Table cells: no-wrap to keep scrollable tables readable */
            th, td {
                white-space: nowrap;
            }
            
            /* Better form spacing on mobile */
            form input, form select, form textarea {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 0.875rem !important;
                border-radius: 10px !important;
            }
            
            /* Stack action/button groups vertically on mobile - but NOT icon+text combos */
            .flex.space-x-2:not(.items-center),
            .flex.space-x-3:not(.items-center),
            .flex.space-x-4:not(.items-center),
            .flex.gap-2:not(.items-center),
            .flex.gap-3:not(.items-center),
            .flex.gap-4:not(.items-center) {
                flex-direction: column !important;
                gap: 0.75rem !important;
            }
            
            .flex.space-x-2:not(.items-center) > *,
            .flex.space-x-3:not(.items-center) > *,
            .flex.space-x-4:not(.items-center) > *,
            .flex.gap-2:not(.items-center) > *,
            .flex.gap-3:not(.items-center) > *,
            .flex.gap-4:not(.items-center) > * {
                width: 100% !important;
                margin: 0 !important;
            }
            
            /* Preserve horizontal layout in sidebar (it's a wide slide-out drawer on mobile) */
            .sidebar .flex.gap-2,
            .sidebar .flex.gap-3,
            .sidebar .flex.items-center {
                flex-direction: row !important;
            }
            .sidebar .flex.gap-2 > *,
            .sidebar .flex.gap-3 > * {
                width: auto !important;
            }
            
            /* Improve heading sizes on mobile */
            main h1 {
                font-size: 1.75rem !important;
                line-height: 1.2;
                margin-bottom: 1rem;
            }
            
            main h2 {
                font-size: 1.5rem !important;
                line-height: 1.3;
                margin-bottom: 0.875rem;
            }
            
            main h3 {
                font-size: 1.25rem !important;
                line-height: 1.4;
                margin-bottom: 0.75rem;
            }
            
            /* Better image scaling on mobile */
            img:not([class*="w-"]) {
                max-width: 100%;
                height: auto;
            }
            
            /* Ensure grids stack on mobile - for auto-responsive grids */
            .grid:not(.grid-no-stack):not(.grid-cols-1) {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
            }
            
            /* Improved stat cards on mobile */
            .stat-icon {
                width: 48px !important;
                height: 48px !important;
                font-size: 1.25rem !important;
            }
            
            /* Better badge sizing */
            .badge, [class*="badge"] {
                padding: 0.375rem 0.75rem !important;
                font-size: 0.8125rem !important;
            }
            
            /* Improve sidebar navigation on mobile */
            .sidebar nav a {
                padding: 0.875rem 1rem !important;
                margin: 2px 8px !important;
                font-size: 0.9375rem !important;
                border-radius: 8px !important;
            }
            
            /* Better submenu styling on mobile */
            .submenu a {
                padding-left: 2.25rem !important;
                font-size: 0.875rem !important;
            }
        }
        
        /* Tablet view improvements */
        @media (min-width: 768px) and (max-width: 1024px) {
            main {
                padding: 1.5rem !important;
                padding-top: 1.5rem !important;
            }
            
            .card {
                padding: 1.5rem !important;
            }
            
            /* 2-column grid on tablets - for auto-responsive grids */
            .grid:not(.grid-no-stack):not(.grid-cols-1) {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            /* Adjust sidebar width on tablets */
            .sidebar {
                width: 16rem !important;
            }
            
            main h1 {
                font-size: 2rem !important;
            }
            
            main h2 {
                font-size: 1.625rem !important;
            }
        }
        @media (min-width: 1025px) {
            main {
                padding: 2rem !important;
            }
            
            .card {
                padding: 2rem !important;
            }
            
            /* Better spacing for large screens */
            .container {
                max-width: 1400px;
                margin: 0 auto;
            }
        }
        
        /* Extra large screens */
        @media (min-width: 1536px) {
            main {
                padding: 2.5rem !important;
            }
            
            .card {
                padding: 2.5rem !important;
            }
            
            .container {
                max-width: 1600px;
            }
        }
        
        /* Landscape mobile optimization */
        @media (max-height: 500px) and (orientation: landscape) and (max-width: 767px) {
            /* Compact everything for landscape mobile */
            .sidebar {
                width: 14rem !important;
            }
            
            .sidebar nav a {
                padding: 0.5rem 1rem !important;
                font-size: 0.875rem !important;
            }
            
            main {
                padding-top: 4rem !important;
            }
        }
        
        /* High DPI displays */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            body {
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
            
            .card {
                border-width: 0.5px;
            }
        }
        
        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            /* Larger touch targets */
            a, button, input[type="submit"], input[type="button"] {
                min-height: 44px;
                min-width: 44px;
            }
            
            /* Better tap feedback */
            a:active, button:active {
                opacity: 0.7;
                transform: scale(0.98);
                transition: all 0.1s ease;
            }
            
            /* Remove hover effects on touch devices */
            .card:hover {
                transform: translateY(-2px); /* Reduce hover effect */
            }
        }
        
        /* Ensure long text doesn't overflow */
        .text-sm, .text-xs {
            overflow-wrap: break-word;
            word-break: break-word;
        }
        
        /* Skip link accessibility styles */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: #00a651;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            z-index: 100;
            border-radius: 0 0 4px 0;
            font-weight: 600;
        }
        
        .skip-link:focus {
            top: 0;
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-800 dark:bg-slate-900 dark:text-slate-200" data-user-theme="<?php echo htmlspecialchars($currentUser['theme_preference'] ?? 'auto'); ?>">
    <!-- Skip to main content link for accessibility -->
    <a href="#main-content" class="skip-link">Zum Hauptinhalt springen</a>
    
    <script>
        // Apply theme immediately to prevent flash of unstyled content (FOUC)
        (function() {
            const userTheme = document.body.getAttribute('data-user-theme') || 'auto';
            const savedTheme = localStorage.getItem('theme') || userTheme;
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'dark';
            } else if (savedTheme === 'light') {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'light';
            } else { // auto
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.body.classList.add('dark-mode', 'dark');
                    document.documentElement.style.colorScheme = 'dark';
                }
            }
        })();
    </script>
    <!-- Mobile Menu Overlay -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <!-- Mobile Top Navbar (visible only below md breakpoint) -->
    <nav class="mobile-topbar md:hidden fixed top-0 left-0 right-0 flex items-center justify-between px-4 py-3 shadow-lg">
        <button id="mobile-menu-btn" class="flex items-center justify-center w-10 h-10 rounded-lg bg-white/10 hover:bg-white/20 transition-all duration-200 active:scale-95 border border-white/20" aria-label="Menü öffnen" aria-expanded="false" aria-controls="sidebar">
            <svg class="w-6 h-6 text-white" id="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path id="menu-icon-top" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16" class="transition-all duration-300"></path>
                <path id="menu-icon-middle" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 12h16" class="transition-all duration-300"></path>
                <path id="menu-icon-bottom" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 18h16" class="transition-all duration-300"></path>
            </svg>
        </button>
        <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>" alt="IBC Logo" class="h-8 w-auto drop-shadow-md">
        <button id="mobile-theme-toggle" class="flex items-center justify-center w-10 h-10 rounded-lg bg-white/10 hover:bg-white/20 transition-all duration-200 border border-white/20" aria-label="Zu Darkmode wechseln">
            <i id="mobile-theme-icon" class="fas fa-moon text-white text-sm"></i>
        </button>
    </nav>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed left-0 top-0 h-screen w-64 md:w-72 transform -translate-x-full md:translate-x-0 transition-transform duration-300 z-40 text-white shadow-2xl flex flex-col">
        <?php 
        $currentUser = Auth::user();
        $userRole = $currentUser['role'] ?? '';
        ?>
        <div class="p-5 flex-1 overflow-y-auto sidebar-scroll">
            <!-- IBC Logo in Navbar -->
            <div class="mb-6 px-3 pt-2">
                <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>" alt="IBC Logo" class="w-full h-auto drop-shadow-lg" decoding="async">
            </div>
            
            <nav aria-label="Hauptnavigation">
                <!-- Dashboard (All) -->
                <a href="<?php echo asset('pages/dashboard/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/dashboard/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-home w-5 mr-3"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Alumni (All) -->
                <a href="<?php echo asset('pages/alumni/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/alumni/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-user-graduate w-5 mr-3"></i>
                    <span>Alumni-Datenbank</span>
                </a>

                <!-- Blog (All) -->
                <a href="<?php echo asset('pages/blog/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/blog/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-newspaper w-5 mr-3"></i>
                    <span>Blog</span>
                </a>

                <!-- Events (All) -->
                <a href="<?php echo asset('pages/events/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/events/') && !isActivePath('/events/helpers.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-calendar w-5 mr-3"></i>
                    <span>Events</span>
                </a>

                <!-- Helfersystem (All) -->
                <a href="<?php echo asset('pages/events/helpers.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/events/helpers.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-hands-helping w-5 mr-3"></i>
                    <span>Helfersystem</span>
                </a>

                <!-- Ideenbox (Members, Candidates, Head, Board) -->
                <?php if (Auth::canAccessPage('ideas')): ?>
                <a href="<?php echo asset('pages/ideas/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/ideas/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-lightbulb w-5 mr-3"></i>
                    <span>Ideenbox</span>
                </a>
                <?php endif; ?>

                <!-- Inventar (All) - Parent with submenu -->
                <div class="menu-item-with-submenu">
                    <a href="<?php echo asset('pages/inventory/index.php'); ?>" 
                       class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/inventory/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                        <i class="fas fa-box w-5 mr-3"></i>
                        <span class="flex-1">Inventar</span>
                        <i class="fas fa-chevron-down text-xs transition-transform duration-200 submenu-chevron"></i>
                    </a>

                    <!-- Submenu -->
                    <div class="submenu">
                        <!-- Meine Ausleihen (All) - Indented -->
                        <a href="<?php echo asset('pages/inventory/my_rentals.php'); ?>" 
                           class="flex items-center justify-start px-4 pr-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 submenu-item <?php echo isActivePath('/my_rentals.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                            <i class="fas fa-clipboard-list w-5 mr-3"></i>
                            <span>Meine Ausleihen</span>
                        </a>
                    </div>
                </div>

                <!-- Mitglieder (Board, Head, Member, Candidate) -->
                <?php if (Auth::canAccessPage('members')): ?>
                <a href="<?php echo asset('pages/members/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/members/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-users w-5 mr-3"></i>
                    <span>Mitglieder-Datenbank</span>
                </a>
                <?php endif; ?>

                <!-- Nützliche Links (Board + Alumni Board + Alumni Auditor) -->
                <?php if (in_array($userRole, ['board_finance', 'board_internal', 'board_external', 'alumni_board', 'alumni_auditor'])): ?>
                <a href="<?php echo asset('pages/links/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/links/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-link w-5 mr-3"></i>
                    <span>Nützliche Links</span>
                </a>
                <?php endif; ?>

                <!-- Projekte (All) -->
                <a href="<?php echo asset('pages/projects/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/projects/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-project-diagram w-5 mr-3"></i>
                    <span>Projekte</span>
                </a>

                <!-- Rechnungen (Only board_finance) -->
                <?php if (Auth::canManageInvoices()): ?>
                <a href="<?php echo asset('pages/invoices/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/invoices/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar w-5 mr-3"></i>
                    <span>Rechnung einreichen</span>
                </a>
                <?php endif; ?>

                <!-- Schulungsanfrage (Alumni, Alumni-Board) -->
                <?php if (Auth::canAccessPage('training_requests')): ?>
                <a href="<?php echo asset('pages/alumni/requests.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/alumni/requests.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher w-5 mr-3"></i>
                    <span>Schulungsanfrage</span>
                </a>
                <?php endif; ?>

                <!-- Shop (All authenticated users) -->
                <a href="<?php echo asset('pages/shop/index.php'); ?>"
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/shop/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-shopping-cart w-5 mr-3"></i>
                    <span>Shop</span>
                    <?php
                        $sidebarCartCount = 0;
                        if (!empty($_SESSION['shop_cart'])) {
                            $sidebarCartCount = array_sum(array_column($_SESSION['shop_cart'], 'quantity'));
                        }
                        if ($sidebarCartCount > 0):
                    ?>
                    <span class="ml-auto bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                        <?php echo $sidebarCartCount > 99 ? '99+' : $sidebarCartCount; ?>
                    </span>
                    <?php endif; ?>
                </a>

                <!-- Umfragen (Polls - All authenticated users) -->
                <?php if (Auth::canAccessPage('polls')): ?>
                <a href="<?php echo asset('pages/polls/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/polls/') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-poll w-5 mr-3"></i>
                    <span>Umfragen</span>
                </a>
                <?php endif; ?>

                <!-- Admin Section Divider -->
                <?php if (Auth::canManageUsers() || Auth::isAdmin() || Auth::canApproveReturns()): ?>
                <div class="my-3 mx-4">
                    <div class="border-t border-white/10"></div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40 mt-3 px-2 text-left">Administration</p>
                </div>
                <?php endif; ?>

                <!-- Benutzerverwaltung (All board members who can manage users) -->
                <?php if (Auth::canManageUsers()): ?>
                <a href="<?php echo asset('pages/admin/users.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/users.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-users-cog w-5 mr-3"></i>
                    <span>Benutzerverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Admin Dashboard -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/index.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/index.php') || (isActivePath('/admin/') && !isActivePath('/admin/users') && !isActivePath('/admin/stats') && !isActivePath('/admin/audit') && !isActivePath('/admin/db_maintenance') && !isActivePath('/admin/settings')) ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-tachometer-alt w-5 mr-3"></i>
                    <span>Dashboard</span>
                </a>
                <?php endif; ?>

                <!-- Inventarverwaltung -->
                <?php if (Auth::hasRole(['board_finance', 'board_internal', 'board_external', 'head']) || Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/rental_returns.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/rental_returns.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-clipboard-check w-5 mr-3"></i>
                    <span>Inventarverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Bewerbungsverwaltung (Board only) -->
                <?php if (Auth::isBoard()): ?>
                <a href="<?php echo asset('pages/admin/project_applications.php'); ?>"
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/project_applications.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-file-alt w-5 mr-3"></i>
                    <span>Bewerbungsverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Masseneinladungen (Admins only) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/mass_invitations.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/mass_invitations.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-mail-bulk w-5 mr-3"></i>
                    <span>Masseneinladungen</span>
                </a>
                <?php endif; ?>

                <!-- Shop-Verwaltung (Board + Head) -->
                <?php if (Auth::hasRole(['board_finance', 'board_internal', 'board_external', 'head'])): ?>
                <a href="<?php echo asset('pages/admin/shop_manage.php'); ?>"
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/shop_manage.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-store w-5 mr-3"></i>
                    <span>Shop-Verwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Systemeinstellungen (Board roles + alumni_board + alumni_auditor) -->
                <?php if (Auth::canAccessSystemSettings()): ?>
                <a href="<?php echo asset('pages/admin/settings.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/settings.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-cogs w-5 mr-3"></i>
                    <span>Systemeinstellungen</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken Section Divider -->
                <?php if (Auth::isAdmin() || Auth::hasRole(['board_finance', 'board_internal', 'board_external', 'head'])): ?>
                <div class="my-3 mx-4">
                    <div class="border-t border-white/10"></div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40 mt-3 px-2 text-left">Statistiken</p>
                </div>
                <?php endif; ?>

                <!-- Event-Statistiken (Admin roles only) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/event_stats.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/event_stats.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-chart-bar w-5 mr-3"></i>
                    <span>Event-Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Shop-Statistiken (Board + Head) -->
                <?php if (Auth::hasRole(['board_finance', 'board_internal', 'board_external', 'head'])): ?>
                <a href="<?php echo asset('pages/admin/shop_stats.php'); ?>"
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/shop_stats.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-chart-line w-5 mr-3"></i>
                    <span>Shop-Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/stats.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/stats.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-chart-pie w-5 mr-3"></i>
                    <span>Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Audit Logs (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/audit.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/audit.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-clipboard-list w-5 mr-3"></i>
                    <span>Audit Logs</span>
                </a>
                <?php endif; ?>

                <!-- System Health (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/db_maintenance.php'); ?>" 
                   class="flex items-center justify-start px-4 py-2 text-white hover:bg-white/10 transition-colors duration-200 <?php echo isActivePath('/admin/db_maintenance.php') ? 'bg-white/20 text-white border-l-4 border-ibc-green' : ''; ?>">
                    <i class="fas fa-database w-5 mr-3"></i>
                    <span>System Health</span>
                </a>
                <?php endif; ?>

            </nav>
        </div>

        <!-- User Profile Section -->
        <div class='sidebar-footer mt-auto pt-2 pb-2 px-3'>
            <?php 
            $currentUser = Auth::user();
            
            // Initialize default values
            $firstname = '';
            $lastname = '';
            $email = '';
            $role = 'User';
            $navProfileImageUrl = '';
            
            // Only try to get profile if user is logged in
            if ($currentUser && isset($currentUser['id'])) {
                // Try to get name from alumni_profiles table first
                require_once __DIR__ . '/../models/Alumni.php';
                $profile = Alumni::getProfileByUserId($currentUser['id']);
                
                // Resolve profile image for the desktop top navbar
                if ($profile && !empty($profile['image_path'])) {
                    $_defaultImg = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';
                    $_resolved = getProfileImageUrl($profile['image_path']);
                    if ($_resolved !== $_defaultImg) {
                        $navProfileImageUrl = $_resolved;
                    }
                }
                
                // Profile data may be user-edited, so don't transform it
                if ($profile && !empty($profile['first_name'])) {
                    $firstname = $profile['first_name'];
                    $lastname = $profile['last_name'] ?? '';
                } elseif (!empty($currentUser['firstname'])) {
                    $firstname = $currentUser['firstname'];
                    $lastname = $currentUser['lastname'] ?? '';
                }
                
                $email = $currentUser['email'] ?? '';
                $role = $currentUser['role'] ?? 'User';
                
                // Check for Entra roles - priority: entra_roles from user table, then session azure_roles, then fallback to internal role
                $displayRoles = [];
                
                // Debug logging for role determination
                if (!empty($currentUser['entra_roles'])) {
                    error_log("main_layout.php: User " . intval($currentUser['id']) . " has entra_roles in database: " . $currentUser['entra_roles']);
                }
                if (!empty($_SESSION['azure_roles'])) {
                    error_log("main_layout.php: Session azure_roles for user " . intval($currentUser['id']) . ": " . (is_array($_SESSION['azure_roles']) ? json_encode($_SESSION['azure_roles']) : $_SESSION['azure_roles']));
                }
                if (!empty($_SESSION['entra_roles'])) {
                    error_log("main_layout.php: Session entra_roles for user " . intval($currentUser['id']) . ": " . (is_array($_SESSION['entra_roles']) ? json_encode($_SESSION['entra_roles']) : $_SESSION['entra_roles']));
                }
                
                if (!empty($currentUser['entra_roles'])) {
                    // Parse JSON array from database - groups from Microsoft Graph are already human-readable (displayName)
                    // No translation needed unlike azure_roles which use internal lowercase format
                    $rolesArray = json_decode($currentUser['entra_roles'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($rolesArray)) {
                        // Extract displayName from each group object (groups now contain both id and displayName)
                        $displayRoles = extractGroupDisplayNames($rolesArray);
                    } else {
                        error_log("Failed to decode entra_roles in main_layout for user ID " . intval($currentUser['id']) . ": " . json_last_error_msg());
                    }
                } elseif (!empty($_SESSION['entra_roles'])) {
                    // Prefer entra_roles from session (groups from Microsoft Graph)
                    if (is_array($_SESSION['entra_roles'])) {
                        // Extract displayName from each group object (groups now contain both id and displayName)
                        $displayRoles = extractGroupDisplayNames($_SESSION['entra_roles']);
                    }
                } elseif (!empty($_SESSION['azure_roles'])) {
                    // Check session variable as alternative (App Roles from JWT)
                    if (is_array($_SESSION['azure_roles'])) {
                        $displayRoles = array_filter(array_map('translateAzureRole', $_SESSION['azure_roles']));
                    } else {
                        // Try to decode if it's JSON string
                        $sessionRoles = json_decode($_SESSION['azure_roles'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($sessionRoles)) {
                            $displayRoles = array_filter(array_map('translateAzureRole', $sessionRoles));
                        }
                    }
                }
                
                // If no Entra roles found, use internal role as fallback
                if (empty($displayRoles)) {
                    $displayRoles = [translateRole($role)];
                }
            }
            
            // Generate initials with proper fallbacks
            if (!empty($firstname) && !empty($lastname)) {
                $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
            } elseif (!empty($firstname)) {
                $initials = strtoupper(substr($firstname, 0, 1));
            } elseif (!empty($lastname)) {
                $initials = strtoupper(substr($lastname, 0, 1));
            } elseif (!empty($email)) {
                $initials = strtoupper(substr($email, 0, 1));
            } else {
                $initials = 'U';
            }
            ?>

            <!-- User Info -->
            <div class='flex items-start gap-2 mb-2'>
                <?php if (!empty($navProfileImageUrl)): ?>
                <img src="<?php echo htmlspecialchars(asset($navProfileImageUrl)); ?>" alt="Profilbild" class="w-9 h-9 rounded-full object-cover shadow border border-white/20 shrink-0" style="aspect-ratio:1/1;">
                <?php else: ?>
                <div class='w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-400 to-cyan-500 flex items-center justify-center text-white font-bold text-xs shadow border border-white/20 shrink-0'>
                    <?php echo $initials; ?>
                </div>
                <?php endif; ?>
                <div class='flex-1 min-w-0'>
                    <?php if (!empty($firstname) || !empty($lastname)): ?>
                    <p class='text-xs font-semibold text-white truncate leading-snug mb-0.5' title='<?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>'>
                        <?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>
                    </p>
                    <?php endif; ?>
                    <p class='text-[11px] text-white/70 truncate leading-snug' title='<?php echo htmlspecialchars($email); ?>'>
                        <?php echo htmlspecialchars($email); ?>
                    </p>
                    <?php foreach ($displayRoles as $sidebarRole): ?>
                    <span class='bg-teal-700 text-white font-semibold px-2 py-1 rounded-md text-xs inline-block mt-1' title='<?php echo htmlspecialchars($sidebarRole); ?>' aria-label='Rolle: <?php echo htmlspecialchars($sidebarRole); ?>'>
                        <?php echo htmlspecialchars($sidebarRole); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Profile Navigation -->
            <a href='<?php echo asset('pages/auth/profile.php'); ?>' 
               class='sidebar-footer-btn <?php echo isActivePath('/auth/profile.php') ? 'active-btn' : ''; ?>'>
                <i class='fas fa-user'></i>
                <span>Mein Profil</span>
            </a>
            <a href='<?php echo asset('pages/auth/settings.php'); ?>' 
               class='sidebar-footer-btn <?php echo isActivePath('/auth/settings.php') ? 'active-btn' : ''; ?>'>
                <i class='fas fa-cog'></i>
                <span>Einstellungen</span>
            </a>

            <!-- Dark/Light Mode Toggle -->
            <button id="theme-toggle" class='sidebar-footer-btn' aria-label="Zwischen hellem und dunklem Modus wechseln">
                <i id="theme-icon" class='fas fa-moon'></i>
                <span id="theme-text">Darkmode</span>
            </button>

            <!-- Logout -->
            <a href='<?php echo asset('pages/auth/logout.php'); ?>' 
               class='sidebar-footer-btn sidebar-logout-btn'>
                <i class='fas fa-sign-out-alt'></i>
                <span>Abmelden</span>
            </a>

            
            <!-- Live Clock -->
            <div class='mt-2 pt-2 border-t border-white/20 text-center'>
                <div id="live-clock" class='text-xs text-white/80 font-mono'>
                    <!-- JavaScript will update this -->
                </div>
            </div>
        </div>
    </aside>


    <!-- Main Content -->
    <main id="main-content" role="main" class="md:ml-64 lg:ml-72 min-h-screen p-4 pt-20 md:p-6 lg:p-10" style="padding-bottom: max(1rem, env(safe-area-inset-bottom, 0))">
        <?php if (isset($_SESSION['show_2fa_nudge']) && $_SESSION['show_2fa_nudge']): ?>
        <!-- 2FA Nudge Modal -->
        <div id="tfa-nudge-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden transform transition-all">
                <!-- Modal Header -->
                <div class="bg-gradient-to-r from-blue-600 to-green-600 px-6 py-4">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-shield-alt text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white">Sicherheitshinweis</h3>
                    </div>
                </div>
                
                <!-- Modal Body -->
                <div class="px-6 py-6 overflow-y-auto flex-1">
                    <p class="text-slate-800 dark:text-slate-200 text-lg mb-2 font-semibold">
                        Erhöhe deine Sicherheit!
                    </p>
                    <p class="text-slate-800 dark:text-slate-200 mb-6">
                        Aktiviere jetzt die 2-Faktor-Authentifizierung für zusätzlichen Schutz deines Kontos.
                    </p>
                    
                    <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                            <p class="text-sm text-slate-800 dark:text-slate-200">
                                Die 2-Faktor-Authentifizierung macht dein Konto deutlich sicherer, indem bei der Anmeldung ein zusätzlicher Code erforderlich ist.
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Footer -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700 flex flex-col sm:flex-row gap-3">
                    <a href="<?php echo asset('pages/auth/profile.php'); ?>" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-blue-600 to-green-600 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-green-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-shield-alt mr-2"></i>
                        Jetzt einrichten
                    </a>
                    <button onclick="dismissTfaNudge()" class="flex-1 px-6 py-3 bg-gray-300 dark:bg-slate-600 text-slate-800 dark:text-slate-200 rounded-lg font-semibold hover:bg-gray-400 dark:hover:bg-slate-500 transition-all duration-300">
                        Später
                    </button>
                </div>
            </div>
        </div>

        <script>
        // Dismiss modal
        function dismissTfaNudge() {
            document.getElementById('tfa-nudge-modal').style.display = 'none';
        }
        </script>
        <?php 
            unset($_SESSION['show_2fa_nudge']);
        endif; 
        ?>
        
        <?php echo $content ?? ''; ?>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mobile-menu-btn');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const menuIconTop = document.getElementById('menu-icon-top');
            const menuIconMiddle = document.getElementById('menu-icon-middle');
            const menuIconBottom = document.getElementById('menu-icon-bottom');

            // Body scroll lock helpers (iOS Safari fix)
            var _savedScrollY = 0;
            function lockBodyScroll() {
                _savedScrollY = window.scrollY;
                document.body.style.top = '-' + _savedScrollY + 'px';
                document.body.classList.add('sidebar-open');
            }
            function unlockBodyScroll() {
                document.body.classList.remove('sidebar-open');
                document.body.style.top = '';
                window.scrollTo(0, _savedScrollY);
            }

            // Reusable open/close helpers
            function openSidebar() {
                if (!sidebar) return;
                sidebar.classList.add('open');
                if (overlay) overlay.classList.add('active');
                lockBodyScroll();
                btn?.setAttribute('aria-expanded', 'true');
                btn?.setAttribute('aria-label', 'Menü schließen');
                menuIconTop?.setAttribute('d', 'M6 18L18 6');
                menuIconMiddle?.setAttribute('d', 'M12 12h0');
                menuIconMiddle?.setAttribute('opacity', '0');
                menuIconBottom?.setAttribute('d', 'M6 6L18 18');
            }
            function closeSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('active');
                unlockBodyScroll();
                btn?.setAttribute('aria-expanded', 'false');
                btn?.setAttribute('aria-label', 'Menü öffnen');
                menuIconTop?.setAttribute('d', 'M4 6h16');
                menuIconMiddle?.setAttribute('d', 'M4 12h16');
                menuIconMiddle?.setAttribute('opacity', '1');
                menuIconBottom?.setAttribute('d', 'M4 18h16');
            }

            if (btn && sidebar) {
                btn.addEventListener('click', function() {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
            }

            if (overlay && sidebar) {
                overlay.addEventListener('click', closeSidebar);
            }

            // Close sidebar when clicking on main content (mobile)
            const mainContent = document.getElementById('main-content');
            if (mainContent && sidebar) {
                mainContent.addEventListener('click', function() {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                    }
                });
            }

            // Escape key closes sidebar (accessibility)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar?.classList.contains('open')) {
                    closeSidebar();
                    btn?.focus();
                }
            });

            // Touch swipe detection for sidebar (mobile only)
            var _touchStartX = 0;
            var _touchStartY = 0;
            var SWIPE_THRESHOLD = 50; // min px for horizontal swipe
            var EDGE_ZONE = 30;       // px from left edge to trigger "open" swipe

            document.addEventListener('touchstart', function(e) {
                _touchStartX = e.changedTouches[0].clientX;
                _touchStartY = e.changedTouches[0].clientY;
            }, { passive: true });

            document.addEventListener('touchend', function(e) {
                if (window.innerWidth >= 768) return; // desktop only
                var touchEndX = e.changedTouches[0].clientX;
                var touchEndY = e.changedTouches[0].clientY;
                var deltaX = touchEndX - _touchStartX;
                var deltaY = Math.abs(touchEndY - _touchStartY);
                // Require swipe to be more horizontal than vertical
                if (Math.abs(deltaX) < deltaY * 1.5) return;
                if (deltaX > SWIPE_THRESHOLD && _touchStartX < EDGE_ZONE) {
                    // Right swipe from left edge – open sidebar
                    openSidebar();
                } else if (deltaX < -SWIPE_THRESHOLD && sidebar?.classList.contains('open')) {
                    // Left swipe – close sidebar
                    closeSidebar();
                }
            }, { passive: true });
        });
        
        // Submenu toggle functionality
        document.querySelectorAll('.menu-item-with-submenu > a').forEach(menuItem => {
            menuItem.addEventListener('click', (e) => {
                const parent = menuItem.closest('.menu-item-with-submenu');
                const isCurrentlyExpanded = parent.classList.contains('expanded');
                
                // If the submenu is collapsed, prevent navigation and expand it
                if (!isCurrentlyExpanded) {
                    e.preventDefault();
                    
                    // Close all other submenus
                    document.querySelectorAll('.menu-item-with-submenu').forEach(item => {
                        if (item !== parent) {
                            item.classList.remove('expanded');
                        }
                    });
                    
                    // Expand current submenu
                    parent.classList.add('expanded');
                }
                // If already expanded, allow navigation to proceed normally
            });
        });
        
        // Auto-expand submenu if current page is a child item
        // Note: This executes immediately (IIFE) since the script runs after DOM is loaded,
        // and DOMContentLoaded would never fire at this point
        (function() {
            const activeSubmenuItem = document.querySelector('.submenu a.bg-white\\/20, .submenu a[class*="border-ibc-green"]');
            if (activeSubmenuItem) {
                const parent = activeSubmenuItem.closest('.menu-item-with-submenu');
                if (parent) {
                    parent.classList.add('expanded');
                }
            }
        })();
        
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');
        const themeText = document.getElementById('theme-text');
        const mobileThemeToggle = document.getElementById('mobile-theme-toggle');
        const mobileThemeIcon = document.getElementById('mobile-theme-icon');
        
        // Get user's saved theme preference from database (via data attribute)
        const userThemePreference = document.body.getAttribute('data-user-theme') || 'auto';
        
        // Load theme preference (localStorage overrides database preference)
        let currentTheme = localStorage.getItem('theme') || userThemePreference;
        
        // Apply theme based on preference
        function applyTheme(theme) {
            const isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                if (themeIcon) { themeIcon.classList.remove('fa-moon'); themeIcon.classList.add('fa-sun'); }
                if (themeText) themeText.textContent = 'Lightmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-moon'); mobileThemeIcon.classList.add('fa-sun'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            } else {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'light';
                if (themeIcon) { themeIcon.classList.remove('fa-sun'); themeIcon.classList.add('fa-moon'); }
                if (themeText) themeText.textContent = 'Darkmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-sun'); mobileThemeIcon.classList.add('fa-moon'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
            }
        }
        
        // Apply initial theme
        applyTheme(currentTheme);
        
        // Toggle theme on button click
        function toggleTheme() {
            const isDarkMode = document.body.classList.contains('dark-mode');
            if (isDarkMode) {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'light';
                localStorage.setItem('theme', 'light');
                if (themeIcon) { themeIcon.classList.remove('fa-sun'); themeIcon.classList.add('fa-moon'); }
                if (themeText) themeText.textContent = 'Darkmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-sun'); mobileThemeIcon.classList.add('fa-moon'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
            } else {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                localStorage.setItem('theme', 'dark');
                if (themeIcon) { themeIcon.classList.remove('fa-moon'); themeIcon.classList.add('fa-sun'); }
                if (themeText) themeText.textContent = 'Lightmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-moon'); mobileThemeIcon.classList.add('fa-sun'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            }
        }

        themeToggle?.addEventListener('click', toggleTheme);

        // Mobile theme toggle (synced with sidebar toggle)
        mobileThemeToggle?.addEventListener('click', toggleTheme);
        
        // Live Clock - Updates every second
        function updateLiveClock() {
            const now = new Date();
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const dateTimeString = `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
            const clockElement = document.getElementById('live-clock');
            if (clockElement) {
                clockElement.textContent = dateTimeString;
            }
        }
        
        // Update immediately and then every second
        updateLiveClock();
        setInterval(updateLiveClock, 1000);

        // Lazy image fade-in
        document.querySelectorAll('img[loading="lazy"]').forEach(img => {
            if (img.complete) {
                img.classList.add('loaded');
            } else {
                img.addEventListener('load', () => img.classList.add('loaded'));
            }
        });

        // Table overflow indicator for responsive tables
        document.querySelectorAll('.table-scroll-wrapper').forEach(wrapper => {
            const checkOverflow = () => {
                if (wrapper.scrollWidth > wrapper.clientWidth) {
                    wrapper.classList.add('has-overflow');
                } else {
                    wrapper.classList.remove('has-overflow');
                }
            };
            checkOverflow();
            window.addEventListener('resize', checkOverflow);
        });

        // iOS viewport height fix
        function setAppHeight() {
            document.documentElement.style.setProperty('--app-height', `${window.innerHeight}px`);
        }
        setAppHeight();
        window.addEventListener('resize', setAppHeight);
        window.addEventListener('orientationchange', () => {
            setTimeout(setAppHeight, 200);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>

</body>
</html>
<!-- ✅ Sidebar visibility: Invoices restricted to board_finance only via canManageInvoices() -->
