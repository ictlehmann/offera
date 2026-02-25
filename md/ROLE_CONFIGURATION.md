# Microsoft Entra ID Role Configuration

## Übersicht / Overview

Dieses Dokument listet alle konfigurierten Microsoft Entra ID (Azure AD) Rollen für das IBC Intranet-System auf.

This document lists all configured Microsoft Entra ID (Azure AD) roles for the IBC Intranet system.

---

## Konfigurierte Rollen / Configured Roles

Die folgenden Rollen sind im System konfiguriert und mit Microsoft Entra ID synchronisiert:

The following roles are configured in the system and synchronized with Microsoft Entra ID:

| Anzeigename (Display Name) | Beschreibung (Description) | Wert (Value) | Azure Role ID | Status |
|---------------------------|---------------------------|--------------|---------------|--------|
| Ehrenmitglied | Ehrenmitglieder | `ehrenmitglied` | `09686b92-dbc8-4e66-a851-2dafea64df89` | Aktiviert |
| Alumni-Finanzprüfer | Finanzprüfer der Alumni | `alumni_finanz` | `39597941-0a22-4922-9587-e3d62ab986d6` | Aktiviert |
| Alumni-Vorstand | Vorstand der Alumni | `alumni_vorstand` | `8a45c6aa-e791-422e-b964-986d8bdd2ed8` | Aktiviert |
| Alumni | Alumni - Ehemalige Mitglieder | `alumni` | `7ffd9c73-a828-4e34-a9f4-10f4ed00f796` | Aktiviert |
| Vorstand Extern | Vorstand Extern | `vorstand_extern` | `bf17e26b-e5f1-4a63-ae56-91ab69ae33ca` | Aktiviert |
| Vorstand Intern | Vorstand Intern | `vorstand_intern` | `f61e99e2-2717-4aff-b3f5-ef2ec489b598` | Aktiviert |
| Vorstand Finanzen und Recht | Vorstand Finanzen und Recht | `vorstand_finanzen` | `3ad43a76-75af-48a7-9974-7a2cf350f349` | Aktiviert |
| Ressortleiter | Leiter eines Ressorts | `ressortleiter` | `9456552d-0f49-42ff-bbde-495a60e61e61` | Aktiviert |
| Mitglied | Rolle für reguläre Mitglieder | `mitglied` | `70f07477-ea4e-4edc-b0e6-7e25968f16c0` | Aktiviert |
| Anwärter | Anwärter Rolle | `anwaerter` | `75edcb0a-c610-4ceb-82f2-457a9dde4fc0` | Aktiviert |

---

## Rollenhierarchie / Role Hierarchy

Die Rollen haben folgende Hierarchie und Prioritäten (höchste zuerst):

The roles have the following hierarchy and priorities (highest first):

1. **Alumni-Finanzprüfer** (`alumni_finanz`) - Priorität 10
   - Interner Name: `alumni_auditor`
   - Finanzprüfer der Alumni-Organisation

2. **Alumni-Vorstand** (`alumni_vorstand`) - Priorität 9
   - Interner Name: `alumni_board`
   - Vorstandsmitglied der Alumni-Organisation

3. **Vorstand Extern** (`vorstand_extern`) - Priorität 8
   - Interner Name: `board_external`
   - Externes Vorstandsmitglied

4. **Vorstand Intern** (`vorstand_intern`) - Priorität 7
   - Interner Name: `board_internal`
   - Internes Vorstandsmitglied

5. **Vorstand Finanzen und Recht** (`vorstand_finanzen`) - Priorität 6
   - Interner Name: `board_finance`
   - Vorstand für Finanzen und Rechtsfragen

6. **Ehrenmitglied** (`ehrenmitglied`) - Priorität 5
   - Interner Name: `honorary_member`
   - Ehrenmitglied des Vereins

7. **Alumni** (`alumni`) - Priorität 4
   - Interner Name: `alumni`
   - Ehemaliges Mitglied

8. **Ressortleiter** (`ressortleiter`) - Priorität 3
   - Interner Name: `head`
   - Leiter eines Ressorts

9. **Mitglied** (`mitglied`) - Priorität 2
   - Interner Name: `member`
   - Reguläres Mitglied

