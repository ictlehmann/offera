<?php
require_once __DIR__ . '/../../src/Auth.php';

if (!Auth::isBoard()) {
    header('Location: /index.php');
    exit;
}

// Get audit logs from content database
$db = Database::getContentDB();

$limit = 100;
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

$filters = [];
$params = [];
$sql = "SELECT * FROM system_logs WHERE 1=1";

if (!empty($_GET['action'])) {
    $sql .= " AND action LIKE ?";
    $params[] = '%' . $_GET['action'] . '%';
}

if (!empty($_GET['user_id'])) {
    $sql .= " AND user_id = ?";
    $params[] = $_GET['user_id'];
}

$sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get total count
$countSql = "SELECT COUNT(*) as total FROM system_logs WHERE 1=1";
$countParams = [];
if (!empty($_GET['action'])) {
    $countSql .= " AND action LIKE ?";
    $countParams[] = '%' . $_GET['action'] . '%';
}
if (!empty($_GET['user_id'])) {
    $countSql .= " AND user_id = ?";
    $countParams[] = $_GET['user_id'];
}
$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalLogs = $stmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

$title = 'Audit-Logs - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-2">
        <i class="fas fa-clipboard-list text-purple-600 mr-2"></i>
        Audit-Logs
    </h1>
    <p class="text-gray-600"><?php echo number_format($totalLogs); ?> Einträge insgesamt</p>
</div>

<!-- Filters -->
<div class="card p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Aktion</label>
            <input 
                type="text" 
                name="action" 
                placeholder="z.B. login, create, update..."
                value="<?php echo htmlspecialchars($_GET['action'] ?? ''); ?>"
                class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
            >
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Benutzer-ID</label>
            <input 
                type="number" 
                name="user_id" 
                placeholder="Benutzer-ID"
                value="<?php echo htmlspecialchars($_GET['user_id'] ?? ''); ?>"
                class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
            >
        </div>
        <div class="flex items-end space-x-2">
            <button type="submit" class="flex-1 btn-primary">
                <i class="fas fa-search mr-2"></i>Filtern
            </button>
            <a href="audit.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="card overflow-hidden">
    <?php if (empty($logs)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-clipboard text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg">Keine Logs gefunden</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zeit</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Benutzer</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktion</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entität</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($logs as $log): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        <?php echo date('d.m.Y H:i:s', strtotime($log['timestamp'])); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <?php if ($log['user_id']): ?>
                        <span class="font-medium text-gray-900">ID: <?php echo $log['user_id']; ?></span>
                        <?php else: ?>
                        <span class="text-gray-400">System</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                        $actionColors = [
                            'login' => 'green',
                            'logout' => 'gray',
                            'login_failed' => 'red',
                            'create' => 'blue',
                            'update' => 'yellow',
                            'delete' => 'red',
                            'invitation' => 'purple'
                        ];
                        
                        $color = 'gray';
                        foreach ($actionColors as $key => $value) {
                            if (stripos($log['action'], $key) !== false) {
                                $color = $value;
                                break;
                            }
                        }
                        ?>
                        <span class="px-2 py-1 text-xs bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-700 rounded-full">
                            <?php echo htmlspecialchars($log['action']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        <?php if ($log['entity_type']): ?>
                        <?php echo htmlspecialchars($log['entity_type']); ?>
                        <?php if ($log['entity_id']): ?>
                        <span class="text-gray-400">#<?php echo $log['entity_id']; ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 max-w-md truncate">
                        <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
        <div class="text-sm text-gray-600">
            Seite <?php echo $page; ?> von <?php echo $totalPages; ?>
        </div>
        <div class="flex space-x-2">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($_GET['action']) ? '&action=' . urlencode($_GET['action']) : ''; ?><?php echo !empty($_GET['user_id']) ? '&user_id=' . urlencode($_GET['user_id']) : ''; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition text-sm">
                <i class="fas fa-chevron-left"></i> Zurück
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($_GET['action']) ? '&action=' . urlencode($_GET['action']) : ''; ?><?php echo !empty($_GET['user_id']) ? '&user_id=' . urlencode($_GET['user_id']) : ''; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition text-sm">
                Weiter <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
