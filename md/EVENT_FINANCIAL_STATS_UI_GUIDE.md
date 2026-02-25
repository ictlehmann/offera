# Event Financial Statistics - Visual UI Guide

## UI-Komponenten Übersicht

Diese Anleitung zeigt die neuen UI-Komponenten für die Finanzstatistik-Funktionalität.

---

## 1. Event-Detail-Seite (pages/events/view.php)

### Neue Sektion: "Finanzstatistiken & Jahresvergleich"

Diese Sektion erscheint nur für Vorstandsmitglieder und Alumni-Vorstand im Event-Dashboard.

#### Komponenten:

**A. Header:**
```
📊 Finanzstatistiken & Jahresvergleich
(Nur für Vorstand sichtbar)
```
- Icon: Teal-farbenes Balkendiagramm
- Farbe: Teal-Gradient (from-teal-600 to-teal-700)

**B. Action Buttons (nebeneinander):**

```
┌─────────────────────────────────┐  ┌─────────────────────────────────┐
│  🛒 Neue Verkäufe tracken       │  │  🧮 Neue Kalkulation erfassen   │
└─────────────────────────────────┘  └─────────────────────────────────┘
```
- Button 1: Blauer Gradient (from-blue-600 to-blue-700)
- Button 2: Grüner Gradient (from-green-600 to-green-700)
- Beide Buttons öffnen das gleiche Modal, nur mit unterschiedlicher Kategorie

**C. Vergleichstabelle:**

Zeigt automatisch aktualisierte Daten nach dem Speichern:

```
┌─────────────────────────────────────────────────────────────────┐
│ 🛒 Verkauf                                                      │
├──────────────┬─────────────────┬─────────────────┬──────────────┤
│ Artikel      │      2025       │      2026       │     2027     │
├──────────────┼─────────────────┼─────────────────┼──────────────┤
│ Brezeln      │  50 (450.00€)   │  65 (550.00€)   │      -       │
│ Getränke     │ 100 (300.00€)   │ 110 (350.00€)   │      -       │
│ Grillstand   │  25 (550.00€)   │  30 (600.00€)   │      -       │
└──────────────┴─────────────────┴─────────────────┴──────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ 🧮 Kalkulation                                                  │
├──────────────┬─────────────────┬─────────────────┬──────────────┤
│ Artikel      │      2025       │      2026       │     2027     │
├──────────────┼─────────────────┼─────────────────┼──────────────┤
│ Brezeln      │       60        │       70        │      -       │
│ Getränke     │      120        │      130        │      -       │
└──────────────┴─────────────────┴─────────────────┴──────────────┘
```

- Blaue Überschrift für Verkäufe
- Grüne Überschrift für Kalkulationen
- Hover-Effekt auf Zeilen (hellgrau)
- "-" für fehlende Daten

---

## 2. Modal-Formular

Wird beim Klick auf "Neue Verkäufe tracken" oder "Neue Kalkulation erfassen" geöffnet.

### Layout:

```
┌────────────────────────────────────────────────┐
│  Neue Verkäufe tracken                    [X]  │
├────────────────────────────────────────────────┤
│                                                │
│  Artikel/Stand-Name *                          │
│  ┌──────────────────────────────────────────┐ │
│  │ z.B. Brezeln, Äpfel, Grillstand          │ │
│  └──────────────────────────────────────────┘ │
│                                                │
│  Menge *                                       │
│  ┌──────────────────────────────────────────┐ │
│  │ z.B. 50                                   │ │
│  └──────────────────────────────────────────┘ │
│                                                │
│  Umsatz (€) (optional)                         │
│  ┌──────────────────────────────────────────┐ │
│  │ z.B. 450.00                               │ │
│  └──────────────────────────────────────────┘ │
│                                                │
│  Jahr *                                        │
│  ┌──────────────────────────────────────────┐ │
│  │ 2026                                      │ │
│  └──────────────────────────────────────────┘ │
│                                                │
│  ┌──────────────┐  ┌───────────────────────┐ │
│  │  Abbrechen   │  │  💾 Speichern         │ │
│  └──────────────┘  └───────────────────────┘ │
└────────────────────────────────────────────────┘
```

**Features:**
- Titel ändert sich je nach Kategorie (Verkauf/Kalkulation)
- Jahr ist vorausgefüllt mit dem aktuellen Jahr
- Validierung bei Eingabe:
  - Menge und Umsatz müssen >= 0 sein
  - Artikelname darf nicht leer sein
- Dark-Mode-Unterstützung

### Validierungs-Meldungen:

Erfolg (grün):
```
✓ Eintrag erfolgreich gespeichert!
```

Fehler (rot):
```
✗ Bitte geben Sie einen gültigen Umsatz ein (≥ 0)
✗ Bitte geben Sie eine gültige Menge ein (≥ 0)
✗ Bitte geben Sie einen Artikelnamen ein
```

---

## 3. Statistik-Seite (pages/events/statistics.php)

### Neue Sektion: "Finanzstatistiken - Jahresvergleich"

Erscheint nach den bestehenden Event-Dokumentationen.

