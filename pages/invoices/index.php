<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Invoice.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow 'board', 'alumni_board', 'head', 'alumni' (read-only)
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to access invoices page
$hasInvoiceAccess = Auth::canAccessPage('invoices');
if (!$hasInvoiceAccess) {
    header('Location: ../dashboard/index.php');
    exit;
}

$userRole = $user['role'] ?? '';

// Check if user has permission to mark invoices as paid
// Only board_finance members can mark invoices as paid
$canMarkAsPaid = Auth::canManageInvoices();

// Get invoices based on role
$invoices = Invoice::getAll($userRole, $user['id']);

// Get statistics (only for board roles and alumni_board/alumni_auditor)
$stats = null;
$canViewStats = Auth::isBoard() || Auth::hasRole(['alumni_board', 'alumni_auditor']);
if ($canViewStats) {
    $stats = Invoice::getStats();
}

// Get user database for fetching submitter info
$userDb = Database::getUserDB();

// Pre-fetch all user info once (avoids N+1 in template)
$userInfoMap = [];
if (!empty($invoices)) {
    $allUids = array_unique(array_merge(
        array_column($invoices, 'user_id'),
        array_filter(array_column($invoices, 'paid_by_user_id'))
    ));
    if (!empty($allUids)) {
        $ph = str_repeat('?,', count($allUids) - 1) . '?';
        $uStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
        $uStmt->execute($allUids);
        foreach ($uStmt->fetchAll() as $u) {
            $userInfoMap[$u['id']] = $u['email'];
        }
    }
}

// Collect open invoices for the dedicated banner
$openInvoices = array_values(array_filter($invoices, fn($inv) => in_array($inv['status'], ['pending', 'approved'])));

// Compute summary stats from the fetched invoices (visible to all users)
$summaryOpenAmount = 0.0;
$summaryInReviewCount = 0;
$summaryPaidAmount = 0.0;
$summaryPaidCount = 0;
foreach ($invoices as $inv) {
    if (in_array($inv['status'], ['pending', 'approved'])) $summaryOpenAmount += (float)$inv['amount'];
    if ($inv['status'] === 'pending') $summaryInReviewCount++;
    if ($inv['status'] === 'paid') { $summaryPaidAmount += (float)$inv['amount']; $summaryPaidCount++; }
}

