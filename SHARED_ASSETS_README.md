# Shared Asset Management Implementation

## Overview

This PR implements a comprehensive shared asset management system for b10cks CMS, enabling centralized Digital Asset Management (DAM) with multi-tenant security.

## What's Included

### 🏗️ Architecture & Design
- **Hybrid Approach**: Assets remain in space databases, metadata shared via global database
- **Team-Level Libraries**: Centralized asset organization at team level
- **Permission System**: Flexible access control (library & asset level)
- **Team Hierarchy**: Child teams inherit parent team's shared assets
- **Readonly Access**: Shared assets are readonly for consuming spaces

### 📊 Database Schema (Migration)
- `shared_asset_libraries`: Team-level asset libraries
- `shared_assets`: References to shared assets from spaces
- `shared_asset_permissions`: Access control for shared assets
- Proper indexes and constraints for performance and integrity

### 🔧 Models & Services
- **SharedAssetLibrary**: Team-level library management
- **SharedAsset**: Cross-database asset references with metadata
- **SharedAssetPermission**: Polymorphic permission model
- **SharedAssetService**: Business logic for sharing, permissions, and access control

### 🌐 API Endpoints

#### Team-Level Management
```
GET    /mgmt/teams/{team}/shared-libraries
POST   /mgmt/teams/{team}/shared-libraries
GET    /mgmt/teams/{team}/shared-libraries/{library}
PATCH  /mgmt/teams/{team}/shared-libraries/{library}
DELETE /mgmt/teams/{team}/shared-libraries/{library}

GET    /mgmt/teams/{team}/shared-libraries/{library}/assets
POST   /mgmt/teams/{team}/shared-libraries/{library}/assets
GET    /mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}
PATCH  /mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}
DELETE /mgmt/teams/{team}/shared-libraries/{library}/assets/{asset}
```

#### Space-Level Access
```
GET /mgmt/spaces/{space}/shared-assets
```

### 📚 Documentation
- **Architecture Document**: Complete design with alternatives and rationale
- **Usage Guide**: Practical examples and API reference
- **Implementation Summary**: Overview of what's included and how it works
- **Troubleshooting Guide**: Common issues and solutions

## Key Features

✅ **Centralized DAM**: Team-level asset libraries for company-wide assets  
✅ **Asset Sharing**: Share from any space into team libraries  
✅ **Access Control**: Library and asset-level permissions  
✅ **Team Hierarchy**: Parent teams share with child teams  
✅ **Readonly Access**: Consumers can't modify shared assets  
✅ **Multi-Tenancy**: Space database isolation preserved  
✅ **Performance**: Caching and optimized queries  
✅ **Backward Compatible**: No breaking changes  

## Usage Example

```php
// 1. Create a shared library
$library = SharedAssetLibrary::create([
    'team_id' => $team->id,
    'name' => 'Brand Assets',
    'slug' => 'brand-assets',
    'is_default' => true,
]);

// 2. Share an asset
$sharedAssetService = app(SharedAssetService::class);
$sharedAsset = $sharedAssetService->shareAsset(
    $asset,
    $library,
    'Company Logo'
);

// 3. Grant access to team
$sharedAssetService->grantPermission(
    library: $library,
    accessorType: Team::class,
    accessorId: $team->id,
    permission: 'view'
);

// 4. Access from space
$sharedAssets = $sharedAssetService->getSharedAssets($space);
```

## Installation & Setup

### 1. Run Migrations
```bash
php artisan migrate
```

This creates the three new tables in the global database.

### 2. No Additional Configuration Required
The shared asset system is opt-in and doesn't affect existing functionality.

