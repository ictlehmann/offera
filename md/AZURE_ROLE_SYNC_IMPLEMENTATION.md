# Azure Role Synchronization Implementation

## Übersicht

Diese Implementierung synchronisiert Rollenänderungen, die im Intranet vorgenommen werden, direkt mit Microsoft Entra ID (Azure AD). Dadurch werden lokale Rollenänderungen nicht mehr beim nächsten Login überschrieben.

## Implementierte Änderungen

### 1. Datenbank-Schema Erweiterung

**Datei:** `sql/add_azure_oid_column.sql`

- Neue Spalte `azure_oid` in der `users` Tabelle
- Speichert die Azure Object ID (OID) für jeden Benutzer
- Ermöglicht direkte Synchronisierung mit Azure ohne E-Mail-Lookups
- Indiziert für schnelle Lookups

**Migration ausführen:**
```sql
mysql -u [username] -p [database] < sql/add_azure_oid_column.sql
```

### 2. AuthHandler Aktualisierung

**Datei:** `includes/handlers/AuthHandler.php`

**Änderungen:**
- Methode `handleMicrosoftCallback()` erweitert
- Extrahiert Azure Object ID (OID) aus den OAuth-Claims
- Speichert OID sowohl bei Neuanlage als auch beim Update existierender Benutzer
- OID wird bei jedem Login aktualisiert

**Code-Snippet:**
```php
// Get Azure Object ID from claims for role synchronization
$azureOid = $claims['oid'] ?? null;

// Update existing user with Azure OID
$stmt = $db->prepare("UPDATE users SET role = ?, azure_roles = ?, azure_oid = ?, profile_complete = 1, last_login = NOW() WHERE id = ?");
```

### 3. MicrosoftGraphService Erweiterung

**Datei:** `includes/services/MicrosoftGraphService.php`

Drei neue Methoden hinzugefügt:

#### 3.1 `getCurrentAppRoleAssignmentId($userId)`
- **Zweck:** Ruft die Assignment-ID (nicht Role-ID!) der aktuellen Rollenzuweisung ab
- **API-Call:** `GET https://graph.microsoft.com/v1.0/users/{userId}/appRoleAssignments`
- **Logik:** Durchläuft alle Zuweisungen und prüft, ob eine `appRoleId` im `ROLE_MAPPING` existiert
- **Rückgabe:** Assignment-ID oder `null` wenn keine Zuweisung gefunden

#### 3.2 `removeRole($userId, $assignmentId)`
- **Zweck:** Entfernt eine Rollenzuweisung aus Azure
- **API-Call:** `DELETE https://graph.microsoft.com/v1.0/users/{userId}/appRoleAssignments/{assignmentId}`
- **Rückgabe:** `true` bei Erfolg (HTTP 204)
- **Fehlerbehandlung:** Wirft Exception bei Fehler

#### 3.3 `updateUserRole($userId, $newRoleValue)`
- **Zweck:** Kompletter Workflow für Rollenwechsel
- **Ablauf:**
  1. Ruft `getCurrentAppRoleAssignmentId()` auf
  2. Falls Assignment existiert: Ruft `removeRole()` auf
  3. Ruft `assignRole()` auf (bereits vorhanden)
- **Parameter:** `$newRoleValue` muss Azure-Rollenname sein (z.B. 'mitglied', 'vorstand_finanzen')
- **Fehlerbehandlung:** Bei Fehler wird Exception geworfen (User kann ohne Rolle zurückbleiben - intentional für Konsistenz)

### 4. Role Succession Logic Anpassung

**Datei:** `pages/auth/ajax_role_succession.php`

**Änderungen:**
- Import von `MicrosoftGraphService` hinzugefügt
- Neue Hilfsfunktion `internalRoleToAzureRole()` für Mapping
- Azure-Synchronisierung VOR Datenbank-Update durchgeführt
- Fehlerbehandlung für fehlende OIDs

#### 4.1 Helper-Funktion: `internalRoleToAzureRole()`
```php
function internalRoleToAzureRole($internalRole) {
    $reverseMapping = [
        'candidate' => 'anwaerter',
        'member' => 'mitglied',
        'head' => 'ressortleiter',
        'board_finance' => 'vorstand_finanzen',
        'board_internal' => 'vorstand_intern',
        'board_external' => 'vorstand_extern',
        'alumni' => 'alumni',
        'alumni_board' => 'alumni_vorstand',
        'alumni_auditor' => 'alumni_finanz',
        'honorary_member' => 'ehrenmitglied'
    ];
    
    return $reverseMapping[$internalRole] ?? $internalRole;
}
```