$title = 'Rechnungsmanagement - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); ?>
    </div>
    <?php 
        unset($_SESSION['success_message']); 
    endif; 
    ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error_message']); ?>
    </div>
    <?php 
        unset($_SESSION['error_message']); 
    endif; 
    ?>

    <!-- Header -->
    <div class="mb-8 rounded-2xl overflow-hidden shadow-lg">
        <div class="bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 dark:from-blue-800 dark:via-blue-900 dark:to-indigo-900 px-6 py-7 sm:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold text-white mb-1">
                        <i class="fas fa-file-invoice-dollar mr-3 opacity-90"></i>
                        Rechnungsmanagement
                    </h1>
                    <p class="text-blue-100 dark:text-blue-200 mt-1">Verwalte Belege und Erstattungen</p>
                </div>
                <!-- New Submission Button -->
                <button
                    id="openSubmissionModal"
                    class="inline-flex items-center justify-center px-6 py-3 bg-white text-blue-700 rounded-xl font-semibold hover:bg-blue-50 transition-all shadow-md hover:shadow-lg flex-shrink-0"
                >
                    <i class="fas fa-plus mr-2"></i>
                    Neue Einreichung
                </button>
            </div>
        </div>
    </div>

    <!-- Dashboard Summary Cards (visible to all users with invoices) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 <?php echo $stats ? 'lg:grid-cols-4' : ''; ?> gap-4 mb-8">
        <!-- Gesamtbetrag Offen -->
        <div class="card p-5 bg-gradient-to-br from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-l-4 border-red-500 dark:border-red-600 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">Gesamtbetrag Offen</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo number_format($summaryOpenAmount, 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1"><i class="fas fa-folder-open mr-1"></i>Ausstehend</p>
                </div>
                <div class="w-12 h-12 bg-red-500 dark:bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-md">
                    <i class="fas fa-folder-open text-white text-xl"></i>
                </div>
            </div>
        </div>

        <!-- In Prüfung -->
        <div class="card p-5 bg-gradient-to-br from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 border-l-4 border-yellow-500 dark:border-yellow-600 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">In Prüfung</p>
                    <p class="text-3xl font-bold text-gray-800 dark:text-gray-100"><?php echo $summaryInReviewCount; ?></p>
                    <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1"><i class="fas fa-clock mr-1"></i>Warten auf Genehmigung</p>
                </div>
                <div class="w-12 h-12 bg-yellow-500 dark:bg-yellow-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-md">
                    <i class="fas fa-clock text-white text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Bezahlt -->
        <div class="card p-5 bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border-l-4 border-green-500 dark:border-green-600 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">Bezahlt</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo number_format($summaryPaidAmount, 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-green-600 dark:text-green-400 mt-1"><i class="fas fa-check-circle mr-1"></i><?php echo $summaryPaidCount; ?> Rechnung<?php echo $summaryPaidCount !== 1 ? 'en' : ''; ?></p>
                </div>
                <div class="w-12 h-12 bg-green-500 dark:bg-green-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-md">
                    <i class="fas fa-check-circle text-white text-xl"></i>
                </div>
            </div>
        </div>

        <?php if ($stats): ?>
        <!-- Offene Beträge (Board/Alumni Board only) -->
        <div class="card p-5 bg-gradient-to-br from-orange-50 to-red-50 dark:from-orange-900/20 dark:to-red-900/20 border-l-4 border-orange-500 dark:border-orange-600 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">Offene Beträge</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo number_format($stats['total_pending'], 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-orange-600 dark:text-orange-400 mt-1"><i class="fas fa-hourglass-half mr-1"></i>Gesamt ausstehend</p>
                </div>
                <div class="w-12 h-12 bg-orange-500 dark:bg-orange-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-md">
                    <i class="fas fa-hourglass-half text-white text-xl"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Offene Rechnungen Banner -->
    <?php if (!empty($openInvoices)): ?>
    <div class="mb-6 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-700/50 rounded-2xl overflow-hidden shadow-sm">
        <div class="px-5 py-4 bg-amber-100/70 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-700/50 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 bg-amber-500 dark:bg-amber-600 rounded-full flex items-center justify-center flex-shrink-0 shadow-sm">
                    <i class="fas fa-exclamation text-white text-sm"></i>
                </span>
                <h2 class="text-base font-bold text-amber-800 dark:text-amber-200">
                    <?php echo count($openInvoices); ?> offene Rechnung<?php echo count($openInvoices) !== 1 ? 'en' : ''; ?> warten auf Bearbeitung
                </h2>
            </div>
            <button onclick="document.getElementById('invoices-table').scrollIntoView({behavior:'smooth'}); filterByStatus('all');" class="text-sm font-semibold text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100 transition-colors">
                Alle anzeigen <i class="fas fa-arrow-right ml-1"></i>
            </button>
        </div>
        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php
            $openStatusLabelMap = ['pending' => 'In Prüfung', 'approved' => 'Genehmigt'];
            $openBadgeClassMap  = [
                'pending'  => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-300 border-yellow-300 dark:border-yellow-600',
                'approved' => 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-300 border-blue-300 dark:border-blue-600',
            ];
            $openDescMaxLen = 45;
            foreach (array_slice($openInvoices, 0, 6) as $openInv):
                $oEmail    = $userInfoMap[$openInv['user_id']] ?? 'Unknown';
                $oName     = explode('@', $oEmail)[0];
                $oInitials = strtoupper(substr($oName, 0, 2));
                $oLabel    = $openStatusLabelMap[$openInv['status']] ?? ucfirst($openInv['status']);
                $oBadge    = $openBadgeClassMap[$openInv['status']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600';
                $oDesc     = mb_strlen($openInv['description']) > $openDescMaxLen
                    ? mb_substr($openInv['description'], 0, $openDescMaxLen) . '…'
                    : $openInv['description'];
            ?>
            <div class="flex items-center gap-3 p-3 bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow cursor-pointer"
                 onclick="filterByStatus('<?php echo htmlspecialchars($openInv['status']); ?>')">
                <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold text-sm flex-shrink-0">
                    <?php echo htmlspecialchars($oInitials); ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate"><?php echo htmlspecialchars($oName); ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($oDesc); ?></p>
                </div>
                <div class="text-right flex-shrink-0">
                    <p class="text-sm font-bold text-gray-800 dark:text-gray-100"><?php echo number_format($openInv['amount'], 2, ',', '.'); ?> €</p>
                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full border <?php echo $oBadge; ?>"><?php echo $oLabel; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($openInvoices) > 6): ?>
            <div class="flex items-center justify-center p-3 bg-gray-50 dark:bg-gray-800/50 rounded-xl border border-dashed border-gray-200 dark:border-gray-700">
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">+ <?php echo count($openInvoices) - 6; ?> weitere</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Tabs + Export -->
    <?php
    $statusCounts = ['all' => count($invoices), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'paid' => 0];
    foreach ($invoices as $inv) {
        if (isset($statusCounts[$inv['status']])) $statusCounts[$inv['status']]++;
    }
    ?>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <!-- Status filter tabs -->
        <div class="flex flex-wrap gap-2">
            <button onclick="filterByStatus('all')" id="tab-all"
                class="filter-tab px-4 py-2 rounded-lg text-sm font-semibold transition-all border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                Alle <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200"><?php echo $statusCounts['all']; ?></span>
            </button>
            <button onclick="filterByStatus('pending')" id="tab-pending"
                class="filter-tab px-4 py-2 rounded-lg text-sm font-semibold transition-all border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                In Prüfung <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300"><?php echo $statusCounts['pending']; ?></span>
            </button>
            <button onclick="filterByStatus('approved')" id="tab-approved"
                class="filter-tab px-4 py-2 rounded-lg text-sm font-semibold transition-all border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                Genehmigt <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300"><?php echo $statusCounts['approved']; ?></span>
            </button>
            <button onclick="filterByStatus('rejected')" id="tab-rejected"
                class="filter-tab px-4 py-2 rounded-lg text-sm font-semibold transition-all border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                Abgelehnt <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300"><?php echo $statusCounts['rejected']; ?></span>
            </button>
            <button onclick="filterByStatus('paid')" id="tab-paid"
                class="filter-tab px-4 py-2 rounded-lg text-sm font-semibold transition-all border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                Bezahlt <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300"><?php echo $statusCounts['paid']; ?></span>
            </button>
        </div>
        <?php if (Auth::isBoard() || Auth::hasRole(['alumni_board', 'alumni_auditor'])): ?>
        <a 
            href="<?php echo asset('api/export_invoices.php'); ?>"
            class="inline-flex items-center px-5 py-2 bg-blue-600 dark:bg-blue-700 text-white rounded-lg font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-md hover:shadow-lg no-underline flex-shrink-0"
        >
            <i class="fas fa-download mr-2"></i>
            Exportieren
        </a>
        <?php endif; ?>
    </div>

    <!-- Invoices Table -->
    <div id="invoices-table">
        <?php if (empty($invoices)): ?>
            <div class="p-16 text-center">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-5">
                    <i class="fas fa-file-invoice text-4xl text-gray-400 dark:text-gray-500"></i>
                </div>
                <p class="text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Rechnungen vorhanden</p>
                <p class="text-gray-500 dark:text-gray-400 mb-5">Erstelle Deine erste Einreichung</p>
                <button onclick="document.getElementById('openSubmissionModal').click()"
                    class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition-all shadow-md">
                    <i class="fas fa-plus mr-2"></i>Neue Einreichung
                </button>
            </div>
        <?php else: ?>
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-card overflow-hidden">
                <?php
                // Status configuration (dots + colors + labels)
                $statusColors = [
                    'pending'  => 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-300 border-yellow-300 dark:border-yellow-700',
                    'approved' => 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-300 border-blue-300 dark:border-blue-700',
                    'rejected' => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-300 border-red-300 dark:border-red-700',
                    'paid'     => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-300 border-green-300 dark:border-green-700',
                ];
                $statusIcons = [
                    'pending'  => '<span class="w-2 h-2 rounded-full bg-yellow-500 inline-block mr-1.5 flex-shrink-0"></span>',
                    'approved' => '<span class="w-2 h-2 rounded-full bg-blue-500 inline-block mr-1.5 flex-shrink-0"></span>',
                    'rejected' => '<span class="w-2 h-2 rounded-full bg-red-500 inline-block mr-1.5 flex-shrink-0"></span>',
                    'paid'     => '<span class="w-2 h-2 rounded-full bg-green-500 inline-block mr-1.5 flex-shrink-0"></span>',
                ];
                $statusLabels = [
                    'pending'  => 'In Prüfung',
                    'approved' => 'Genehmigt',
                    'rejected' => 'Abgelehnt',
                    'paid'     => 'Bezahlt',
                ];
                ?>

                <!-- Mobile Card View (visible on small screens only) -->
                <div class="md:hidden flex flex-col gap-4">
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                        $submitterName  = explode('@', $submitterEmail)[0];
                        $initials       = strtoupper(substr($submitterName, 0, 2));
                        $statusClass    = $statusColors[$invoice['status']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 border-gray-300 dark:border-gray-600';
                        $statusIcon     = $statusIcons[$invoice['status']] ?? '';
                        $statusLabel    = $statusLabels[$invoice['status']] ?? ucfirst($invoice['status']);

                        $paidAt      = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                        $paidByName  = '';
                        if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                            $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                        }
                        $filePath        = !empty($invoice['file_path']) ? htmlspecialchars(asset($invoice['file_path']), ENT_QUOTES) : '';
                        $rejectionReason = !empty($invoice['rejection_reason']) ? htmlspecialchars($invoice['rejection_reason'], ENT_QUOTES) : '';
                        ?>
                        <div class="p-5 bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer invoice-row relative overflow-hidden" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                             onclick="openInvoiceDetail({
                                 id: '<?php echo $invoice['id']; ?>',
                                 date: '<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                                 submitter: '<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                                 initials: '<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                                 description: <?php echo json_encode($invoice['description']); ?>,
                                 amount: '<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                                 status: '<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                                 statusLabel: '<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                                 filePath: '<?php echo $filePath; ?>',
                                 paidAt: '<?php echo $paidAt; ?>',
                                 paidBy: '<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                                 rejectionReason: <?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                             })">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-semibold text-sm flex-shrink-0">
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?php echo htmlspecialchars($submitterName); ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?></p>
                                    </div>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full border <?php echo $statusClass; ?>">
                                    <?php echo $statusIcon . htmlspecialchars($statusLabel); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3 line-clamp-2"><?php echo htmlspecialchars($invoice['description']); ?></p>
                            <div class="flex items-center justify-between">
                                <span class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                                    <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                                </span>
                                <?php if (!empty($invoice['file_path'])): ?>
                                    <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                        <a href="<?php echo asset($invoice['file_path']); ?>"
                                           target="_blank"
                                           title="Ansehen"
                                           class="inline-flex items-center px-2 py-1 bg-blue-600 text-white rounded-xl text-xs font-medium hover:bg-blue-700 transition-colors no-underline shadow-sm hover:shadow">
                                            <i class="fas fa-eye mr-1"></i>Ansehen
                                        </a>
                                        <a href="<?php echo asset($invoice['file_path']); ?>"
                                           download
                                           title="Herunterladen"
                                           class="inline-flex items-center px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors no-underline">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (Auth::isBoard() && $invoice['status'] === 'pending'): ?>
                            <div class="flex gap-2 mt-3" onclick="event.stopPropagation()">
                                <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')"
                                    class="flex-1 px-3 py-1.5 bg-green-600 text-white rounded-xl text-xs font-medium hover:bg-green-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-check mr-1"></i>Genehmigen
                                </button>
                                <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')"
                                    class="flex-1 px-3 py-1.5 bg-red-600 text-white rounded-xl text-xs font-medium hover:bg-red-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-times mr-1"></i>Ablehnen
                                </button>
                            </div>
                            <?php elseif (Auth::isBoard() && $invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                            <div class="mt-3" onclick="event.stopPropagation()">
                                <button onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)"
                                    class="w-full px-3 py-1.5 bg-blue-600 text-white rounded-xl text-xs font-medium hover:bg-blue-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-check-double mr-1"></i>Als Bezahlt markieren
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Desktop Table View (hidden on small screens) -->
                <table class="hidden md:table min-w-full">
                    <thead class="bg-gray-50/80 dark:bg-gray-800/80 backdrop-blur-sm border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Datum</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Einreicher</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Zweck</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Betrag</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Beleg</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <?php if (Auth::isBoard()): ?>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aktionen</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900">
                        <?php foreach ($invoices as $invoice): ?>
                            <?php
                            $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                            $submitterName  = explode('@', $submitterEmail)[0];
                            $initials       = strtoupper(substr($submitterName, 0, 2));
                            $statusClass    = $statusColors[$invoice['status']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 border-gray-300 dark:border-gray-600';
                            $statusIcon     = $statusIcons[$invoice['status']] ?? '';
                            $statusLabel    = $statusLabels[$invoice['status']] ?? ucfirst($invoice['status']);

                            $paidAt     = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                            $paidByName = '';
                            if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                                $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                            }
                            $filePath = !empty($invoice['file_path']) ? htmlspecialchars(asset($invoice['file_path']), ENT_QUOTES) : '';
                            ?>
                            <tr class="hover:bg-blue-50/50 dark:hover:bg-blue-900/20 transition-colors duration-200 cursor-pointer border-b border-gray-50 dark:border-gray-800 last:border-0 invoice-row" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                                onclick="openInvoiceDetail({
                                    id: '<?php echo $invoice['id']; ?>',
                                    date: '<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                                    submitter: '<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                                    initials: '<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                                    description: <?php echo json_encode($invoice['description']); ?>,
                                    amount: '<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                                    status: '<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                                    statusLabel: '<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                                    filePath: '<?php echo $filePath; ?>',
                                    paidAt: '<?php echo $paidAt; ?>',
                                    paidBy: '<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                                    rejectionReason: <?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                                })">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-semibold mr-3">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            <?php echo htmlspecialchars($submitterName); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100 max-w-xs truncate">
                                    <?php echo htmlspecialchars($invoice['description']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-base font-extrabold text-gray-900 dark:text-white">
                                    <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm" onclick="event.stopPropagation()">
                                    <?php if (!empty($invoice['file_path'])): ?>
                                        <div class="flex items-center gap-2">
                                            <a href="<?php echo asset($invoice['file_path']); ?>"
                                               target="_blank"
                                               title="Ansehen"
                                               class="inline-flex items-center px-2.5 py-1.5 bg-blue-600 dark:bg-blue-700 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm hover:shadow hover:-translate-y-0.5 transform no-underline">
                                                <i class="fas fa-eye mr-1"></i>Ansehen
                                            </a>
                                            <a href="<?php echo asset($invoice['file_path']); ?>"
                                               download
                                               title="Herunterladen"
                                               class="inline-flex items-center px-2.5 py-1.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-xs font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all shadow-sm hover:shadow hover:-translate-y-0.5 transform no-underline">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500">Kein Beleg</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?php echo $statusClass; ?>">
                                        <?php echo $statusIcon . htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <?php if (Auth::isBoard()): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm" onclick="event.stopPropagation()">
                                    <?php if ($invoice['status'] === 'pending'): ?>
                                        <div class="flex gap-2">
                                            <button
                                                onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')"
                                                class="inline-flex items-center px-3 py-1.5 bg-green-600 dark:bg-green-700 text-white rounded-xl text-xs font-semibold hover:bg-green-700 dark:hover:bg-green-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                                <i class="fas fa-check mr-1"></i>Genehmigen
                                            </button>
                                            <button
                                                onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')"
                                                class="inline-flex items-center px-3 py-1.5 bg-red-600 dark:bg-red-700 text-white rounded-xl text-xs font-semibold hover:bg-red-700 dark:hover:bg-red-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                                <i class="fas fa-times mr-1"></i>Ablehnen
                                            </button>
                                        </div>
                                    <?php elseif ($invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                                        <button
                                            onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)"
                                            class="inline-flex items-center px-3 py-1.5 bg-blue-600 dark:bg-blue-700 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                            <i class="fas fa-check-circle mr-1"></i>Als Bezahlt markieren
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Invoice Detail Modal -->
<div id="invoiceDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- Header -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-file-invoice mr-2 text-blue-600 dark:text-blue-400"></i>
                Rechnungsdetails <span id="detail-id" class="text-gray-400 dark:text-gray-500 text-base font-normal"></span>
            </h2>
            <button id="closeDetailModal" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <!-- Body -->
        <div class="p-5 space-y-4">
            <!-- Submitter + Date row -->
            <div class="flex items-center gap-4">
                <div id="detail-avatar" class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold text-lg flex-shrink-0"></div>
                <div>
                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="detail-submitter"></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><i class="fas fa-calendar-alt mr-1"></i><span id="detail-date"></span></p>
                </div>
                <div class="ml-auto">
                    <span id="detail-status-badge" class="inline-flex items-center px-3 py-1 text-sm font-semibold rounded-full border"></span>
                </div>
            </div>

            <!-- Description -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Zweck</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-description"></p>
            </div>

            <!-- Amount -->
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Betrag</p>
                <p class="text-2xl font-bold text-blue-700 dark:text-blue-300"><span id="detail-amount"></span> €</p>
            </div>

            <!-- Paid Info (conditionally shown) -->
            <div id="detail-paid-row" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 hidden">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Bezahlt am</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-paid-info"></p>
            </div>

            <!-- Rejection Reason (conditionally shown) -->
            <div id="detail-rejection-row" class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4 hidden">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Ablehnungsgrund</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-rejection"></p>
            </div>

            <!-- Document -->
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Beleg</p>
                <div id="detail-document" class="hidden">
                    <div id="detail-doc-preview"></div>
                    <a id="detail-doc-link" href="#" target="_blank"
                       class="inline-flex items-center mt-2 px-3 py-1.5 bg-blue-600 dark:bg-blue-700 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm no-underline">
                        <i class="fas fa-external-link-alt mr-1"></i>In neuem Tab öffnen
                    </a>
                </div>
                <p id="detail-no-document" class="text-gray-400 dark:text-gray-500 text-sm hidden">
                    <i class="fas fa-ban mr-1"></i>Kein Beleg hochgeladen
                </p>
            </div>
        </div>
        <!-- Footer -->
        <div class="px-5 pb-5 space-y-3">
            <?php if (Auth::isBoard()): ?>
            <!-- Board action buttons (shown/hidden dynamically by JS) -->
            <div id="detail-actions-pending" class="hidden flex gap-2">
                <button onclick="updateInvoiceStatusFromDetail('approved')"
                    class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-green-600 dark:bg-green-700 text-white rounded-lg font-semibold hover:bg-green-700 dark:hover:bg-green-600 transition-all shadow-sm">
                    <i class="fas fa-check mr-2"></i>Genehmigen
                </button>
                <button onclick="openRejectModal()"
                    class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-red-600 dark:bg-red-700 text-white rounded-lg font-semibold hover:bg-red-700 dark:hover:bg-red-600 transition-all shadow-sm">
                    <i class="fas fa-times mr-2"></i>Ablehnen
                </button>
            </div>
            <?php if ($canMarkAsPaid): ?>
            <div id="detail-actions-approved" class="hidden">
                <button onclick="markInvoiceAsPaidFromDetail()"
                    class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-600 dark:bg-blue-700 text-white rounded-lg font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm">
                    <i class="fas fa-check-circle mr-2"></i>Als Bezahlt markieren
                </button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <button id="closeDetailModalBtn"
                class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                Schließen
            </button>
        </div>
    </div>
</div>

<!-- Rejection Reason Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 z-[60] hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-times-circle mr-2 text-red-500"></i>Rechnung ablehnen
            </h3>
        </div>
        <div class="p-5">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ablehnungsgrund <span class="text-gray-400 dark:text-gray-500 font-normal">(optional)</span></label>
            <textarea id="rejectReasonInput" rows="3"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none"
                placeholder="Grund für die Ablehnung (optional)..."></textarea>
        </div>
        <div class="px-5 pb-5 flex gap-3">
            <button onclick="confirmReject()"
                class="flex-1 px-4 py-2 bg-red-600 dark:bg-red-700 text-white rounded-lg font-semibold hover:bg-red-700 dark:hover:bg-red-600 transition-all">
                <i class="fas fa-times mr-2"></i>Ablehnen
            </button>
            <button onclick="document.getElementById('rejectModal').classList.add('hidden')"
                class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                Abbrechen
            </button>
        </div>
    </div>
</div>

<!-- Submission Modal -->
<div id="submissionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
                    <i class="fas fa-file-invoice mr-2 text-blue-600 dark:text-blue-400"></i>
                    Neue Rechnung einreichen
                </h2>
                <button id="closeSubmissionModal" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
        </div>
        
        <form id="submissionForm" action="<?php echo asset('api/submit_invoice.php'); ?>" method="POST" enctype="multipart/form-data" class="p-6">
            <!-- Betrag -->
            <div class="mb-6">
                <label for="amount" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Betrag (€) <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="number" 
                    id="amount" 
                    name="amount" 
                    step="0.01"
                    min="0"
                    required
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="0.00"
                >
            </div>

            <!-- Belegdatum -->
            <div class="mb-6">
                <label for="date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Belegdatum <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="date" 
                    id="date" 
                    name="date" 
                    required
                    max="<?php echo date('Y-m-d'); ?>"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
            </div>

            <!-- Zweck/Beschreibung -->
            <div class="mb-6">
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Zweck <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="3"
                    required
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="Beschreiben Sie den Zweck der Rechnung..."
                ></textarea>
            </div>

            <!-- File Upload (Drag & Drop Zone) -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beleg hochladen <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <div 
                    id="dropZone"
                    class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center hover:border-blue-500 dark:hover:border-blue-400 transition-colors cursor-pointer bg-gray-50 dark:bg-gray-700"
                >
                    <input 
                        type="file" 
                        id="file" 
                        name="file" 
                        accept=".pdf,.jpg,.jpeg,.png"
                        required
                        class="hidden"
                    >
                    <div id="dropZoneContent">
                        <i class="fas fa-cloud-upload-alt text-5xl text-gray-400 dark:text-gray-500 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-300 mb-2">
                            <span class="text-blue-600 dark:text-blue-400 font-semibold">Klicken Sie hier</span> oder ziehen Sie eine Datei hierher
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Unterstützt: PDF, JPG, PNG (Max. 10MB)
                        </p>
                    </div>
                    <div id="fileInfo" class="hidden">
                        <i class="fas fa-file-check text-5xl text-green-500 dark:text-green-400 mb-4"></i>
                        <p id="fileName" class="text-gray-700 dark:text-gray-300 font-semibold mb-2"></p>
                        <button 
                            type="button"
                            id="removeFile"
                            class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300"
                        >
                            <i class="fas fa-times mr-1"></i>
                            Datei entfernen
                        </button>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex gap-4">
                <button 
                    type="submit"
                    class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg"
                >
                    <i class="fas fa-paper-plane mr-2"></i>
                    Einreichen
                </button>
                <button 
                    type="button"
                    id="cancelSubmission"
                    class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all"
                >
                    Abbrechen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Invoice Detail Modal ────────────────────────────────────────────────────
const detailModal = document.getElementById('invoiceDetailModal');
let currentDetailInvoiceId = null;

function openInvoiceDetail(data) {
    currentDetailInvoiceId = data.id;

    // Populate header
    document.getElementById('detail-id').textContent = '#' + data.id;
    document.getElementById('detail-avatar').textContent  = data.initials;
    document.getElementById('detail-submitter').textContent = data.submitter;
    document.getElementById('detail-date').textContent = data.date;
    document.getElementById('detail-description').textContent = data.description;
    document.getElementById('detail-amount').textContent = data.amount;

    // Status badge
    const badge = document.getElementById('detail-status-badge');
    const statusClasses = {
        pending:  'bg-yellow-100 text-yellow-800 border-yellow-300',
        approved: 'bg-blue-100 text-blue-800 border-blue-300',
        rejected: 'bg-red-100 text-red-800 border-red-300',
        paid:     'bg-green-100 text-green-800 border-green-300',
    };
    const statusIcons = {
        pending:  '<span class="w-2 h-2 rounded-full bg-yellow-500 inline-block mr-1.5 flex-shrink-0"></span>',
        approved: '<span class="w-2 h-2 rounded-full bg-blue-500 inline-block mr-1.5 flex-shrink-0"></span>',
        rejected: '<span class="w-2 h-2 rounded-full bg-red-500 inline-block mr-1.5 flex-shrink-0"></span>',
        paid:     '<span class="w-2 h-2 rounded-full bg-green-500 inline-block mr-1.5 flex-shrink-0"></span>',
    };
    badge.className = 'inline-flex items-center px-3 py-1 text-sm font-semibold rounded-full border ' +
        (statusClasses[data.status] || 'bg-gray-100 text-gray-800 border-gray-300');
    badge.innerHTML = (statusIcons[data.status] || '') + data.statusLabel;

    // Paid info
    const paidRow = document.getElementById('detail-paid-row');
    const paidInfo = document.getElementById('detail-paid-info');
    if (data.paidAt) {
        paidInfo.textContent = data.paidAt + (data.paidBy ? ' · von ' + data.paidBy : '');
        paidRow.classList.remove('hidden');
    } else {
        paidRow.classList.add('hidden');
    }

    // Rejection reason
    const rejRow = document.getElementById('detail-rejection-row');
    if (data.rejectionReason) {
        document.getElementById('detail-rejection').textContent = data.rejectionReason;
        rejRow.classList.remove('hidden');
    } else {
        rejRow.classList.add('hidden');
    }

    // Document
    const docContainer  = document.getElementById('detail-document');
    const docPreview    = document.getElementById('detail-doc-preview');
    const docLink       = document.getElementById('detail-doc-link');
    const noDoc         = document.getElementById('detail-no-document');
    docPreview.innerHTML = '';
    if (data.filePath) {
        const ext = data.filePath.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'heic'].includes(ext)) {
            const img = document.createElement('img');
            img.src = data.filePath;
            img.alt = 'Beleg';
            img.className = 'max-w-full rounded-lg border border-gray-200 dark:border-gray-700';
            docPreview.appendChild(img);
        } else if (ext === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = data.filePath;
            iframe.className = 'w-full rounded-lg border border-gray-200 dark:border-gray-700';
            iframe.style.height = '320px';
            iframe.setAttribute('frameborder', '0');
            docPreview.appendChild(iframe);
        }
        docLink.href = data.filePath;
        docContainer.classList.remove('hidden');
        noDoc.classList.add('hidden');
    } else {
        docContainer.classList.add('hidden');
        noDoc.classList.remove('hidden');
    }

    // Board action buttons
    const pendingActions  = document.getElementById('detail-actions-pending');
    const approvedActions = document.getElementById('detail-actions-approved');
    if (pendingActions)  pendingActions.classList.toggle('hidden',  data.status !== 'pending');
    if (approvedActions) approvedActions.classList.toggle('hidden', data.status !== 'approved');

    detailModal.classList.remove('hidden');
}

document.getElementById('closeDetailModal').addEventListener('click', () => {
    detailModal.classList.add('hidden');
});
document.getElementById('closeDetailModalBtn').addEventListener('click', () => {
    detailModal.classList.add('hidden');
});
detailModal.addEventListener('click', (e) => {
    if (e.target === detailModal) detailModal.classList.add('hidden');
});

// Actions from detail modal
function updateInvoiceStatusFromDetail(status) {
    if (status === 'rejected') { openRejectModal(); return; }
    updateInvoiceStatus(currentDetailInvoiceId, status);
}

function markInvoiceAsPaidFromDetail() {
    markInvoiceAsPaid(currentDetailInvoiceId);
}

// ── Rejection reason modal ──────────────────────────────────────────────────
function openRejectModal() {
    document.getElementById('rejectReasonInput').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}

function confirmReject() {
    const reason = document.getElementById('rejectReasonInput').value.trim();
    document.getElementById('rejectModal').classList.add('hidden');
    const invoiceId = currentDetailInvoiceId !== null ? currentDetailInvoiceId : pendingRejectInvoiceId;
    _doUpdateStatus(invoiceId, 'rejected', reason);
}

// ── Submission Modal ────────────────────────────────────────────────────────
const modal = document.getElementById('submissionModal');
const openBtn = document.getElementById('openSubmissionModal');
const closeBtn = document.getElementById('closeSubmissionModal');
const cancelBtn = document.getElementById('cancelSubmission');

openBtn.addEventListener('click', () => {
    modal.classList.remove('hidden');
});

closeBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
});

cancelBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
});

