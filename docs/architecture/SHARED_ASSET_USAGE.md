# Shared Asset Management - Usage Guide

This guide provides practical examples of how to use the shared asset management system in the b10cks CMS.

## Table of Contents

1. [Overview](#overview)
2. [Setup](#setup)
3. [Creating a Shared Asset Library](#creating-a-shared-asset-library)
4. [Sharing Assets](#sharing-assets)
5. [Granting Access Permissions](#granting-access-permissions)
6. [Accessing Shared Assets](#accessing-shared-assets)
7. [Team Hierarchy and Inheritance](#team-hierarchy-and-inheritance)
8. [API Reference](#api-reference)

## Overview

The shared asset management system allows:
- Companies to create team-level asset libraries (centralized DAM)
- Sharing assets from any space into team libraries
- Controlling access at team, space, and user levels
- Child teams inheriting parent team's shared assets
- Readonly access to shared assets from consuming spaces

## Setup

### Prerequisites

1. Run migrations to create shared asset tables:
```bash
php artisan migrate
```

2. Ensure your team and space structures are set up correctly.

## Creating a Shared Asset Library

### Create a Default Brand Assets Library

**Request:**
```http
POST /mgmt/teams/{team_id}/shared-libraries
Content-Type: application/json
Authorization: Bearer {token}

{
  "name": "Brand Assets",
  "slug": "brand-assets",
  "description": "Official brand logos, colors, and guidelines",
  "icon": "palette",
  "color": "#0066CC",
  "is_default": true,
  "settings": {
    "allow_downloads": true,
    "require_attribution": true
  }
}
```

**Response:**
```json
{
  "data": {
    "id": "01HQWXYZ...",
    "team_id": "01HQWXYZ...",
    "name": "Brand Assets",
    "slug": "brand-assets",
    "description": "Official brand logos, colors, and guidelines",
    "icon": "palette",
    "color": "#0066CC",
    "is_default": true,
    "settings": {
      "allow_downloads": true,
      "require_attribution": true
    },
    "created_at": "2024-01-15T10:00:00Z",
    "updated_at": "2024-01-15T10:00:00Z"
  }
}
```

### List All Libraries for a Team

**Request:**
```http
GET /mgmt/teams/{team_id}/shared-libraries
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": "01HQWXYZ...",
      "name": "Brand Assets",
      "slug": "brand-assets",
      "is_default": true,
      "shared_assets_count": 25
    },
    {
      "id": "01HQWXYZ...",
      "name": "Product Images",
      "slug": "product-images",
      "is_default": false,
      "shared_assets_count": 150
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 2
  }
}
```

## Sharing Assets

### Share an Asset from a Space into a Library

First, upload an asset to a space, then share it:

**Request:**
```http
POST /mgmt/teams/{team_id}/shared-libraries/{library_id}/assets
Content-Type: application/json
Authorization: Bearer {token}
X-Space: {space_id}

{
  "asset_id": "01HQWXYZ...",
  "shared_name": "Primary Logo",
  "shared_description": "Use this logo on white backgrounds",
  "shared_tags": ["logo", "primary", "brand"],
  "shared_metadata": {
    "usage_guidelines": "Minimum size 100px width",
    "approved_by": "Marketing Team",
    "version": "2.0"
  }
}
```

**Response:**
```json
{
  "data": {
    "id": "01HQWXYZ...",
    "library_id": "01HQWXYZ...",
    "source_space_id": "01HQWXYZ...",
    "source_asset_id": "01HQWXYZ...",
    "shared_name": "Primary Logo",
    "shared_description": "Use this logo on white backgrounds",
    "shared_tags": ["logo", "primary", "brand"],
    "shared_metadata": {
      "usage_guidelines": "Minimum size 100px width",
      "approved_by": "Marketing Team",
      "version": "2.0"
    },
    "display_name": "Primary Logo",
    "access_count": 0,
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

### List Shared Assets in a Library

**Request:**
```http
GET /mgmt/teams/{team_id}/shared-libraries/{library_id}/assets
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": "01HQWXYZ...",
      "shared_name": "Primary Logo",
      "display_name": "Primary Logo",
      "source_space_id": "01HQWXYZ...",
      "access_count": 42,
      "last_accessed_at": "2024-01-20T14:30:00Z"
    }
  ]
}
```

### Unshare an Asset

**Request:**
```http
DELETE /mgmt/teams/{team_id}/shared-libraries/{library_id}/assets/{shared_asset_id}
Authorization: Bearer {token}
```

**Response:**
```http
204 No Content
```

## Granting Access Permissions

### Grant Library-Level Access to a Space

This allows all assets in the library to be viewed by the space:

**PHP Example:**
```php
use App\Services\Storage\SharedAssetService;
use App\Models\Management\SharedAssetLibrary;
use App\Models\Management\Space;
use App\Models\Management\SharedAssetPermission;

$sharedAssetService = app(SharedAssetService::class);

$library = SharedAssetLibrary::find('01HQWXYZ...');
$space = Space::find('01HQWXYZ...');

$permission = $sharedAssetService->grantPermission(
    library: $library,
    accessorType: Space::class,
    accessorId: $space->id,
    permission: SharedAssetPermission::PERMISSION_VIEW
);
```

### Grant Library-Level Access to a Team

This allows all spaces in the team to access the library:

**PHP Example:**
```php
use App\Models\Management\Team;

$team = Team::find('01HQWXYZ...');

$permission = $sharedAssetService->grantPermission(
    library: $library,
    accessorType: Team::class,
    accessorId: $team->id,
    permission: SharedAssetPermission::PERMISSION_USE
);
```

### Grant Asset-Level Access

Grant access to a specific asset only:

**PHP Example:**
```php
use App\Models\Management\SharedAsset;

$sharedAsset = SharedAsset::find('01HQWXYZ...');

$permission = $sharedAssetService->grantPermission(
    sharedAsset: $sharedAsset,
    accessorType: Space::class,
    accessorId: $space->id,
    permission: SharedAssetPermission::PERMISSION_DOWNLOAD
);
```

### Grant Time-Limited Access

**PHP Example:**
```php
$permission = $sharedAssetService->grantPermission(
    library: $library,
    accessorType: Space::class,
    accessorId: $space->id,
    permission: SharedAssetPermission::PERMISSION_VIEW,
    conditions: [
        'expires_at' => now()->addMonths(3),
        'max_downloads' => 100
    ]
);
```

## Accessing Shared Assets

### View Shared Assets Accessible by a Space

**Request:**
```http
GET /mgmt/spaces/{space_id}/shared-assets
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "id": "01HQWXYZ...",
      "library_id": "01HQWXYZ...",
      "shared_name": "Primary Logo",
      "display_name": "Primary Logo",
      "library": {
        "id": "01HQWXYZ...",
        "name": "Brand Assets",
        "team_id": "01HQWXYZ..."
      },
      "source_space": {
        "id": "01HQWXYZ...",
        "name": "Marketing Space"
      }
    }
  ]
}
```

### Filter by Specific Library

**Request:**
```http
GET /mgmt/spaces/{space_id}/shared-assets?library_id={library_id}
Authorization: Bearer {token}
```

### Get Shared Asset with Source Asset Details

**Request:**
```http
GET /mgmt/teams/{team_id}/shared-libraries/{library_id}/assets/{shared_asset_id}?include_source_asset=true
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "id": "01HQWXYZ...",
    "shared_name": "Primary Logo",
    "source_asset": {
      "id": "01HQWXYZ...",
      "filename": "logo-primary.svg",
      "extension": "svg",
      "mime_type": "image/svg+xml",
      "size": 12458,
      "metadata": {
        "width": 500,
        "height": 200
      }
    }
  }
}
```

### Check Access Permission Programmatically

**PHP Example:**
```php
use App\Models\User;