**WICHTIG:** Diese Mapping muss synchron mit `AuthHandler::handleMicrosoftCallback()` gehalten werden!

#### 4.2 Demotion-Logik (mit Nachfolger)

```php
// 1. Hole Azure OIDs für beide Benutzer
$currentUserOid = ...;
$successorOid = ...;

// 2. Validierung: Beide müssen OID haben
if (!$currentUserOid || !$successorOid) {
    // Fehler: Benutzer müssen sich erneut anmelden
}

// 3. Azure-Synchronisierung zuerst
$graphService = new MicrosoftGraphService();
$graphService->updateUserRole($currentUserOid, $azureNewRole);
$graphService->updateUserRole($successorOid, $azureCurrentRole);

// 4. Nur bei Erfolg: Datenbank-Update
$db->beginTransaction();
// ... UPDATE statements
$db->commit();
```

#### 4.3 Normale Rollenänderung (ohne Nachfolger)

```php
// 1. Hole Azure OID
$userOid = ...;

// 2. Sync mit Azure (falls OID vorhanden)
if ($userOid) {
    $graphService->updateUserRole($userOid, $azureNewRole);
}

// 3. Datenbank-Update
User::update($currentUserId, ['role' => $newRole, ...]);
```

## Rollenmapping

### Azure → Internal (bei Login)
Definiert in `AuthHandler::handleMicrosoftCallback()`:

| Azure Rolle         | Interne Rolle    |
|---------------------|------------------|
| anwaerter           | candidate        |
| mitglied            | member           |
| ressortleiter       | head             |
| vorstand_finanzen   | board_finance    |
| vorstand_intern     | board_internal   |
| vorstand_extern     | board_external   |
| alumni              | alumni           |
| alumni_vorstand     | alumni_board     |
| alumni_finanz       | alumni_auditor   |
| ehrenmitglied       | honorary_member  |

### Internal → Azure (bei Rollenänderung)
Definiert in `ajax_role_succession.php::internalRoleToAzureRole()`:
- Umkehrung des obigen Mappings
- **MUSS synchron gehalten werden!**

## Ablaufdiagramm

```
┌─────────────────────────────────────────────────┐
│ Benutzer ändert Rolle im Intranet              │
└─────────────────┬───────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────┐
│ ajax_role_succession.php                        │
│ 1. Validierung (Berechtigungen, etc.)         │
│ 2. Hole Azure OIDs aus Datenbank              │
└─────────────────┬───────────────────────────────┘
                  │
                  ▼
         ┌────────┴────────┐
         │ OIDs vorhanden? │
         └────────┬────────┘
                  │ Ja
                  ▼
┌─────────────────────────────────────────────────┐
│ MicrosoftGraphService::updateUserRole()         │
│ 1. getCurrentAppRoleAssignmentId()             │
│ 2. removeRole() (falls vorhanden)              │
│ 3. assignRole()                                │
└─────────────────┬───────────────────────────────┘
                  │
         ┌────────┴────────┐
         │ Azure-Sync OK?  │
         └────────┬────────┘
                  │ Ja
                  ▼
┌─────────────────────────────────────────────────┐
│ Datenbank-Update                                │
│ - UPDATE users SET role = ...                  │
│ - commit()                                      │
└─────────────────┬───────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────┐
│ Erfolg: Rolle in Azure UND Datenbank geändert  │
└─────────────────────────────────────────────────┘
```

## Fehlerbehandlung

### Fehlende Azure OID
- **Ursache:** Benutzer hat sich noch nicht mit Microsoft angemeldet
- **Lösung:** Fehlermeldung: "Azure-Synchronisierung nicht möglich: Fehlende Azure-Daten. Bitte melden Sie sich erneut an."
- **Action:** Benutzer muss sich erneut mit Microsoft anmelden, um OID zu erhalten

### Azure API-Fehler
- **Ursache:** Netzwerkfehler, Azure-Berechtigungen, ungültige Role-ID, etc.
- **Verhalten:** Exception wird geworfen, Datenbank wird NICHT aktualisiert
- **Logging:** Vollständige Fehlermeldung wird in Error-Log geschrieben
- **User-Nachricht:** "Fehler bei der Azure-Synchronisierung: [Fehlermeldung]"

### Datenbank-Fehler
- **Ursache:** Datenbank-Connection, Constraint-Verletzung, etc.
- **Verhalten:** Transaction wird zurückgerollt
- **Hinweis:** Rolle ist bereits in Azure geändert!
- **Manueller Fix:** Administrator muss Rolle in Datenbank oder Azure manuell korrigieren

## Berechtigungen

