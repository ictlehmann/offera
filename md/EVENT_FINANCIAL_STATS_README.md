# Event Financial Statistics Feature - Implementation Guide

## Übersicht

Diese Implementierung erweitert das Event-Modul um umfassende Finanzstatistik-Funktionen mit Jahresvergleich.

## Features

### 1. Neue Datenbank-Tabelle: `event_financial_stats`
- **Kategorie**: Verkauf oder Kalkulation
- **Artikelname**: z.B. "Brezeln", "Äpfel", "Grillstand"
- **Menge**: Anzahl verkauft/kalkuliert
- **Umsatz**: Optional, in Euro
- **Jahr**: Für historischen Vergleich (2025, 2026, etc.)
- **Validierung**: Keine negativen Zahlen

### 2. UI-Funktionen in `pages/events/view.php`

#### Neue Buttons im Event-Dashboard (Vorstand-Bereich):
- **"Neue Verkäufe tracken"** - Öffnet Modal für Verkaufsdaten
- **"Neue Kalkulation erfassen"** - Öffnet Modal für Kalkulationsdaten

#### Modal-Formular:
- Artikelname (Pflichtfeld)
- Menge (Pflichtfeld, nur positive Zahlen)
- Umsatz in Euro (Optional, nur positive Zahlen)
- Jahr (Pflichtfeld, default: aktuelles Jahr)
- Validierung auf Client- und Server-Seite

#### Vergleichstabelle:
- Zeigt alle erfassten Daten gruppiert nach Kategorie
- Vergleich über mehrere Jahre (z.B. 2025 vs. 2026)
- Automatische Summenberechnung

### 3. Statistiken-Seite (`pages/events/statistics.php`)

#### Erweiterte Ansicht:
- **Jahresvergleich-Tabellen** für alle Events
- Separate Darstellung für Verkäufe und Kalkulationen
- Übersichtliche Gegenüberstellung der Jahre
- Farbcodierung: Blau für Verkäufe, Grün für Kalkulationen

#### Beispiel-Anzeige:
```
Grillstand 2025: 20 Stück (450€)
Grillstand 2026: 25 Stück (550€)
```

## Installation

### 1. Datenbank-Migration ausführen

Führen Sie das SQL-Script aus:

```bash
cd /pfad/zum/projekt
php sql/migrate_event_financial_stats.php
```

Oder manuell via MySQL:
```bash
mysql -h [HOST] -u [USER] -p [DATABASE] < sql/add_event_financial_stats_table.sql
```

### 2. Dateien überprüfen

Neue Dateien:
- `sql/add_event_financial_stats_table.sql` - Datenbank-Schema
- `sql/migrate_event_financial_stats.php` - Migrations-Script
- `includes/models/EventFinancialStats.php` - Model-Klasse
- `api/save_financial_stats.php` - API zum Speichern
- `api/get_financial_stats.php` - API zum Abrufen

Modifizierte Dateien:
- `pages/events/view.php` - UI mit Buttons und Modal
- `pages/events/statistics.php` - Erweiterte Statistiken-Ansicht

## Verwendung

### Für Vorstandsmitglieder:

1. **Event öffnen**: Navigieren Sie zu einem Event in `pages/events/view.php`

2. **Verkäufe tracken**:
   - Klicken Sie auf "Neue Verkäufe tracken"
   - Geben Sie ein:
     - Artikelname: z.B. "Brezeln"
     - Menge: z.B. 50
     - Umsatz: z.B. 450.00 (optional)
     - Jahr: z.B. 2026
   - Klicken Sie auf "Speichern"

3. **Kalkulation erfassen**:
   - Klicken Sie auf "Neue Kalkulation erfassen"
   - Geben Sie ein:
     - Artikelname: z.B. "Getränke"
     - Menge: z.B. 100
     - Umsatz: optional
     - Jahr: z.B. 2026
   - Klicken Sie auf "Speichern"

