<?php
require_once '../../config/config.php';
require_once '../../src/Auth.php';
require_once '../../includes/services/MicrosoftGraphService.php';

// Initialize session with secure parameters
init_session();

// Require admin access for this debugging tool
if (!Auth::check() || !Auth::isAdmin()) {
    die("Zugriff verweigert. Nur Administratoren können dieses Tool verwenden.");
}

// Prüfen ob eingeloggt (oder zumindest Token da ist)
if (!isset($_SESSION['access_token'])) {
    die("Bitte erst ganz normal einloggen, dann diese Seite aufrufen!");
}

$graphService = new MicrosoftGraphService($_SESSION['access_token']);
try {
    // Rufe Gruppen ab (genau wie im AuthHandler)
    $groups = $graphService->getMemberGroups();
} catch (Exception $e) {
    die("Fehler beim Abruf: " . htmlspecialchars($e->getMessage()));
}

echo "<h1>Microsoft Entra Diagnose</h1>";
echo "<h3>Deine Gruppen aus Azure:</h3>";
echo "<pre>" . htmlspecialchars(print_r($groups, true)) . "</pre>";

echo "<h3>Deine aktuelle Config (ROLE_MAPPING):</h3>";
echo "<pre>" . htmlspecialchars(print_r(ROLE_MAPPING, true)) . "</pre>";

echo "<h3>Vergleich:</h3>";
echo "<ul>";
foreach ($groups as $group) {
    $name = htmlspecialchars($group['displayName']);
    $id = htmlspecialchars($group['id']);
    $match = 'NEIN';
    
    foreach (ROLE_MAPPING as $roleKey => $mapping) {
        if ($roleKey === $group['displayName'] || $roleKey === $group['id']) {
            $match = "JA -> Rolle: <strong>" . htmlspecialchars($mapping) . "</strong>";
            break;
        }
    }
    echo "<li>Gruppe: <strong>$name</strong> (ID: $id) - Treffer in Config? $match</li>";
}
echo "</ul>";
?>
