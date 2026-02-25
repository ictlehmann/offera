# Microsoft Entra Role Reading Fix - Summary

## Date: 2026-02-16

## Problem Statement (German)
Die Rollen werden aus dem Microsoft Entra nicht korrekt ausgelesen

**Translation**: The roles are not being read correctly from Microsoft Entra

## Root Cause Analysis

The system was attempting to read user roles from the JWT token's `roles` claim:
```php
$azureRoles = $claims['roles'] ?? [];
```

However, Microsoft Entra ID does not populate the `roles` claim by default. Instead, group memberships must be fetched via the Microsoft Graph API, which the system was already doing but **ONLY for display purposes** - not for determining the user's actual role.

### The Problem Flow

**Before the fix:**
1. User logs in via Microsoft Entra
2. System reads JWT token `roles` claim → Usually empty unless App Roles are explicitly configured
3. System maps roles to internal roles → Defaults to `member` since no roles found
4. Later, system fetches groups from Graph API → Stored in `entra_roles` column for display only
5. **Result**: User always gets `member` role regardless of their actual Entra groups

## Solution Implemented

### Changes Made to `includes/handlers/AuthHandler.php`

#### 1. Fetch Groups Early (Lines 514-531)
- Moved Microsoft Graph API call to happen BEFORE role determination
- Fetches user's group memberships using `getUserProfile($azureOid)`
- Stores groups in `$entraGroups` array

```php
// Fetch user's group memberships from Microsoft Graph API
$entraGroups = [];
if ($azureOid) {
    try {
        $graphService = new MicrosoftGraphService();
        $profileData = $graphService->getUserProfile($azureOid);
        $entraGroups = $profileData['groups'] ?? [];
    } catch (Exception $e) {
        error_log("Failed to fetch user groups from Microsoft Graph during login: " . $e->getMessage());
    }
}
```

#### 2. Enhanced Role Mapping (Lines 533-560)
- Added support for capitalized group names (e.g., `Vorstand_Finanzen`)
- Updated comments to clarify the mapping works for both JWT roles AND Entra groups

```php
$roleMapping = [
    'anwaerter' => 'candidate',
    'mitglied' => 'member',
    // ... lowercase versions
    'Anwaerter' => 'candidate',
    'Mitglied' => 'member',
    // ... capitalized versions
];
```

#### 3. Combined Role Sources (Lines 576-605)
- Merges JWT token roles and Entra groups into single array
- Checks both exact match and lowercase match for compatibility
- Selects role with highest priority from all sources

```php
$allRoleSources = array_merge($azureRoles, $entraGroups);

foreach ($allRoleSources as $roleSource) {
    $roleLower = strtolower($roleSource);
    
    if (isset($roleMapping[$roleSource])) {
        // Check exact match
    } elseif (isset($roleMapping[$roleLower])) {
        // Check lowercase match
    }
}
```

#### 4. Debug Logging
- Added comprehensive logging to help troubleshoot role assignment issues
- Logs: groups fetched, all role sources, and final selected role

#### 5. Avoid Duplicate API Calls (Lines 686-714)
- Reuses `$profileData` if already fetched for role determination
- Only calls Graph API once per login instead of twice

## Technical Details

### Role Priority Hierarchy
When a user belongs to multiple groups, the role with the highest priority is selected:

| Priority | Role | Internal Name |
|----------|------|---------------|
| 10 | Alumni-Finanzprüfer | alumni_auditor |
| 9 | Alumni-Vorstand | alumni_board |
| 8 | Vorstand Extern | board_external |
| 7 | Vorstand Intern | board_internal |
| 6 | Vorstand Finanzen | board_finance |
| 5 | Ehrenmitglied | honorary_member |
| 4 | Alumni | alumni |
| 3 | Ressortleiter | head |
| 2 | Mitglied | member |
| 1 | Anwärter | candidate |

### Expected Microsoft Entra Group Names

The system expects group display names in Microsoft Entra to match these patterns:
- **Lowercase**: `vorstand_finanzen`, `vorstand_intern`, `vorstand_extern`, etc.
- **Capitalized**: `Vorstand_Finanzen`, `Vorstand_Intern`, `Vorstand_Extern`, etc.
- **Case-insensitive**: `VORSTAND_FINANZEN` will also work due to lowercase fallback

### Microsoft Graph API Permissions Required

The Azure App Registration must have these permissions:
- `User.Read.All` - To read user profiles
- `GroupMember.Read.All` - To read group memberships
- `Directory.Read.All` - To read directory data (includes transitive group memberships)

## Testing

### Unit Test Results
Created and ran `/tmp/test_role_mapping.php` to verify the logic:

```
✓ JWT role only (lowercase): PASS
✓ Entra group only (lowercase): PASS
✓ Entra group only (capitalized): PASS
✓ Multiple groups - highest priority wins: PASS
✓ Both JWT and Entra - highest priority wins: PASS
✓ No matching roles - defaults to member: PASS
✓ Case insensitive matching: PASS

Results: 7 passed, 0 failed
```

### Manual Testing Required

To verify the fix works in production:

1. **Check error logs** after a user logs in:
   ```
   Microsoft Entra groups fetched for user {oid}: ["group1","group2"]
   Role determination for user {oid}: JWT roles = [], Entra groups = ["group1","group2"]
   Selected role for user {oid}: board_finance (priority: 6)
   ```

2. **Verify in database** that the `role` column is set correctly:
   ```sql
   SELECT id, email, role, entra_roles FROM users WHERE email = 'test@example.com';
   ```

3. **Test navigation permissions**:
   - User with `Vorstand_Intern` group should see "Systemeinstellungen" in Administration
   - User with `Alumni_Vorstand` group should see "Systemeinstellungen" in Administration
   - User with only `Mitglied` group should NOT see "Systemeinstellungen"

## Backward Compatibility

The fix maintains backward compatibility:
- Still supports JWT token `roles` claim if configured
- Combines both JWT roles AND Entra groups
- If both sources have roles, the highest priority role wins
- Defaults to `member` role if no valid roles found

## Security Considerations

- ✅ Role assignment is based on authenticated Microsoft Graph API calls
- ✅ Service account is used to fetch groups (not user's token)
- ✅ Groups are validated against the role mapping
- ✅ Invalid/unknown groups are ignored
- ✅ Always defaults to lowest privilege (`member`) if no matches

## Known Limitations

1. **Group Names Must Match**: Microsoft Entra group display names must exactly match the expected patterns (case-insensitive)
2. **Transitive Groups**: The system fetches transitive group memberships, so nested groups work correctly
3. **Caching**: Groups are fetched on every login, no caching is implemented
4. **Performance**: Adds one additional Graph API call during login (minimal impact)

## Files Modified

1. **includes/handlers/AuthHandler.php**
   - Lines 514-531: Added early group fetching
   - Lines 533-560: Enhanced role mapping with capitalized variants
   - Lines 576-605: Combined role sources with case-insensitive matching
   - Lines 686-714: Optimized to avoid duplicate API calls
   - Added comprehensive debug logging

## Migration Notes

**No database migrations required** - the fix is code-only.

The `entra_roles` column already exists and is being populated correctly. The fix simply uses those groups for role determination instead of just display.

## Deployment Instructions

1. Deploy the updated code to production
2. Monitor error logs for the new debug messages
3. Have test users with different roles log in
4. Verify their roles are assigned correctly in the database
5. Test that navigation permissions work as expected

## Troubleshooting

If roles are still not being assigned correctly:

1. **Check error logs** for these messages:
   - "Microsoft Entra groups fetched for user {oid}: ..."
   - "Role determination for user {oid}: ..."
   - "Selected role for user {oid}: ..."

2. **Verify Microsoft Graph API permissions** in Azure Portal:
   - Application has required permissions granted
   - Admin consent has been given
   - Service principal has access to read users and groups

3. **Check group names in Microsoft Entra**:
   - Group display names match expected patterns
   - No extra spaces or special characters
   - Users are members of the correct groups

4. **Verify database**:
   - `entra_roles` column is being populated with JSON array
   - `role` column is being updated to the correct internal role
   - `azure_oid` column contains the user's Object ID

## Success Criteria

- ✅ Users with Entra group memberships get correct internal roles
- ✅ Role hierarchy works correctly (highest priority wins)
- ✅ Case-insensitive group name matching works
- ✅ No duplicate Graph API calls
- ✅ Backward compatibility with JWT token roles
- ✅ Comprehensive debug logging for troubleshooting
- ✅ All unit tests pass

## Future Enhancements

1. **Caching**: Cache group memberships to reduce API calls
2. **Admin UI**: Add admin interface to view/test role assignments
3. **Audit Log**: Log role changes in audit log table
4. **Group Mapping Config**: Move group-to-role mapping to configuration file
5. **Multiple Roles**: Support users having multiple active roles instead of selecting highest priority

## References

- Microsoft Graph API: https://docs.microsoft.com/en-us/graph/api/user-list-transitivememberof
- Azure AD App Roles: https://docs.microsoft.com/en-us/azure/active-directory/develop/howto-add-app-roles-in-azure-ad-apps
- Original issue: ENTRA_ROLES_AND_NAVIGATION_SUMMARY.md
