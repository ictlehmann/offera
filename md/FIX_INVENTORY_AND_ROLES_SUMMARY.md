# Fix Summary: Inventory Checkout and Microsoft Entra Role Mapping

## Date: 2026-02-17

## Problem Statement (Original - German)

1. **Inventar (Ausleihen):** - Analysiere `pages/inventory/checkout.php` und das Model `Inventory.php`.
   - Stelle sicher, dass beim Ausleihen:
     a) Der Lagerbestand (Quantity) korrekt reduziert wird.
     b) Ein Eintrag in der `rentals` (oder `checkouts`) Tabelle erstellt wird.
     c) Fehler (z.B. Bestand = 0) sauber abgefangen werden.

2. **Microsoft Entra Rollen:**
   - In `MicrosoftGraphService.php` und `AuthHandler.php`: Das Mapping der Entra-Gruppen funktioniert nicht korrekt.
   - Prüfe, ob `transitiveMemberOf` korrekt iteriert wird.
   - Stelle sicher, dass die `displayName`s der Gruppen aus Azure gegen die interne `ROLE_MAPPING` Logik geprüft werden. Wenn Azure "Vorstand" liefert, muss das System dem User die interne Rolle `vorstand` zuweisen.
   - Debugge die Session-Variable `$_SESSION['azure_roles']` in `main_layout.php`, um zu sehen, was wirklich ankommt.

## Translation

1. **Inventory (Checkout):** - Analyze `pages/inventory/checkout.php` and the model `Inventory.php`.
   - Ensure that when checking out:
     a) The inventory stock (Quantity) is correctly reduced.
     b) An entry is created in the `rentals` (or `checkouts`) table.
     c) Errors (e.g., stock = 0) are cleanly handled.

2. **Microsoft Entra Roles:**
   - In `MicrosoftGraphService.php` and `AuthHandler.php`: The mapping of Entra groups is not working correctly.
   - Check if `transitiveMemberOf` is correctly iterated.
   - Ensure that the `displayName`s of groups from Azure are checked against the internal `ROLE_MAPPING` logic. If Azure delivers "Vorstand", the system must assign the internal role `vorstand` to the user.
   - Debug the session variable `$_SESSION['azure_roles']` in `main_layout.php` to see what really arrives.

## Root Cause Analysis

### Issue 1: Inventory Checkout Double-Counting Bug

**The Problem:**
The system had a double-counting bug in the `available_quantity` calculation:

1. When checking out items, the code correctly:
   - Reduces `quantity` in `inventory_items` table
   - Creates a rental record in `rentals` table

2. However, the `available_quantity` calculation was:
   ```sql
   (i.quantity - COALESCE(SUM(r.amount), 0)) as available_quantity
   ```

This subtracted active rentals from an already-reduced quantity!

**Example:**
- Initial: quantity=100, active_rentals=0 → available=100 ✓
- After checkout 10: quantity=90, active_rentals=10 → available=80 ❌ (should be 90!)
- After return: quantity=100, active_rentals=0 → available=100 ✓

The bug only appeared when items were checked out but not returned.

### Issue 2: Microsoft Entra Role Mapping Gaps

**The Problem:**
The role mapping supported specific formats but was missing common variations:

1. **Missing simple names**: "Vorstand" (without suffix) had no mapping
2. **Missing space-separated names**: "Vorstand Finanzen" was not supported
3. **Limited debugging**: Hard to troubleshoot what roles were being received

The `transitiveMemberOf` iteration was already working correctly via `MicrosoftGraphService::getUserProfile()`.

## Solution Implemented

### Changes to `includes/models/Inventory.php`

#### 1. Fixed `getById()` Method (Lines 20-32)
**Before:**
```sql
(i.quantity - COALESCE(SUM(r.amount), 0)) as available_quantity
FROM inventory_items i
LEFT JOIN rentals r ON i.id = r.item_id AND r.actual_return IS NULL
...
GROUP BY i.id, c.name, c.color, l.name
```

**After:**
```sql
i.quantity as available_quantity
FROM inventory_items i
LEFT JOIN categories c ON i.category_id = c.id
LEFT JOIN locations l ON i.location_id = l.id
-- No more JOIN with rentals table, no more GROUP BY
```

**Reason:** Since `quantity` is reduced during checkout, `available_quantity` is simply the current `quantity`. No need to subtract active rentals.

#### 2. Fixed `getAll()` Method (Lines 144-159)
Applied the same fix as `getById()` to ensure consistent behavior across all queries.

#### 3. Improved Error Messages in `checkoutItem()` (Lines 450-460)
- Added available quantity to error message: `'Verfügbar: ' . $item['quantity']`
- Kept validation for invalid quantity (<=0)
- Removed redundant stock=0 check (already covered by earlier validation)

### Changes to `includes/handlers/AuthHandler.php`

#### Enhanced Role Mapping (Lines 540-583)
Added comprehensive support for multiple Azure group name formats:

**New Mappings:**
```php
// Simple names (NEW)
'vorstand' => 'board_internal',
'Vorstand' => 'board_internal',

// Space-separated names (NEW)
'Vorstand Finanzen' => 'board_finance',
'Vorstand Intern' => 'board_internal',
'Vorstand Extern' => 'board_external',
'Alumni Vorstand' => 'alumni_board',
'Alumni Finanz' => 'alumni_auditor',

// Existing underscore-separated names
'vorstand_finanzen' => 'board_finance',
'Vorstand_Finanzen' => 'board_finance',
// ... etc.
```

