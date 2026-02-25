# MicrosoftGraphService Documentation

## Overview

The `MicrosoftGraphService` class provides integration with Microsoft Graph API for user invitation, role assignment, and user profile photo retrieval functionality. It uses the Azure Client Credentials Flow for authentication and supports the Azure App Permissions `User.Invite.All`, `AppRoleAssignment.ReadWrite.All`, and `User.Read.All`.

## Location

`includes/services/MicrosoftGraphService.php`

## Prerequisites

### Azure App Permissions

The following Azure App Permissions must be configured in your Azure AD application:
- `User.Invite.All` - Required for inviting users
- `AppRoleAssignment.ReadWrite.All` - Required for assigning roles to users
- `User.Read.All` - Required for reading user profile photos

### Environment Variables

The following environment variables must be configured in your `.env` file:

```env
AZURE_TENANT_ID="your-tenant-id"
AZURE_CLIENT_ID="your-client-id"
AZURE_CLIENT_SECRET="your-client-secret"
```

### Dependencies

- `guzzlehttp/guzzle` ^7.0 - HTTP client library
- PHP 7.4 or higher

## Class Methods

### Constructor

```php
public function __construct()
```

**Description**: Initializes the service and obtains an access token using the Azure Client Credentials Flow.

**Authentication Flow**:
1. Reads Azure credentials from environment variables (via config.php)
2. Requests access token from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
3. Uses scope: `https://graph.microsoft.com/.default`
4. Caches the access token for subsequent API calls

**Throws**: `Exception` if:
- Azure credentials are not configured
- Token request fails

### inviteUser()

```php
public function inviteUser(string $email, string $name, string $redirectUrl): string
```

**Description**: Invites a user to the Azure AD tenant via Microsoft Graph API.

**Parameters**:
- `$email` (string) - Email address of the user to invite
- `$name` (string) - Display name for the invited user
- `$redirectUrl` (string) - URL to redirect the user after accepting the invitation

**Returns**: `string` - The User ID (Object ID) of the newly invited user

**API Endpoint**: `POST https://graph.microsoft.com/v1.0/invitations`

**Request Body**:
```json
{
    "invitedUserEmailAddress": "user@example.com",
    "invitedUserDisplayName": "User Name",
    "inviteRedirectUrl": "https://your-app.com/welcome",
    "sendInvitationMessage": true
}
```

**Throws**: `Exception` if:
- Invitation request fails
- User ID is not found in response

### assignRole()

```php
public function assignRole(string $userId, string $roleValue): bool
```

**Description**: Assigns an application role to a user.

**Parameters**:
- `$userId` (string) - User ID (Object ID from Azure AD)
- `$roleValue` (string) - Role value from the role mapping (e.g., 'anwaerter', 'mitglied')

**Returns**: `bool` - True if role assignment succeeded

**API Endpoint**: `POST https://graph.microsoft.com/v1.0/users/{userId}/appRoleAssignments`

**Role Mapping**: The service includes a mapping of role values to Azure App Role IDs:
- `anwaerter`
- `mitglied`
- `vorstand_intern`
- `vorstand_extern`
- `berater`
- `alumni`
- `ehrenmitglied`
- `senior`
- `partner`
- `gast`

**Important**: Replace the placeholder IDs (e.g., `PLACEHOLDER_ANWAERTER_ID`, `PLACEHOLDER_MITGLIED_ID`) with actual Azure App Role IDs from your Azure AD application.

**Request Body**:
```json
{
    "principalId": "user-object-id",
    "resourceId": "service-principal-object-id",
    "appRoleId": "role-id"
}
```

**Throws**: `Exception` if:
- Role value is invalid (not in mapping)
- Service Principal ID cannot be retrieved
- Role assignment request fails

### getUserPhoto()

```php
public function getUserPhoto(string $userId): ?string
```

**Description**: Retrieves the user profile photo from Microsoft Entra ID (Azure AD).

**Parameters**:
- `$userId` (string) - User ID (Object ID from Azure AD)

**Returns**: `string|null` - Binary content of the photo if it exists, or `null` if no photo is found

**API Endpoint**: `GET https://graph.microsoft.com/v1.0/users/{userId}/photo/$value`

**Behavior**:
- Returns the binary content (image data) when a photo exists (HTTP 200)
- Returns `null` when no photo is available (HTTP 404)
- Throws an exception for other errors (e.g., network failures, authentication errors)

**Throws**: `Exception` if:
- API request fails (excluding 404 responses)
- Network or authentication errors occur

**Example**:
```php
$userId = 'user-object-id';
$photoData = $graphService->getUserPhoto($userId);

if ($photoData !== null) {
    // Save the photo
    // Note: Photo format can be JPEG, PNG, GIF, etc.
    file_put_contents('user-photo.jpg', $photoData);
    
    // Or output directly in browser with proper headers
    // Detect the actual format from image data
    $imageInfo = getimagesizefromstring($photoData);
    $mimeType = $imageInfo['mime'] ?? 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    echo $photoData;
} else {
    echo "No photo available for this user";
}
```

### getServicePrincipalId() (Private)

```php
private function getServicePrincipalId(): string
```

**Description**: Retrieves the Service Principal Object ID for the application. This ID is cached after the first retrieval to avoid repeated API calls.

**Returns**: `string` - Service Principal Object ID

**API Endpoint**: `GET https://graph.microsoft.com/v1.0/servicePrincipals(appId='{clientId}')`

