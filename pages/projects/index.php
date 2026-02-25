<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication - any logged-in user can view projects
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'member';

// Get filter parameter from URL
$typeFilter = $_GET['type'] ?? 'all';
$validTypes = ['all', 'internal', 'external'];
if (!in_array($typeFilter, $validTypes)) {
    $typeFilter = 'all';
}

// Get all projects based on filter
$db = Database::getContentDB();

// Check if user is admin - they can see archived projects
$isAdmin = Auth::isBoard() || Auth::hasPermission('manage_projects');

if ($typeFilter === 'all') {
    if ($isAdmin) {
        // Admins see all non-draft projects (including archived)
        $stmt = $db->query("
            SELECT * FROM projects 
            WHERE status != 'draft'
            ORDER BY created_at DESC
        ");
    } else {
        // Regular users only see active projects: open, running, applying, completed
        $stmt = $db->query("
            SELECT * FROM projects 
            WHERE status IN ('open', 'running', 'applying', 'completed')
            ORDER BY created_at DESC
        ");
    }
} else {
    // For specific type filters (internal/external)
    if ($isAdmin) {
        // Admins see all non-draft projects of the specified type
        $stmt = $db->prepare("
            SELECT * FROM projects 
            WHERE status != 'draft' AND type = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$typeFilter]);
    } else {
        // Regular users only see active projects of the specified type
        $stmt = $db->prepare("
            SELECT * FROM projects 
            WHERE status IN ('open', 'running', 'applying', 'completed') AND type = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$typeFilter]);
    }
}

$projects = $stmt->fetchAll();

// Filter sensitive data for each project based on user role
$filteredProjects = array_map(function($project) use ($userRole, $user) {
    return Project::filterSensitiveData($project, $userRole, $user['id']);
}, $projects);