4. **Vergleich ansehen**:
   - Die Tabelle wird automatisch aktualisiert
   - Besuchen Sie `pages/events/statistics.php` für den vollständigen Jahresvergleich

## Validierung

### Client-seitig (JavaScript):
- Artikelname darf nicht leer sein
- Menge muss >= 0 sein
- Umsatz muss >= 0 sein (wenn angegeben)

### Server-seitig (PHP):
- Authentifizierung erforderlich
- Nur Vorstand und Alumni-Vorstand haben Zugriff
- Kategorie muss "Verkauf" oder "Kalkulation" sein
- Alle Eingaben werden gegen SQL-Injection geschützt
- Negative Zahlen werden abgelehnt

## Datenbank-Schema

```sql
CREATE TABLE event_financial_stats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    category ENUM('Verkauf', 'Kalkulation') NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(10, 2) DEFAULT NULL,
    record_year YEAR NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NOT NULL,
    
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_event_id (event_id),
    INDEX idx_category (category),
    INDEX idx_record_year (record_year),
    INDEX idx_event_year (event_id, record_year)
);
```

## API-Endpunkte

### POST `/api/save_financial_stats.php`
Speichert einen neuen Finanzstatistik-Eintrag.

**Request Body:**
```json
{
  "event_id": 123,
  "category": "Verkauf",
  "item_name": "Brezeln",
  "quantity": 50,
  "revenue": 450.00,
  "record_year": 2026
}
```

**Response:**
```json
{
  "success": true,
  "message": "Eintrag erfolgreich gespeichert"
}
```

### GET `/api/get_financial_stats.php?event_id=123`
Ruft Finanzstatistiken für ein Event ab.

**Response:**
```json
{
  "success": true,
  "data": {
    "comparison": [...],
    "available_years": [2026, 2025],
    "all_stats": [...]
  }
}
```

## Sicherheit

- ✅ Nur authentifizierte Benutzer haben Zugriff
- ✅ Nur Vorstand und Alumni-Vorstand können Daten erfassen
- ✅ Alle Eingaben werden validiert und escaped
- ✅ SQL-Injection-Schutz durch Prepared Statements
- ✅ XSS-Schutz durch htmlspecialchars()
- ✅ CSRF-Schutz über Session-Validierung

## Beispiel-Workflow

### Szenario: BSW-Event 2026

1. Vorstand erfasst Verkäufe:
   - Brezeln: 50 Stück, 450€
   - Getränke: 100 Stück, 300€
   - Grillstand: 25 Stück, 550€

2. Vorstand erfasst Kalkulationen:
   - Brezeln (geplant): 60 Stück
   - Getränke (geplant): 120 Stück

3. Im nächsten Jahr (2027) werden die Daten für BSW-Event 2027 erfasst

4. In der Statistik-Seite sieht man den Vergleich:
   ```
   Brezeln:
   2026: 50 Stück (450€)
   2027: 65 Stück (550€)
   
   Getränke:
   2026: 100 Stück (300€)
   2027: 110 Stück (350€)
   ```

## Troubleshooting

### Problem: Modal öffnet sich nicht
- Überprüfen Sie die Browser-Konsole auf JavaScript-Fehler
- Stellen Sie sicher, dass Sie als Vorstand angemeldet sind

### Problem: Speichern schlägt fehl
- Überprüfen Sie die Netzwerk-Konsole auf API-Fehler
- Stellen Sie sicher, dass alle Pflichtfelder ausgefüllt sind
- Überprüfen Sie, dass keine negativen Zahlen eingegeben wurden

### Problem: Tabelle wird nicht angezeigt
- Stellen Sie sicher, dass die Datenbank-Migration erfolgreich war
- Überprüfen Sie, dass Daten in der Datenbank vorhanden sind
- Prüfen Sie die PHP-Fehlerprotokolle

## Support

Bei Fragen oder Problemen kontaktieren Sie bitte den Vorstand oder erstellen Sie ein Issue im Repository.