**Note**: The Service Principal Object ID is different from the Application (Client) ID. The Object ID is required for role assignments.

**Throws**: `Exception` if:
- Service Principal cannot be found
- API request fails

## Usage Example

```php
<?php
require_once __DIR__ . '/includes/services/MicrosoftGraphService.php';

try {
    // Initialize the service
    $graphService = new MicrosoftGraphService();
    
    // Invite a new user
    $email = 'newuser@example.com';
    $name = 'New User';
    $redirectUrl = 'https://intra.business-consulting.de/welcome';
    
    $userId = $graphService->inviteUser($email, $name, $redirectUrl);
    echo "User invited successfully. User ID: {$userId}\n";
    
    // Assign a role to the user
    $roleValue = 'mitglied'; // Must match a key in ROLE_MAPPING
    $success = $graphService->assignRole($userId, $roleValue);
    
    if ($success) {
        echo "Role '{$roleValue}' assigned successfully to user {$userId}\n";
    } else {
        echo "Failed to assign role\n";
    }
    
    // Get user profile photo
    $photoData = $graphService->getUserPhoto($userId);
    
    if ($photoData !== null) {
        // Save the photo to a file
        file_put_contents("photos/{$userId}.jpg", $photoData);
        echo "User photo downloaded successfully\n";
    } else {
        echo "No photo available for this user\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Configuration Steps

### 1. Update Role IDs

Before using the service, you must update the `ROLE_MAPPING` constant with your actual Azure App Role IDs:

1. Go to Azure Portal → Azure Active Directory → App Registrations
2. Select your application
3. Go to "App roles" section
4. Copy the ID for each role
5. Update the `ROLE_MAPPING` array in `MicrosoftGraphService.php`

Example:
```php
private const ROLE_MAPPING = [
    'anwaerter' => '12a34567-89bc-def0-1234-56789abcdef0',
    'mitglied' => '98765432-10fe-dcba-9876-543210fedcba',
    // ... etc
];
```

### 2. Verify Environment Variables

Ensure your `.env` file contains the correct Azure credentials:

```bash
# Check if variables are set (run from project root directory)
php -r "require 'config/config.php'; echo AZURE_TENANT_ID . PHP_EOL;"
```

### 3. Test the Service

Create a test script to verify the service works:

```php
<?php
require_once __DIR__ . '/includes/services/MicrosoftGraphService.php';

try {
    $service = new MicrosoftGraphService();
    echo "✓ Service initialized successfully\n";
    echo "✓ Access token obtained\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
```

## Error Handling

All methods throw `Exception` with descriptive error messages when errors occur. Common error scenarios:

1. **Missing Credentials**: "Azure credentials not configured"
2. **Authentication Failure**: "Failed to obtain access token: [details]"
3. **Invalid Role**: "Invalid role value: [role]"
4. **API Errors**: Error messages from Microsoft Graph API

## Security Considerations

1. **Access Tokens**: Access tokens are stored in memory only and are not persisted
2. **Credentials**: Azure credentials are read from environment variables, not hardcoded
3. **HTTPS**: All API calls use HTTPS for secure communication
4. **Error Messages**: Error messages include relevant details but do not expose sensitive data
5. **Caching**: Service Principal ID is cached in memory only (not persisted to disk)

## API Rate Limits

Microsoft Graph API has rate limits. For production use, consider implementing:
- Retry logic with exponential backoff
- Request throttling
- Batch operations for bulk user operations

## Troubleshooting

### Common Issues

1. **"Access token not found in response"**
   - Verify Azure credentials are correct
   - Check if the application has the required permissions
   - Ensure the tenant ID is correct

2. **"Service Principal ID not found"**
   - Verify the Client ID is correct
   - Check if the Service Principal exists in your Azure AD tenant
   - Ensure the application has been granted admin consent

3. **"Failed to assign role"**
   - Verify the role ID in ROLE_MAPPING is correct
   - Check if the user exists in Azure AD
   - Ensure the application has `AppRoleAssignment.ReadWrite.All` permission

### Debug Mode

To see detailed HTTP requests/responses, enable Guzzle debug mode:

```php
// In __construct(), update httpClient initialization:
$this->httpClient = new Client([
    'timeout' => 30,
    'connect_timeout' => 10,
    'debug' => true, // Enable debug output
]);
```

## Related Files

- `includes/services/MicrosoftGraphService.php` - Main service class
- `config/config.php` - Configuration file that loads Azure credentials
- `.env` - Environment variables file (not in repository)

## Future Enhancements

Potential improvements for future versions:

1. **Token Refresh**: Implement token caching and refresh logic
2. **Batch Operations**: Support for bulk user invitations
3. **Error Retry**: Automatic retry with exponential backoff
4. **Logging**: Integration with application logging system
5. **User Management**: Additional methods for user updates, deletion, etc.
6. **Group Management**: Methods for managing Azure AD groups
7. **License Assignment**: Support for assigning licenses to users

## References

- [Microsoft Graph API Documentation](https://docs.microsoft.com/en-us/graph/overview)
- [Invitations API](https://docs.microsoft.com/en-us/graph/api/invitation-post)
- [AppRoleAssignments API](https://docs.microsoft.com/en-us/graph/api/user-post-approleassignments)
- [Azure OAuth 2.0 Client Credentials Flow](https://docs.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-client-creds-grant-flow)