Die Azure-Anwendung benötigt folgende Berechtigungen:
- `AppRoleAssignment.ReadWrite.All` - Zum Lesen und Ändern von Rollenzuweisungen
- `User.Read.All` - Zum Lesen von Benutzerdaten
- Diese sind bereits in der `MicrosoftGraphService` Dokumentation dokumentiert

## Testing

### Manuelle Tests

1. **Test: Demotion mit Nachfolger**
   - Als Board-Mitglied einloggen (mit Microsoft)
   - Rolle zu 'member' ändern und Nachfolger wählen
   - Erwartung: Beide Rollen in Azure UND Datenbank aktualisiert
   - Prüfung: Nächster Login überschreibt Rolle NICHT

2. **Test: Board-zu-Board Wechsel**
   - Als Board-Mitglied einloggen
   - Rolle zu anderer Board-Rolle ändern (ohne Nachfolger)
   - Erwartung: Rolle in Azure UND Datenbank aktualisiert
   - Prüfung: Nächster Login überschreibt Rolle NICHT

3. **Test: Fehlende OID**
   - Als Benutzer ohne Azure OID (z.B. lokaler Test-User)
   - Rolle ändern versuchen
   - Erwartung: Fehlermeldung mit Hinweis auf erneuten Login

4. **Test: Azure API-Fehler**
   - Azure-Credentials temporär ungültig machen
   - Rolle ändern versuchen
   - Erwartung: Fehlermeldung, Datenbank bleibt unverändert

### Azure Portal Verification
1. Azure Portal → Entra ID → Enterprise Applications
2. Deine App auswählen → Users and groups
3. Nach Rollenänderung: Prüfen ob neue Rolle zugewiesen ist

## Maintenance & Troubleshooting

### Problem: Rolle wird trotzdem beim Login überschrieben
**Diagnose:**
- Prüfe ob `azure_oid` in DB gespeichert ist: `SELECT id, email, role, azure_oid FROM users WHERE email = '...'`
- Prüfe Error-Logs für Azure-Sync-Fehler

**Lösungen:**
- Falls `azure_oid` NULL: Benutzer muss sich erneut mit Microsoft anmelden
- Falls Sync-Fehler: Azure-Berechtigungen prüfen

### Problem: "Failed to get current app role assignment"
**Ursache:** Benutzer hat keine Rollenzuweisung in Azure
**Lösung:** Normal - `getCurrentAppRoleAssignmentId()` gibt dann `null` zurück

### Problem: User bleibt ohne Rolle nach Fehler in `removeRole()`
**Ursache:** Intentional - verhindert inkonsistente Zustände
**Lösung:** 
1. Rolle manuell in Azure neu zuweisen, ODER
2. `updateUserRole()` erneut aufrufen (versucht neue Rolle zuzuweisen)

### Rollenmapping aktualisieren
**Wenn neue Rollen hinzugefügt werden:**
1. `MicrosoftGraphService::ROLE_MAPPING` aktualisieren (Azure Role ID hinzufügen)
2. `AuthHandler::handleMicrosoftCallback()` → `$roleMapping` aktualisieren
3. `ajax_role_succession.php` → `internalRoleToAzureRole()` aktualisieren
4. **WICHTIG:** Alle drei müssen synchron sein!

## Sicherheitshinweise

- Azure Object IDs sind nicht-sensitive Identifier (können geloggt werden)
- Exception-Meldungen enthalten keine sensitiven Daten
- Azure-Sync erfolgt mit Service-Principal (Client Credentials Flow)
- Benutzer können nur ihre eigene Rolle ändern (Validierung in `ajax_role_succession.php`)

## Migration für existierende Benutzer

Existierende Benutzer haben noch keine `azure_oid`:
1. SQL-Migration ausführen (fügt Spalte hinzu, initial NULL)
2. Beim nächsten Microsoft-Login wird OID automatisch gespeichert
3. Erst danach können diese Benutzer Rollenänderungen vornehmen, die mit Azure synchronisiert werden

**Optional:** Batch-Job zum Vorausfüllen der OIDs:
```php
// Hole OID via E-Mail aus Azure
GET https://graph.microsoft.com/v1.0/users/{email}
// Speichere in DB
UPDATE users SET azure_oid = ? WHERE email = ?
```

## Fazit

Die Implementierung stellt sicher, dass:
- ✅ Rollenänderungen im Intranet sofort in Azure übernommen werden
- ✅ Keine Überschreibung beim nächsten Login
- ✅ Atomare Operationen (Azure zuerst, dann DB)
- ✅ Klare Fehlerbehandlung und Logging
- ✅ Rückwärtskompatibilität (Benutzer ohne OID können sich anmelden und erhalten OID)
