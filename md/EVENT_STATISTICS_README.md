# Event Statistics Feature - Implementation Guide

## Overview
This feature adds comprehensive statistics tracking for events, allowing board members and alumni board to track sellers, calculations, and sales data with a historical view.

## Features Implemented

### 1. Seller Tracking (Verkäufer-Tracking)
- Track individual sellers/stands at events
- Record seller name, items sold, quantity, and revenue
- Example entries:
  - BSW - 50 Brezeln - 450€
  - BSW - 45 Äpfel
  - Grillstand 2026 - 25 Verkauft
  - Grillstand 2025 - 20 Verkauft

### 2. Calculations Tracking (Kalkulationen)
- Dedicated text area for event calculations
- Record budgets, costs, and financial details
- Separate button for adding new calculations

### 3. Sales Data (Verkaufsdaten)
- Track overall sales with labels, amounts, and dates
- Visual chart display of sales data
- Aggregated revenue tracking

### 4. Statistics History View
- View all event statistics across multiple events
- Summary cards showing:
  - Total documented events
  - Total seller entries
  - Total revenue
- Detailed breakdown per event

## Installation

### Database Migration
Run the following SQL migration to add the `sellers_data` column:

```bash
php sql/migrate_sellers_data.php
```

Or manually execute:
```sql
ALTER TABLE event_documentation 
ADD COLUMN sellers_data JSON DEFAULT NULL 
COMMENT 'JSON array of seller entries with name, items, quantity, and revenue';
```

## Usage

### For Board Members and Alumni Board

#### Accessing Event Statistics
1. Navigate to Events page
2. Click the "Statistiken" button (visible only to board members)
3. View historical statistics across all events

#### Adding Seller Tracking
1. Open any event detail page
2. Scroll to "Statistiken & Dokumentation" section
3. Click "Neuen Verkäufer tracken" button
4. Fill in:
   - Verkäufer/Stand (Seller name or stand name)
   - Artikel (Items sold)
   - Menge/Anzahl (Quantity)
   - Umsatz (Revenue - optional)
5. Click "Dokumentation speichern"

#### Adding Calculations
1. Open any event detail page
2. Scroll to "Kalkulationen" section
3. Click "Neue Kalkulation tracken" button (focuses text area)
4. Enter calculation details
5. Click "Dokumentation speichern"

#### Adding Sales Data
1. Open any event detail page
2. Scroll to "Verkaufsdaten (Gesamt)" section
3. Click "Verkauf hinzufügen"
4. Fill in label, amount, and date
5. Click "Dokumentation speichern"

## Technical Details

### Files Modified
- `api/save_event_documentation.php` - API endpoint for saving documentation
- `includes/models/EventDocumentation.php` - Model with seller data support
- `pages/events/view.php` - Event detail page with seller tracking UI
- `pages/events/index.php` - Added statistics button
- `pages/events/statistics.php` - New history view page

### Files Created
- `sql/add_sellers_data_to_event_documentation.sql` - SQL migration
- `sql/migrate_sellers_data.php` - PHP migration script
- `pages/events/statistics.php` - Statistics history page

### Database Schema
The `event_documentation` table now includes:
- `calculations` (TEXT) - Calculation notes
- `sales_data` (JSON) - Array of sales entries
- `sellers_data` (JSON) - Array of seller entries

Sellers data structure:
```json
[
  {
    "seller_name": "BSW",
    "items": "Brezeln",
    "quantity": "50",
    "revenue": "450€"
  }
]
```

## Security
- Access restricted to board members and alumni_board roles
- CSRF protection via existing Auth system
- Input validation and sanitization
- JSON encoding for secure data storage

## Testing Checklist
- [ ] Database migration applied successfully
- [ ] Seller tracking form works
- [ ] Calculations can be saved
- [ ] Sales data can be added
- [ ] Statistics page displays correctly
- [ ] Permissions are enforced (board/alumni_board only)
- [ ] Data persists across page reloads
- [ ] Multiple sellers can be added to one event
- [ ] History view shows all events

## Future Enhancements
- Export statistics to CSV/PDF
- Advanced filtering and search
- Comparison charts between events
- Revenue analytics and trends
