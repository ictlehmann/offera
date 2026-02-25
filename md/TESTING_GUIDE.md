# Manual Testing Guide for Event Statistics Feature

## Prerequisites
1. Database migration must be applied first:
   ```bash
   php sql/migrate_sellers_data.php
   ```
   Or run the SQL directly:
   ```sql
   ALTER TABLE event_documentation 
   ADD COLUMN sellers_data JSON DEFAULT NULL 
   COMMENT 'JSON array of seller entries with name, items, quantity, and revenue';
   ```

2. Login as a board member or alumni_board user

## Test Cases

### Test 1: Access Control
**Expected**: Only board and alumni_board can see statistics features

- [ ] Login as regular member
- [ ] Navigate to Events page
- [ ] Verify "Statistiken" button is NOT visible
- [ ] Navigate to an event detail page
- [ ] Verify "Statistiken & Dokumentation" section is NOT visible

- [ ] Login as board member
- [ ] Navigate to Events page
- [ ] Verify "Statistiken" button IS visible
- [ ] Navigate to an event detail page
- [ ] Verify "Statistiken & Dokumentation" section IS visible

### Test 2: Add Seller Tracking
**Expected**: Can add and save seller data

- [ ] Login as board member
- [ ] Navigate to any event detail page
- [ ] Scroll to "Verkäufer-Tracking" section
- [ ] Click "Neuen Verkäufer tracken" button
- [ ] Verify empty seller entry form appears
- [ ] Fill in seller details:
  - Verkäufer/Stand: "BSW"
  - Artikel: "Brezeln"
  - Menge: "50"
  - Umsatz: "450€"
- [ ] Click "Dokumentation speichern"
- [ ] Verify success message appears
- [ ] Reload page
- [ ] Verify seller data persists

### Test 3: Add Multiple Sellers
**Expected**: Can add multiple sellers to one event

- [ ] Add first seller: BSW - Brezeln - 50 - 450€
- [ ] Click "Neuen Verkäufer tracken" again
- [ ] Add second seller: BSW - Äpfel - 45 - (leave empty)
- [ ] Click "Neuen Verkäufer tracken" again
- [ ] Add third seller: Grillstand 2026 - (leave empty) - 25 Verkauft - (leave empty)
- [ ] Click "Dokumentation speichern"
- [ ] Verify all three sellers are saved
- [ ] Reload page and verify all data persists

### Test 4: Edit Seller Data
**Expected**: Can modify existing seller entries

- [ ] Open event with existing seller data
- [ ] Modify a seller's quantity field
- [ ] Click "Dokumentation speichern"
- [ ] Reload page
- [ ] Verify changes persisted

### Test 5: Delete Seller Entry
**Expected**: Can remove a seller entry

- [ ] Open event with multiple sellers
- [ ] Click trash icon on one seller
- [ ] Confirm deletion in popup
- [ ] Verify entry is removed from display
- [ ] Click "Dokumentation speichern"
- [ ] Reload page
- [ ] Verify seller is permanently deleted

### Test 6: Add Calculations
**Expected**: Can add and save calculation notes

- [ ] Scroll to "Kalkulationen" section
- [ ] Click "Neue Kalkulation tracken" button
- [ ] Verify focus moves to text area
- [ ] Enter calculation details:
  ```
  Budget: 1000€
  Kosten: 550€
  Gewinn: 450€
  ```
- [ ] Click "Dokumentation speichern"
- [ ] Reload page
- [ ] Verify calculations persist

### Test 7: Add Sales Data
**Expected**: Can add and track overall sales

- [ ] Scroll to "Verkaufsdaten (Gesamt)" section
- [ ] Click "Verkauf hinzufügen"
- [ ] Fill in:
  - Bezeichnung: "Ticketverkauf"
  - Betrag: "1200"
  - Datum: (today)
- [ ] Verify chart updates
- [ ] Click "Dokumentation speichern"
- [ ] Reload page
- [ ] Verify sales data and chart persist

### Test 8: Statistics History View
**Expected**: Can view all event statistics in one page

- [ ] From Events page, click "Statistiken" button
- [ ] Verify statistics page loads
- [ ] Verify summary cards show correct totals:
  - Events dokumentiert
  - Verkäufer-Einträge
  - Gesamtumsatz
- [ ] Verify each event is listed with its data
- [ ] Verify seller data is shown in table format
- [ ] Verify calculations are displayed
- [ ] Click "Event ansehen" on any event
- [ ] Verify navigation to event detail page

### Test 9: XSS Protection
**Expected**: Special characters are properly escaped

- [ ] Add seller with HTML/JS in fields:
  - Verkäufer: `<script>alert('xss')</script>`
  - Artikel: `<img src=x onerror=alert(1)>`
- [ ] Save and reload
- [ ] Verify no JavaScript executes
- [ ] Verify characters are displayed as text

### Test 10: Empty State Handling
**Expected**: Proper messages when no data exists

- [ ] Create new event (or find event with no documentation)
- [ ] View event detail page
- [ ] Verify message: "Keine Verkäufer-Daten vorhanden..."
- [ ] View statistics history page with no documented events
- [ ] Verify message: "Noch keine Statistiken vorhanden"

## Performance Tests

### Test 11: Large Dataset
**Expected**: System handles many sellers efficiently

- [ ] Add 20+ sellers to one event
- [ ] Verify page loads quickly
- [ ] Verify save operation completes successfully
- [ ] View statistics history with 10+ events
- [ ] Verify page loads in reasonable time

## Browser Compatibility

### Test 12: Cross-Browser Testing
**Expected**: Works in all major browsers

- [ ] Test in Chrome/Edge
- [ ] Test in Firefox
- [ ] Test in Safari (if available)
- [ ] Verify UI renders correctly
- [ ] Verify all interactions work

## Reporting Issues

If any test fails, document:
1. Test case number and name
2. Expected behavior
3. Actual behavior
4. Steps to reproduce
5. Browser/environment details
6. Screenshots if applicable