$user = auth()->user();
$space = request('space');
$team = $space->team;

$canAccess = $sharedAssetService->canAccessSharedAsset(
    sharedAsset: $sharedAsset,
    user: $user,
    space: $space,
    team: $team,
    permission: SharedAssetPermission::PERMISSION_VIEW
);

if ($canAccess) {
    // User can view the asset
    $sharedAsset->recordAccess();
}
```

## Team Hierarchy and Inheritance

### Scenario: Parent Company with Sub-brands

```
Company (Parent Team)
├── Shared Library: "Corporate Brand Assets"
├── Marketing Space
└── Child Teams:
    ├── Brand A (Child Team)
    │   └── Brand A Website (Space)
    └── Brand B (Child Team)
        └── Brand B App (Space)
```

### Setup Parent Library

**PHP Example:**
```php
// Create library for parent company
$parentTeam = Team::where('name', 'Company')->first();

$corporateLibrary = SharedAssetLibrary::create([
    'team_id' => $parentTeam->id,
    'name' => 'Corporate Brand Assets',
    'slug' => 'corporate-brand',
    'is_default' => true,
]);

// Grant access to parent team (all child teams inherit)
$sharedAssetService->grantPermission(
    library: $corporateLibrary,
    accessorType: Team::class,
    accessorId: $parentTeam->id,
    permission: SharedAssetPermission::PERMISSION_VIEW,
    inherited: false
);
```

### Access from Child Team Space

**PHP Example:**
```php
// From Brand A Website space
$brandASpace = Space::where('name', 'Brand A Website')->first();
$brandATeam = $brandASpace->team;