10. **Anwärter** (`anwaerter`) - Priorität 1
    - Interner Name: `candidate`
    - Anwärter/Kandidat für Mitgliedschaft

---

## Technische Details / Technical Details

### Rollenmapping (Role Mapping)

Die Rollen werden in folgenden Dateien konfiguriert:

The roles are configured in the following files:

#### 1. `includes/services/MicrosoftGraphService.php`

```php
private const ROLE_MAPPING = [
    'ehrenmitglied' => '09686b92-dbc8-4e66-a851-2dafea64df89',
    'alumni_finanz' => '39597941-0a22-4922-9587-e3d62ab986d6',
    'alumni_vorstand' => '8a45c6aa-e791-422e-b964-986d8bdd2ed8',
    'alumni' => '7ffd9c73-a828-4e34-a9f4-10f4ed00f796',
    'vorstand_extern' => 'bf17e26b-e5f1-4a63-ae56-91ab69ae33ca',
    'vorstand_intern' => 'f61e99e2-2717-4aff-b3f5-ef2ec489b598',
    'vorstand_finanzen' => '3ad43a76-75af-48a7-9974-7a2cf350f349',
    'ressortleiter' => '9456552d-0f49-42ff-bbde-495a60e61e61',
    'mitglied' => '70f07477-ea4e-4edc-b0e6-7e25968f16c0',
    'anwaerter' => '75edcb0a-c610-4ceb-82f2-457a9dde4fc0'
];
```

Diese Konstante mappt die Azure-Rollenwerte zu ihren Azure App Role IDs.

This constant maps Azure role values to their Azure App Role IDs.

#### 2. `config/config.php`

```php
define('ROLE_MAPPING', [
    // Lowercase versions (for App Roles)
    'anwaerter' => 'candidate',
    'mitglied' => 'member',
    'ressortleiter' => 'head',
    'vorstand_finanzen' => 'board_finance',
    'vorstand_intern' => 'board_internal',
    'vorstand_extern' => 'board_external',
    'alumni' => 'alumni',
    'alumni_vorstand' => 'alumni_board',
    'alumni_finanz' => 'alumni_auditor',
    'ehrenmitglied' => 'honorary_member',
    // ... and capitalized versions
]);
```

Diese Konstante mappt Azure-Rollenwerte zu internen Rollennamen.

This constant maps Azure role values to internal role names.

### Unterstützte Namensformate / Supported Name Formats

Das System unterstützt mehrere Namensformate für Azure-Gruppen:

The system supports multiple name formats for Azure groups:

- **Kleinbuchstaben mit Unterstrichen** (Lowercase with underscores): `vorstand_finanzen`
- **Großbuchstaben mit Unterstrichen** (Uppercase with underscores): `Vorstand_Finanzen`
- **Mit Leerzeichen** (With spaces): `Vorstand Finanzen`
- **Einfache Namen** (Simple names): `Vorstand`

Alle Formate werden case-insensitive behandelt.

All formats are treated case-insensitively.

---

## Zulässige Mitgliedstypen / Allowed Member Types

Alle konfigurierten Rollen erlauben folgende Mitgliedstypen:

All configured roles allow the following member types:

- **Benutzer** (Users)
- **Gruppen** (Groups)

Dies ermöglicht sowohl direkte Benutzerzuweisungen als auch Gruppenzuweisungen in Microsoft Entra ID.

This allows both direct user assignments and group assignments in Microsoft Entra ID.

---

## Synchronisierung / Synchronization

### Automatische Synchronisierung (Automatic Synchronization)

Die Rollen werden automatisch bei jedem Login mit Microsoft Entra ID synchronisiert:

Roles are automatically synchronized with Microsoft Entra ID on every login:

1. Benutzer meldet sich mit Microsoft Entra ID an
2. System ruft Benutzergruppen und App-Rollen ab
3. Rollen werden gemäß `ROLE_MAPPING` konvertiert
4. Höchste Rolle wird dem Benutzer zugewiesen

### Manuelle Synchronisierung (Manual Synchronization)

Administratoren können Rollen auch manuell über die Benutzerverwaltung ändern:

Administrators can also manually change roles via user management:

1. Navigation zu Admin > Benutzerverwaltung
2. Auswahl eines Benutzers
3. Rollenänderung wird automatisch mit Azure synchronisiert
4. Neue Rolle wird beim nächsten API-Aufruf aktiv