#### Header:

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  📈 Finanzstatistiken - Jahresvergleich                         │
│                                                                 │
│  Vergleich von Verkäufen und Kalkulationen über verschiedene   │
│  Jahre                                                          │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```
- Teal-Gradient Hintergrund (from-teal-600 to-teal-700)
- Weißer Text

#### Event-Karten:

Für jedes Event mit Finanzstatistiken:

```
┌─────────────────────────────────────────────────────────────────┐
│  BSW - Bundesweites Sommerfest                    [Event ansehen]│
│  📅 15.06.2026                                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  🛒 Verkäufe                                                    │
│  ┌───────────────┬─────────────┬─────────────┬──────────────┐ │
│  │ Artikel       │    2025     │    2026     │     2027     │ │
│  ├───────────────┼─────────────┼─────────────┼──────────────┤ │
│  │ Brezeln       │ 50 Stück    │ 65 Stück    │      -       │ │
│  │               │  450.00€    │  550.00€    │              │ │
│  │ Getränke      │ 100 Stück   │ 110 Stück   │      -       │ │
│  │               │  300.00€    │  350.00€    │              │ │
│  └───────────────┴─────────────┴─────────────┴──────────────┘ │
│                                                                 │
│  🧮 Kalkulationen                                               │
│  ┌───────────────┬─────────────┬─────────────┬──────────────┐ │
│  │ Artikel       │    2025     │    2026     │     2027     │ │
│  ├───────────────┼─────────────┼─────────────┼──────────────┤ │
│  │ Brezeln       │ 60 Stück    │ 70 Stück    │      -       │ │
│  │ Getränke      │ 120 Stück   │ 130 Stück   │      -       │ │
│  └───────────────┴─────────────┴─────────────┴──────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

**Features:**
- Farbcodierung:
  - Verkäufe: Blaue Überschrift und blaue Hervorhebung der Mengen
  - Kalkulationen: Grüne Überschrift und grüne Hervorhebung der Mengen
- Umsatz wird in kleinerer Schrift unter der Menge angezeigt
- Button "Event ansehen" führt zur Event-Detail-Seite
- Hover-Effekt auf Tabellenzeilen

---

## 4. Farbschema

### Kategorien:
- **Verkauf**: Blau (#2563EB - blue-600)
- **Kalkulation**: Grün (#16A34A - green-600)
- **Finanzstatistiken allgemein**: Teal (#0D9488 - teal-600)

### UI-Elemente:
- **Buttons**: Gradient-Effekt mit Hover-Übergang
- **Tabellen**: 
  - Header: Farbige Hintergründe (light für Light-Mode, dark/30 für Dark-Mode)
  - Zeilen: Hover-Effekt (bg-gray-50 / bg-gray-700)
  - Border: Gray-300 (Light) / Gray-600 (Dark)

---

## 5. Responsive Design

### Desktop (>= 768px):
- Volle Tabellenbreite
- Buttons nebeneinander
- Modal zentriert mit max-width

### Mobile (< 768px):
- Tabellen horizontal scrollbar
- Buttons untereinander gestapelt
- Modal nimmt volle Breite ein (mit Padding)

---

## 6. Dark Mode Support

Alle Komponenten unterstützen automatisch Dark Mode:
- Hintergründe: `bg-white dark:bg-gray-800`
- Text: `text-gray-800 dark:text-gray-100`
- Borders: `border-gray-300 dark:border-gray-600`
- Inputs: `bg-white dark:bg-gray-700`

---

## 7. Accessibility

- ✅ Semantisches HTML (table, form, labels)
- ✅ ARIA-Labels wo nötig
- ✅ Keyboard-Navigation (Tab, Enter, Esc)
- ✅ Focus-States für alle interaktiven Elemente
- ✅ Kontrastverhältnisse WCAG 2.1 AA konform
- ✅ Icons mit Text-Alternativen

---

## 8. Animations & Transitions

- Button Hover: `transition-all` (200ms ease)
- Table Row Hover: Smooth background color change
- Modal: Fade-in beim Öffnen, Fade-out beim Schließen
- Success/Error Messages: Slide-in von oben rechts, auto-hide nach 5s

---

## 9. Icons (Font Awesome)

Verwendete Icons:
- `fa-chart-bar` - Finanzstatistiken Header
- `fa-shopping-cart` - Verkäufe Button & Überschrift
- `fa-calculator` - Kalkulation Button & Überschrift
- `fa-save` - Speichern Button
- `fa-times` - Modal schließen
- `fa-eye` - Event ansehen
- `fa-calendar` - Datum
- `fa-check-circle` - Erfolgs-Meldung
- `fa-exclamation-circle` - Fehler-Meldung
- `fa-spinner fa-spin` - Lade-Animation

---

## 10. Beispiel-Szenarien

### Szenario 1: Erstmalige Erfassung
1. Benutzer öffnet Event-Detail-Seite
2. Klickt auf "Neue Verkäufe tracken"
3. Gibt ein: Brezeln, 50, 450.00, 2026
4. Klickt "Speichern"
5. Tabelle wird aktualisiert und zeigt: Brezeln | 2026: 50 (450.00€)

### Szenario 2: Jahresvergleich
1. Benutzer öffnet Statistik-Seite
2. Sieht Event mit Daten aus 2025 und 2026
3. Kann direkt vergleichen: Brezeln 2025 vs. 2026
4. Erkennt Trend: Verkauf um 30% gestiegen

### Szenario 3: Mobile-Nutzung
1. Benutzer öffnet Event auf Smartphone
2. Tabelle scrollt horizontal
3. Modal öffnet sich im Vollbild
4. Touch-optimierte Buttons

---

Diese UI-Implementierung bietet eine intuitive, professionelle Lösung für das Tracking und den Vergleich von Event-Finanzstatistiken.
