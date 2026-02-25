<?php
/**
 * Shop-Statistiken (Admin)
 * Monatliche Verkäufe und Gesamtumsatz als grafische Auswertung mit Chart.js.
 * Access: board_finance, board_internal, board_external, head
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Shop.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

if (!Auth::hasRole(Shop::MANAGER_ROLES)) {
    header('Location: ../dashboard/index.php');
    exit;
}

// Fetch last 12 months of sales data
$monthlyStats = Shop::getMonthlySalesStats(12);

// Build arrays for Chart.js
$labels   = [];
$counts   = [];
$revenues = [];

foreach ($monthlyStats as $row) {
    // Format month label: "2024-03" → "Mär 24"
    $dt       = DateTime::createFromFormat('Y-m', $row['month']);
    $labels[] = $dt ? $dt->format('M y') : $row['month'];
    $counts[] = (int)   $row['count'];
    $revenues[] = (float) $row['revenue'];
}

// Summary KPIs
$totalOrders  = array_sum($counts);
$totalRevenue = array_sum($revenues);
$avgRevenue   = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

$title = 'Shop-Statistiken – IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">

    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                    <i class="fas fa-chart-bar mr-3 text-blue-600 dark:text-blue-400"></i>
                    Shop-Statistiken
                </h1>
                <p class="text-gray-600 dark:text-gray-300">Monatliche Verkaufszahlen und Umsatz der letzten 12 Monate</p>
            </div>
            <a href="<?php echo asset('pages/admin/shop_manage.php'); ?>"
               class="inline-flex items-center px-5 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all font-medium no-underline">
                <i class="fas fa-arrow-left mr-2"></i>Shop-Verwaltung
            </a>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
        <!-- Total orders -->
        <div class="card p-6 rounded-xl shadow-lg border-l-4 border-blue-500 bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-blue-900/20">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Bestellungen</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($totalOrders); ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Letzte 12 Monate (bezahlt)</p>
                </div>
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center">
                    <i class="fas fa-shopping-bag text-blue-600 dark:text-blue-400 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total revenue -->
        <div class="card p-6 rounded-xl shadow-lg border-l-4 border-green-500 bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-green-900/20">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Gesamtumsatz</p>
                    <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($totalRevenue, 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Letzte 12 Monate</p>
                </div>
                <div class="w-14 h-14 bg-green-100 dark:bg-green-900/50 rounded-full flex items-center justify-center">
                    <i class="fas fa-euro-sign text-green-600 dark:text-green-400 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Average order value -->
        <div class="card p-6 rounded-xl shadow-lg border-l-4 border-purple-500 bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-purple-900/20">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Ø Bestellwert</p>
                    <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($avgRevenue, 2, ',', '.'); ?> €</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pro Bestellung</p>
                </div>
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center">
                    <i class="fas fa-chart-line text-purple-600 dark:text-purple-400 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($monthlyStats)): ?>
    <div class="card rounded-xl shadow-lg p-10 text-center text-gray-500 dark:text-gray-400">
        <i class="fas fa-chart-bar text-5xl mb-4 opacity-30"></i>
        <p class="text-xl">Noch keine Verkaufsdaten vorhanden.</p>
        <p class="text-sm mt-2">Sobald Bestellungen als „Bezahlt" markiert werden, erscheinen hier die Statistiken.</p>
    </div>
    <?php else: ?>

    <!-- Charts -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mb-8">

        <!-- Monthly order count chart -->
        <div class="card rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-shopping-bag mr-2 text-blue-500"></i>Monatliche Verkäufe (Anzahl)
            </h2>
            <div class="relative" style="height:300px">
                <canvas id="salesCountChart"></canvas>
            </div>
        </div>

        <!-- Monthly revenue chart -->
        <div class="card rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-euro-sign mr-2 text-green-500"></i>Monatlicher Umsatz (€)
            </h2>
            <div class="relative" style="height:300px">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Data table -->
    <div class="card rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-table mr-2 text-gray-500"></i>Detailübersicht
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-left">
                        <th class="pb-3 font-semibold">Monat</th>
                        <th class="pb-3 font-semibold text-right">Bestellungen</th>
                        <th class="pb-3 font-semibold text-right">Umsatz</th>
                        <th class="pb-3 font-semibold text-right">Ø Bestellwert</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach (array_reverse($monthlyStats) as $row): ?>
                    <?php
                        $dt     = DateTime::createFromFormat('Y-m', $row['month']);
                        $label  = $dt ? $dt->format('F Y') : $row['month'];
                        $avg    = $row['count'] > 0 ? $row['revenue'] / $row['count'] : 0;
                    ?>
                    <tr>
                        <td class="py-3 font-medium text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($label); ?></td>
                        <td class="py-3 text-right text-gray-700 dark:text-gray-300"><?php echo number_format((int) $row['count']); ?></td>
                        <td class="py-3 text-right text-gray-700 dark:text-gray-300"><?php echo number_format((float) $row['revenue'], 2, ',', '.'); ?> €</td>
                        <td class="py-3 text-right text-gray-500 dark:text-gray-400"><?php echo number_format($avg, 2, ',', '.'); ?> €</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if (!empty($monthlyStats)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const isDark = document.documentElement.classList.contains('dark-mode');
    const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.07)';
    const textColor = isDark ? '#e5e7eb' : '#374151';

    const labels   = <?php echo json_encode($labels); ?>;
    const counts   = <?php echo json_encode($counts); ?>;
    const revenues = <?php echo json_encode($revenues); ?>;

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: textColor } },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: { ticks: { color: textColor }, grid: { color: gridColor } },
            y: { ticks: { color: textColor }, grid: { color: gridColor }, beginAtZero: true }
        }
    };

    // Sales count chart
    new Chart(document.getElementById('salesCountChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Bestellungen',
                data: counts,
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                borderRadius: 4,
            }]
        },
        options: {
            ...commonOptions,
            plugins: { ...commonOptions.plugins, legend: { display: false } },
            scales: {
                x: { ...commonOptions.scales.x },
                y: { ...commonOptions.scales.y, ticks: { ...commonOptions.scales.y.ticks, stepSize: 1 } }
            }
        }
    });

    // Revenue chart
    new Chart(document.getElementById('revenueChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Umsatz (€)',
                data: revenues,
                backgroundColor: 'rgba(34, 197, 94, 0.15)',
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 2,
                pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                pointRadius: 5,
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            ...commonOptions,
            plugins: { ...commonOptions.plugins, legend: { display: false } },
            scales: {
                x: { ...commonOptions.scales.x },
                y: {
                    ...commonOptions.scales.y,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: function(value) { return value.toLocaleString('de-DE') + ' €'; }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