### 3. Start Using
- Create shared libraries for your teams
- Share assets you want centralized
- Grant permissions to spaces/teams
- Access shared assets from any authorized space

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                      Global Database                         │
├─────────────────────────────────────────────────────────────┤
│  teams                                                        │
│  ├─> shared_asset_libraries (team_id)                       │
│  │    └─> shared_assets (library_id, source_space_id, ...)  │
│  │        └─> shared_asset_permissions (accessor_*)          │
│  └─> spaces (team_id)                                        │
└─────────────────────────────────────────────────────────────┘
                               │
                               │ references
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                     Space Database                           │
├─────────────────────────────────────────────────────────────┤
│  assets (original asset storage)                             │
│  asset_folders                                               │
│  asset_tags                                                  │
│  [other space-specific tables...]                            │
└─────────────────────────────────────────────────────────────┘
```

## Security Model

### Multi-Tenant Isolation
- Assets remain in space databases (existing isolation)
- Shared asset metadata in global database (team-scoped)
- Permission checks on every access
- Cross-team access requires explicit permissions

### Access Control Layers
1. **Authentication**: User must be authenticated
2. **Team Membership**: User must belong to team/space
3. **Permission Check**: SharedAssetPermission validation
4. **Team Hierarchy**: Parent-child relationship verification

### Readonly Enforcement
- Shared assets cannot be modified by consumers
- Original space retains full control
- Updates to original asset propagate automatically

## Performance Optimization

### Caching
- Permission checks cached (5 minutes)
- Team hierarchy cached
- Shared library listings cached

### Database Optimization
- Indexes on all foreign keys
- Composite indexes for common queries
- Efficient team hierarchy traversal

### Query Optimization
- Eager loading of relationships
- Pagination support on all list endpoints
- Efficient permission lookups

## What's NOT Included (Future Work)

### Testing
- [ ] Unit tests for models
- [ ] Service layer tests
- [ ] API endpoint tests
- [ ] Integration tests

### Middleware
- [ ] `EnsureCanAccessSharedAsset` middleware
- [ ] `PreventSharedAssetModification` middleware
- [ ] Rate limiting for shared assets

### Advanced Features
- [ ] Asset versioning
- [ ] Asset collections
- [ ] Usage analytics dashboard
- [ ] Approval workflows
- [ ] License tracking
- [ ] Asset expiration
- [ ] Local asset synchronization

### UI Components
- [ ] Admin interface for library management
- [ ] Asset browser with shared assets
- [ ] Permission management UI
- [ ] Usage statistics dashboard

## Testing Recommendations

Before merging, recommend adding:

1. **Model Tests**
   - Test relationships
   - Test helper methods
   - Test permission logic

2. **Service Tests**
   - Test asset sharing
   - Test permission granting
   - Test team hierarchy resolution

3. **API Tests**
   - Test CRUD operations
   - Test permission enforcement
   - Test edge cases

4. **Integration Tests**
   - Test cross-space asset access
   - Test team inheritance
   - Test permission caching

## Migration Path for Existing Installations

### Opt-In Approach (Recommended)
1. Run migrations (non-destructive)
2. No changes to existing assets
3. Teams can start creating shared libraries
4. Users manually share assets as needed
5. Gradual adoption with no disruption

### No Migration Required
- Existing assets continue working unchanged
- No data needs to be moved
- System is additive only

## Documentation Files

1. **`docs/architecture/SHARED_ASSET_MANAGEMENT.md`**
   - Complete architectural design
   - Alternative approaches considered
   - Security and performance considerations

2. **`docs/architecture/SHARED_ASSET_USAGE.md`**
   - Usage guide with examples
   - API reference
   - Best practices
   - Troubleshooting

3. **`docs/architecture/IMPLEMENTATION_SUMMARY.md`**
   - What has been implemented
   - How it works
   - Integration points
   - Next steps

## Benefits

### For Companies
- ✅ Centralized brand asset management
- ✅ Consistent asset usage across projects
- ✅ Reduced storage duplication
- ✅ Better governance and control

### For Teams
- ✅ Easy access to company assets
- ✅ No need to duplicate assets
- ✅ Automatic updates to shared assets
- ✅ Clear asset ownership

### For Developers
- ✅ Minimal code changes required
- ✅ Clean, maintainable architecture
- ✅ Well-documented APIs
- ✅ Backward compatible

## Support & Questions

- **Architecture Questions**: See `docs/architecture/SHARED_ASSET_MANAGEMENT.md`
- **Usage Examples**: See `docs/architecture/SHARED_ASSET_USAGE.md`
- **Implementation Details**: See `docs/architecture/IMPLEMENTATION_SUMMARY.md`
- **Issues**: Open a GitHub issue
- **Discussions**: GitHub Discussions or Discord

## License

This implementation follows the project's existing AGPL-3.0-or-later license.