// Get inherited libraries (includes parent's libraries)
$inheritedLibraries = $sharedAssetService->getInheritedLibraries($brandATeam);
// Returns: ["Corporate Brand Assets", ...]

// Get all accessible shared assets (includes inherited)
$accessibleAssets = $sharedAssetService->getSharedAssets($brandASpace);
// Returns all assets from parent and current team libraries
```

## API Reference

### Shared Asset Library Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/mgmt/teams/{team}/shared-libraries` | List libraries |
| POST | `/mgmt/teams/{team}/shared-libraries` | Create library |
| GET | `/mgmt/teams/{team}/shared-libraries/{library}` | Get library |
| PATCH | `/mgmt/teams/{team}/shared-libraries/{library}` | Update library |
| DELETE | `/mgmt/teams/{team}/shared-libraries/{library}` | Delete library |

### Shared Asset Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/mgmt/teams/{team}/shared-libraries/{library}/assets` | List shared assets |
| POST | `/mgmt/teams/{team}/shared-libraries/{library}/assets` | Share asset |
| GET | `/mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}` | Get shared asset |
| PATCH | `/mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}` | Update shared asset |
| DELETE | `/mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}` | Unshare asset |

### Space Access Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/mgmt/spaces/{space}/shared-assets` | Get accessible shared assets |

### Permission Types

- `view`: Can view asset details
- `use`: Can use asset in content
- `download`: Can download asset file

## Best Practices

1. **Library Organization**: Create separate libraries for different asset types (logos, photos, documents)
2. **Naming Conventions**: Use clear, descriptive names for shared assets
3. **Metadata**: Add comprehensive metadata to help users find and use assets correctly
4. **Permissions**: Start with restrictive permissions and grant as needed
5. **Team Hierarchy**: Use parent teams for company-wide assets
6. **Monitoring**: Track access_count to understand asset usage
7. **Cleanup**: Regularly review and remove unused shared assets

## Troubleshooting

### Asset Not Appearing in Shared Assets

1. Check if asset is in a library accessible to the space
2. Verify permissions are granted (library or asset level)
3. Check team hierarchy for inheritance issues
4. Clear cache if using cached permission checks

### Cannot Access Source Asset

1. Ensure source space connection is valid
2. Check if source asset still exists
3. Verify source asset is not soft-deleted

### Performance Issues

1. Enable caching for permission checks
2. Use pagination for large libraries
3. Add database indexes if querying large datasets
4. Consider using eager loading for relationships

## Support

For additional help:
- GitHub Issues: https://github.com/b10cks/cms/issues
- Documentation: https://docs.b10cks.com
- Discord: https://discord.gg/UT6GrvhvBx