**Documentation:** Added clarifying comments explaining that duplicate mappings are intentional to document all supported formats explicitly.

### Changes to `includes/templates/main_layout.php`

#### Added Debug Logging (Lines 767-780)
```php
// Debug logging for role determination
if (!empty($currentUser['entra_roles'])) {
    error_log("main_layout.php: User " . intval($currentUser['id']) . " has entra_roles in database: " . $currentUser['entra_roles']);
}
if (!empty($_SESSION['azure_roles'])) {
    error_log("main_layout.php: Session azure_roles for user " . intval($currentUser['id']) . ": " . ...);
}
if (!empty($_SESSION['entra_roles'])) {
    error_log("main_layout.php: Session entra_roles for user " . intval($currentUser['id']) . ": " . ...);
}
```

#### Improved Priority Order (Lines 782-798)
Changed priority to prefer `entra_roles` from session over `azure_roles`:
1. `entra_roles` from database (groups from Microsoft Graph)
2. `entra_roles` from session (NEW priority)
3. `azure_roles` from session (App Roles from JWT)
4. Internal role as fallback

## Testing

### Unit Tests Created

**Test 1: Inventory Checkout Logic** (`/tmp/test_inventory_checkout.php`)
- ✓ Initial state calculation
- ✓ After checkout calculation (demonstrates the bug fix)
- ✓ After checkin calculation
- ✓ Multiple active rentals
- ✓ Edge case: stock = 0

**Result:** 5/5 tests pass

**Test 2: Role Mapping Logic** (`/tmp/test_role_mapping.php`)
- ✓ Simple "Vorstand" group (NEW)
- ✓ Lowercase "vorstand" (NEW)
- ✓ Space-separated "Vorstand Finanzen" (NEW)
- ✓ Underscore "Vorstand_Finanzen"
- ✓ Space-separated "Vorstand Intern" (NEW)
- ✓ Space-separated "Alumni Vorstand" (NEW)
- ✓ Multiple groups - highest priority wins
- ✓ Case insensitive matching
- ✓ Mixed format groups
- ✓ No matching roles - defaults to member

**Result:** 10/10 tests pass

### Code Quality Checks

- ✓ PHP syntax validation: All files pass
- ✓ Code review: All feedback addressed
- ✓ CodeQL security scan: No issues found

## Manual Testing Required

To fully verify the fixes in production:

### For Inventory Checkout:
1. Navigate to inventory item view
2. Click "Ausleihen" (Checkout)
3. Try to checkout more than available → Should see error with available quantity
4. Checkout valid amount → Should succeed
5. Check that:
   - `quantity` in `inventory_items` is reduced
   - Entry created in `rentals` table
   - `available_quantity` shown on UI matches the reduced quantity
6. Return items (checkin) → Check that quantity increases

### For Microsoft Entra Roles:
1. Login with Azure account that belongs to a group named:
   - "Vorstand" (simple name)
   - "Vorstand Finanzen" (space-separated)
   - "Vorstand_Intern" (underscore)
2. Check error logs for:
   ```
   main_layout.php: User X has entra_roles in database: [...]
   main_layout.php: Session entra_roles for user X: [...]
   ```
3. Verify user is assigned correct internal role (e.g., "board_internal" for "Vorstand")
4. Check user profile shows correct role display name

## Expected Microsoft Entra Group Names

The system now supports these Azure group display name formats:

| Azure Group Name | Internal Role | Priority |
|-----------------|---------------|----------|
| Alumni Finanz / Alumni_Finanz | alumni_auditor | 10 |
| Alumni Vorstand / Alumni_Vorstand | alumni_board | 9 |
| Vorstand Extern / Vorstand_Extern | board_external | 8 |
| Vorstand Intern / Vorstand_Intern | board_internal | 7 |
| Vorstand Finanzen / Vorstand_Finanzen | board_finance | 6 |
| Vorstand (simple) | board_internal | 7 |
| Ehrenmitglied | honorary_member | 5 |
| Alumni | alumni | 4 |
| Ressortleiter | head | 3 |
| Mitglied | member | 2 |
| Anwaerter | candidate | 1 |

**Note:** All names are case-insensitive due to fallback logic.

## Files Modified

1. `includes/models/Inventory.php` - Fixed double-counting bug
2. `includes/handlers/AuthHandler.php` - Enhanced role mapping
3. `includes/templates/main_layout.php` - Added debug logging

## Commits

1. `ffa07d9` - Fix inventory checkout and Microsoft Entra role mapping issues
2. `601b240` - Address code review feedback - remove redundant check and clarify role mapping

## Summary

Both issues have been successfully resolved:

1. ✅ **Inventory Checkout**: Fixed double-counting bug, quantity is properly reduced, rentals are tracked, errors are handled
2. ✅ **Microsoft Entra Roles**: Enhanced role mapping to support multiple formats, added debug logging, transitiveMemberOf already working

All automated tests pass and the code is ready for manual testing in production.