// Close modal when clicking outside
modal.addEventListener('click', (e) => {
    if (e.target === modal) {
        modal.classList.add('hidden');
    }
});

// File upload handling
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('file');
const dropZoneContent = document.getElementById('dropZoneContent');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const removeFileBtn = document.getElementById('removeFile');

dropZone.addEventListener('click', () => {
    fileInput.click();
});

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
    
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        updateFileInfo();
    }
});

fileInput.addEventListener('change', updateFileInfo);

removeFileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.value = '';
    dropZoneContent.classList.remove('hidden');
    fileInfo.classList.add('hidden');
});

function updateFileInfo() {
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        fileName.textContent = file.name;
        dropZoneContent.classList.add('hidden');
        fileInfo.classList.remove('hidden');
    }
}

// ── Status filter tabs ──────────────────────────────────────────────────────
function filterByStatus(status) {
    document.querySelectorAll('.filter-tab').forEach(btn => {
        btn.classList.remove('active', 'bg-blue-600', 'text-white', 'border-blue-600', 'dark:bg-blue-700', 'dark:border-blue-700');
        btn.classList.add('bg-white', 'dark:bg-gray-800', 'text-gray-700', 'dark:text-gray-300', 'border-gray-300', 'dark:border-gray-600', 'hover:bg-gray-50', 'dark:hover:bg-gray-700');
    });
    const activeTab = document.getElementById('tab-' + status);
    if (activeTab) {
        activeTab.classList.add('active', 'bg-blue-600', 'text-white', 'border-blue-600', 'dark:bg-blue-700', 'dark:border-blue-700');
        activeTab.classList.remove('bg-white', 'dark:bg-gray-800', 'text-gray-700', 'dark:text-gray-300', 'border-gray-300', 'dark:border-gray-600', 'hover:bg-gray-50', 'dark:hover:bg-gray-700');
    }
    document.querySelectorAll('.invoice-row').forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Initialise tab styling on page load
filterByStatus('all');

// ── Update invoice status ───────────────────────────────────────────────────
let pendingRejectInvoiceId = null;

function updateInvoiceStatus(invoiceId, status) {
    if (status === 'rejected') {
        pendingRejectInvoiceId = invoiceId;
        openRejectModal();
        return;
    }
    _doUpdateStatus(invoiceId, status, null);
}

function _doUpdateStatus(invoiceId, status, reason) {
    const formData = new FormData();
    formData.append('invoice_id', invoiceId);
    formData.append('status', status);
    if (reason) formData.append('reason', reason);
    
    fetch('<?php echo asset('api/update_invoice_status.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Fehler beim Aktualisieren des Status');
    });
}

// Mark invoice as paid function
function markInvoiceAsPaid(invoiceId) {
    if (!confirm('Möchtest du diese Rechnung wirklich als bezahlt markieren?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('invoice_id', invoiceId);
    
    fetch('<?php echo asset('api/mark_invoice_paid.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Fehler beim Markieren als bezahlt');
    });
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