$title = 'Projekte - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Error/Success Messages -->
    <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success']); ?>
    </div>
    <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-4xl font-bold text-gray-800 mb-2">
                <i class="fas fa-briefcase mr-3 text-purple-600"></i>
                Projekte
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Entdecke aktuelle Projekte und bewirb Dich</p>
        </div>
        
        <!-- Neues Projekt Button - Board/Head/Manager only -->
        <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['head', 'alumni_board'])): ?>
        <a href="manage.php?new=1" class="btn-primary">
            <i class="fas fa-plus mr-2"></i>Neues Projekt
        </a>
        <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <div class="mb-6 card p-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium text-gray-700">Filter:</span>
            <a href="index.php?type=all" 
               class="px-4 py-2 rounded-lg font-semibold transition <?php echo $typeFilter === 'all' ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                <i class="fas fa-list mr-2"></i>Alle
            </a>
            <a href="index.php?type=internal" 
               class="px-4 py-2 rounded-lg font-semibold transition <?php echo $typeFilter === 'internal' ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-700 hover:bg-blue-200'; ?>">
                <i class="fas fa-building mr-2"></i>Intern
            </a>
            <a href="index.php?type=external" 
               class="px-4 py-2 rounded-lg font-semibold transition <?php echo $typeFilter === 'external' ? 'bg-green-600 text-white' : 'bg-green-100 text-green-700 hover:bg-green-200'; ?>">
                <i class="fas fa-users mr-2"></i>Extern
            </a>
        </div>
    </div>

    <!-- Projects Grid -->
    <?php if (empty($filteredProjects)): ?>
        <div class="card p-8 text-center">
            <i class="fas fa-briefcase text-6xl text-gray-300 mb-4"></i>
            <p class="text-xl text-gray-600">Keine Projekte gefunden</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($filteredProjects as $project): ?>
                <?php
                    $isArchived = $project['status'] === 'archived';
                    $canApply = ($project['status'] === 'open' || $project['status'] === 'applying') && $userRole !== 'alumni';
                ?>
                
                <div class="card p-6 relative hover:shadow-lg transition <?php echo $isArchived ? 'opacity-60 grayscale' : ''; ?>">
                    <?php if ($isArchived): ?>
                        <div class="absolute top-4 right-4">
                            <span class="px-3 py-1 bg-gray-200 text-gray-600 text-xs font-semibold rounded-full">
                                <i class="fas fa-archive mr-1"></i>
                                Archiviert
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Image -->
                    <?php if (!empty($project['image_path'])): ?>
                        <a href="view.php?id=<?php echo $project['id']; ?>" class="block mb-4 rounded-lg overflow-hidden">
                            <img src="/<?php echo htmlspecialchars($project['image_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                                 class="w-full h-48 object-cover hover:scale-105 transition-transform duration-300">
                        </a>
                    <?php endif; ?>
                    
                    <!-- Status and Priority Badges with Animation -->
                    <div class="flex items-start justify-between mb-4 gap-2">
                        <span class="status-badge px-3 py-1.5 text-xs font-bold rounded-full shadow-sm
                            <?php 
                            switch($project['status']) {
                                case 'open': echo 'bg-gradient-to-r from-blue-500 to-blue-600 text-white'; break;
                                case 'applying': echo 'bg-gradient-to-r from-yellow-500 to-amber-600 text-white'; break;
                                case 'assigned': echo 'bg-gradient-to-r from-green-500 to-emerald-600 text-white'; break;
                                case 'running': echo 'bg-gradient-to-r from-purple-500 to-purple-600 text-white'; break;
                                case 'completed': echo 'bg-gradient-to-r from-teal-500 to-cyan-600 text-white'; break;
                                case 'archived': echo 'bg-gray-200 text-gray-600'; break;
                                default: echo 'bg-gray-100 text-gray-800'; break;
                            }
                            ?>">
                            <i class="fas fa-circle text-[8px] mr-1 animate-pulse"></i>
                            <?php 
                            switch($project['status']) {
                                case 'open': echo 'Offen'; break;
                                case 'applying': echo 'Bewerbungsphase'; break;
                                case 'assigned': echo 'Vergeben'; break;
                                case 'running': echo 'Laufend'; break;
                                case 'completed': echo 'Abgeschlossen'; break;
                                case 'archived': echo 'Archiviert'; break;
                                default: echo ucfirst($project['status']); break;
                            }
                            ?>
                        </span>
                        <span class="priority-badge px-3 py-1.5 text-xs font-bold rounded-full shadow-sm
                            <?php 
                            switch($project['priority']) {
                                case 'low': echo 'bg-gradient-to-r from-blue-400 to-blue-500 text-white'; break;
                                case 'medium': echo 'bg-gradient-to-r from-yellow-400 to-orange-500 text-white'; break;
                                case 'high': echo 'bg-gradient-to-r from-red-500 to-rose-600 text-white'; break;
                                default: echo 'bg-gray-100 text-gray-800'; break;
                            }
                            ?>">
                            <?php 
                            switch($project['priority']) {
                                case 'low': echo '<i class="fas fa-arrow-down"></i> Niedrig'; break;
                                case 'medium': echo '<i class="fas fa-minus"></i> Mittel'; break;
                                case 'high': echo '<i class="fas fa-arrow-up"></i> Hoch'; break;
                                default: echo ucfirst($project['priority']); break;
                            }
                            ?>
                        </span>
                    </div>

                    <!-- Project Type Badge -->
                    <div class="mb-4">
                        <span class="type-badge px-3 py-1.5 text-xs font-bold rounded-full shadow-sm
                            <?php 
                            $projectType = $project['type'] ?? 'internal';
                            echo $projectType === 'internal' ? 'bg-gradient-to-r from-indigo-500 to-blue-600 text-white' : 'bg-gradient-to-r from-green-500 to-emerald-600 text-white';
                            ?>">
                            <i class="fas fa-tag mr-1"></i>
                            <?php echo $projectType === 'internal' ? 'Intern' : 'Extern'; ?>
                        </span>
                    </div>

                    <!-- Title with Hover Effect -->
                    <a href="view.php?id=<?php echo $project['id']; ?>" class="block mb-3 group">
                        <h3 class="text-xl font-bold text-gray-800 group-hover:text-purple-600 transition-colors duration-300">
                            <?php echo htmlspecialchars($project['title']); ?>
                        </h3>
                    </a>

                    <!-- Description -->
                    <?php if (!empty($project['description'])): ?>
                        <p class="text-sm text-gray-600 mb-4 line-clamp-3">
                            <?php echo htmlspecialchars(substr($project['description'], 0, 150)) . (strlen($project['description']) > 150 ? '...' : ''); ?>
                        </p>
                    <?php endif; ?>

                    <!-- Project Info with Enhanced Icons -->
                    <div class="space-y-2.5 mb-5 text-sm text-gray-600">
                        <?php if (!empty($project['client_name'])): ?>
                            <div class="flex items-center group">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-100 to-purple-200 flex items-center justify-center mr-3">
                                    <i class="fas fa-user-tie text-purple-600 text-sm"></i>
                                </div>
                                <span class="group-hover:text-purple-600 transition-colors"><?php echo htmlspecialchars($project['client_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($project['start_date'])): ?>
                            <div class="flex items-center group">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center mr-3">
                                    <i class="fas fa-calendar-alt text-blue-600 text-sm"></i>
                                </div>
                                <span class="group-hover:text-blue-600 transition-colors">Start: <?php echo date('d.m.Y', strtotime($project['start_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($project['end_date'])): ?>
                            <div class="flex items-center group">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-green-100 to-green-200 flex items-center justify-center mr-3">
                                    <i class="fas fa-calendar-check text-green-600 text-sm"></i>
                                </div>
                                <span class="group-hover:text-green-600 transition-colors">Ende: <?php echo date('d.m.Y', strtotime($project['end_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons with Modern Design -->
                    <div class="flex flex-col space-y-2.5">
                        <a href="view.php?id=<?php echo $project['id']; ?>" 
                           class="block w-full text-center px-4 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-lg font-bold hover:from-purple-700 hover:to-indigo-700 transform hover:-translate-y-0.5 transition-all duration-200 shadow-md hover:shadow-xl">
                            <i class="fas fa-eye mr-2"></i>
                            Details ansehen
                        </a>
                        
                        <?php if ($canApply): ?>
                            <a href="view.php?id=<?php echo $project['id']; ?>&action=apply" 
                               class="block w-full text-center px-4 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-lg font-bold hover:from-green-700 hover:to-emerald-700 transform hover:-translate-y-0.5 transition-all duration-200 shadow-md hover:shadow-xl">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Jetzt bewerben
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Line clamp utility */
    .line-clamp-3 {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    /* Grayscale filter for archived projects */
    .grayscale {
        filter: grayscale(80%);
    }
    
    /* Enhanced project card styles */
    .project-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
        border: 1px solid rgba(0, 0, 0, 0.05);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .project-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12), 0 8px 16px rgba(0, 0, 0, 0.08);
        border-color: rgba(139, 92, 246, 0.2);
    }
    
    .project-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #8b5cf6, #3b82f6, #10b981);
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .project-card:hover::before {
        opacity: 1;
    }
    
    /* Image wrapper with overlay */
    .project-image-wrapper {
        position: relative;
        overflow: hidden;
        border-radius: 0.5rem;
    }
    
    .project-image-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(180deg, transparent 0%, rgba(0, 0, 0, 0.3) 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .project-image-wrapper:hover .project-image-overlay {
        opacity: 1;
    }
    
    /* Status badge animations */
    .status-badge, .priority-badge, .type-badge {
        position: relative;
        display: inline-flex;
        align-items: center;
        transition: all 0.3s ease;
    }
    
    .status-badge:hover, .priority-badge:hover, .type-badge:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    /* Grid stagger animation */
    .project-card {
        animation: fadeInUp 0.6s ease-out backwards;
    }
    
    .project-card:nth-child(1) { animation-delay: 0.05s; }
    .project-card:nth-child(2) { animation-delay: 0.1s; }
    .project-card:nth-child(3) { animation-delay: 0.15s; }
    .project-card:nth-child(4) { animation-delay: 0.2s; }
    .project-card:nth-child(5) { animation-delay: 0.25s; }
    .project-card:nth-child(6) { animation-delay: 0.3s; }
    .project-card:nth-child(7) { animation-delay: 0.35s; }
    .project-card:nth-child(8) { animation-delay: 0.4s; }
    .project-card:nth-child(9) { animation-delay: 0.45s; }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Respect user motion preferences */
    @media (prefers-reduced-motion: reduce) {
        .project-card {
            animation: none;
        }
        
        .status-badge .animate-pulse,
        .priority-badge .animate-pulse,
        .type-badge .animate-pulse,
        .status-badge .fas,
        .priority-badge .fas,
        .type-badge .fas {
            animation: none !important;
        }
        
        .project-image-wrapper img {
            transition: none;
        }
        
        .project-card,
        .status-badge,
        .priority-badge,
        .type-badge,
        .info-card {
            transform: none !important;
        }
    }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