**Hinweis:** Für manuelle Synchronisierung muss die `azure_oid` (Azure Object ID) des Benutzers bekannt sein.

**Note:** For manual synchronization, the user's `azure_oid` (Azure Object ID) must be known.

---

## Berechtigungen / Permissions

Jede Rolle hat unterschiedliche Berechtigungen im System:

Each role has different permissions in the system:

### Vorstandsrollen (Board Roles)
- Zugriff auf Admin-Bereich
- Benutzerverwaltung
- Event-Management
- Finanzberichte
- Projekt-Genehmigung

### Alumni-Rollen (Alumni Roles)
- Alumni-Bereich Zugriff
- Profilansicht
- Event-Teilnahme (eingeschränkt)

### Mitgliederrollen (Member Roles)
- **Ressortleiter**: Ressort-Management, Event-Organisation
- **Mitglied**: Basis-Zugriff, Event-Teilnahme, Projekt-Bewerbung
- **Anwärter**: Eingeschränkter Zugriff, Event-Teilnahme

### Ehrenmitglied (Honorary Member)
- Besondere Privilegien
- Lebenslanger Zugang
- Event-Teilnahme

---

## Wartung und Updates / Maintenance and Updates

### Neue Rolle hinzufügen (Adding a New Role)

Um eine neue Rolle hinzuzufügen:

To add a new role:

1. **Azure Portal**: App-Rolle in Microsoft Entra ID erstellen
2. **MicrosoftGraphService.php**: Rolle zu `ROLE_MAPPING` hinzufügen
3. **config.php**: Mapping zu internem Namen hinzufügen
4. **Dokumentation**: Dieses Dokument aktualisieren

### Rolle ändern (Modifying a Role)

Um eine Rolle zu ändern:

To modify a role:

1. **Wichtig**: Azure Role IDs sollten NIEMALS geändert werden
2. Nur Beschreibungen und Anzeigenamen können in Azure aktualisiert werden
3. Interne Mappings können bei Bedarf angepasst werden

### Rolle deaktivieren (Deactivating a Role)

Um eine Rolle zu deaktivieren:

To deactivate a role:

1. **Azure Portal**: App-Rolle in Microsoft Entra ID deaktivieren
2. **Code**: Rolle aus `ROLE_MAPPING` entfernen (optional)
3. **Datenbank**: Existierende Zuweisungen manuell aktualisieren

---

## Fehlerbehebung / Troubleshooting

### Rolle wird nicht zugewiesen (Role Not Assigned)

Prüfen Sie folgendes:

Check the following:

1. Azure App-Rolle ist aktiviert
2. Benutzer ist der Rolle in Azure zugewiesen
3. `ROLE_MAPPING` enthält korrekten Wert
4. Benutzer hat `azure_oid` in der Datenbank
5. Logs prüfen: `/logs/` Verzeichnis

### Rolle wird nicht synchronisiert (Role Not Synchronized)

Debugging-Schritte:

Debugging steps:

1. `pages/auth/debug_entra.php` aufrufen
2. Session-Variablen prüfen: `$_SESSION['azure_roles']` und `$_SESSION['entra_roles']`
3. Datenbank-Feld `azure_roles` prüfen
4. Graph API Berechtigungen verifizieren:
   - `User.Read.All`
   - `AppRoleAssignment.ReadWrite.All`
   - `User.Invite.All`

---

## Referenzen / References

- [Microsoft Entra ID Documentation](https://learn.microsoft.com/en-us/entra/identity/)
- [Microsoft Graph API - App Role Assignments](https://learn.microsoft.com/en-us/graph/api/resources/approleassignment)
- Interne Dokumentation:
  - `md/AZURE_ROLE_SYNC_IMPLEMENTATION.md`
  - `md/ENTRA_ROLES_AND_NAVIGATION_SUMMARY.md`
  - `FIX_INVENTORY_AND_ROLES_SUMMARY.md`

---

## Änderungshistorie / Change History

| Datum / Date | Änderung / Change | Autor / Author |
|--------------|-------------------|----------------|
| 2026-02-18 | Initiale Dokumentation mit allen 10 Rollen | GitHub Copilot |

---

**Letzte Aktualisierung / Last Updated:** 2026-02-18
