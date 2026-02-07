# Shared Asset Management Architecture

## Executive Summary

This document outlines the architectural design for implementing shared asset management across spaces within a team context in the b10cks CMS. The solution enables centralized Digital Asset Management (DAM) while maintaining multi-tenant isolation and security.

## Current Architecture Analysis

### Multi-Tenancy Model

The current architecture implements a **database-per-space** multi-tenancy model:

1. **Team Level**: Teams are stored in the global database with support for hierarchical structures (parent-child relationships)
2. **Space Level**: Each space belongs to a team and has its own database connection
3. **Asset Storage**: Assets are stored per-space in the `assets` table within each space's database

```
Global Database:
├── teams (with parent_id for hierarchy)
├── spaces (with team_id)
├── space_connections (database config per space)
└── storages (file storage config per space)

Space Database (separate per space):
├── assets
├── asset_folders
├── asset_tags
└── [other space-specific tables]
```

### Current Asset Model

- **Location**: `app/Models/Space/Asset.php`
- **Database**: Space-specific database (extends `SpaceModel`)
- **Storage**: References global `Storage` model with file location
- **Relationships**:
  - `storage`: BelongsTo Storage (global)
  - `folder`: BelongsTo AssetFolder (space)

### Limitations

1. Assets cannot be shared between spaces
2. Each space must duplicate common brand assets
3. No centralized DAM functionality
4. Storage redundancy across sub-brands/projects

## Requirements

### Functional Requirements

1. **Centralized Asset Library**: Companies should have a team-level asset library
2. **Readonly Access**: Sub-brands and projects can access shared assets in readonly mode
3. **Hierarchical Inheritance**: Child teams inherit parent team's shared assets
4. **Space-Level Access**: Spaces can reference and use shared assets
5. **Permission Control**: Fine-grained permissions for asset sharing
6. **Multi-Tenancy**: Maintain strict tenant isolation for security

### Non-Functional Requirements

1. **Performance**: Minimal performance impact on asset retrieval
2. **Backward Compatibility**: Existing space-specific assets continue to work
3. **Scalability**: Support large asset libraries (100k+ assets)
4. **Security**: Prevent unauthorized cross-team access

## Proposed Solution: Hybrid Approach

### Architecture Overview

We propose a **hybrid model** that combines global shared asset libraries with space-specific assets:

```
Global Database:
├── teams
├── spaces
├── shared_asset_libraries (team-level)
├── shared_assets (references to space assets)
└── shared_asset_permissions

Space Databases:
└── assets (original asset storage, unchanged)
```

### Solution Components

#### 1. Shared Asset Library (Team Level)

**Model**: `SharedAssetLibrary` (Global Database)

```php
Schema::create('shared_asset_libraries', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
    
    $table->string('name', 100);
    $table->string('slug', 50)->charset('ascii');
    $table->text('description')->nullable();
    $table->string('icon', 50)->nullable();
    $table->char('color', 7)->nullable();
    
    $table->boolean('is_default')->default(false);
    $table->json('settings')->nullable();
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['team_id', 'slug']);
});
```

**Purpose**: Organizes shared assets at the team level. Teams can have multiple libraries (e.g., "Brand Assets", "Product Images", "Marketing Materials").

#### 2. Shared Asset (Cross-Reference Model)

**Model**: `SharedAsset` (Global Database)

```php
Schema::create('shared_assets', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('library_id')->constrained('shared_asset_libraries')->cascadeOnDelete();
    
    // Reference to original asset in space database
    $table->foreignUlid('source_space_id')->constrained('spaces')->cascadeOnDelete();
    $table->ulid('source_asset_id'); // Asset ID in source space's database
    
    // Optional overrides for shared context
    $table->string('shared_name', 100)->nullable();
    $table->text('shared_description')->nullable();
    $table->json('shared_tags')->nullable();
    $table->json('shared_metadata')->nullable();
    
    $table->integer('access_count')->default(0);
    $table->timestamp('last_accessed_at')->nullable();
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->unique(['library_id', 'source_space_id', 'source_asset_id']);
    $table->index(['source_space_id', 'source_asset_id']);
});
```

**Purpose**: Acts as a reference/pointer to assets that live in space databases. Tracks which assets are shared and provides shared-context metadata.

#### 3. Shared Asset Permissions

**Model**: `SharedAssetPermission` (Global Database)

```php
Schema::create('shared_asset_permissions', function (Blueprint $table) {
    $table->ulid('id')->primary();
    
    // What is being shared
    $table->foreignUlid('library_id')->nullable()->constrained('shared_asset_libraries')->cascadeOnDelete();
    $table->foreignUlid('shared_asset_id')->nullable()->constrained('shared_assets')->cascadeOnDelete();
    
    // Who can access it
    $table->nullableUlidMorphs('accessor'); // team_id, space_id, or user_id
    
    $table->string('permission', 50)->charset('ascii'); // 'view', 'use', 'download'
    $table->boolean('inherited')->default(false); // Inherited from parent team
    
    $table->json('conditions')->nullable(); // Additional access conditions
    
    $table->timestamps();
    
    $table->index(['library_id', 'accessor_type', 'accessor_id']);
});
```

**Purpose**: Controls who can access shared assets. Supports library-level and individual asset-level permissions.

## Implementation Approach

### Phase 1: Foundation (Models & Migrations)

1. Create migrations for new tables
2. Create `SharedAssetLibrary`, `SharedAsset`, `SharedAssetPermission` models
3. Establish relationships between models
4. Add helper methods for permission checking

### Phase 2: Service Layer

1. Create `SharedAssetService` for business logic:
   - `shareAsset(Asset $asset, SharedAssetLibrary $library): SharedAsset`
   - `getSharedAssets(Team $team, ?Space $space): Collection`
   - `canAccessSharedAsset(SharedAsset $asset, User $user, Space $space): bool`
   - `getInheritedLibraries(Team $team): Collection`

2. Update `AssetService` to handle shared assets:
   - Check both space-specific and shared assets
   - Merge results in asset listing APIs

### Phase 3: API Layer

1. New Endpoints:
   ```
   GET    /mgmt/teams/{team}/shared-libraries
   POST   /mgmt/teams/{team}/shared-libraries
   GET    /mgmt/teams/{team}/shared-libraries/{library}
   PATCH  /mgmt/teams/{team}/shared-libraries/{library}
   DELETE /mgmt/teams/{team}/shared-libraries/{library}
   
   POST   /mgmt/shared-libraries/{library}/assets
   GET    /mgmt/shared-libraries/{library}/assets
   DELETE /mgmt/shared-libraries/{library}/assets/{asset}
   
   POST   /mgmt/shared-assets/{asset}/permissions
   GET    /mgmt/shared-assets/{asset}/permissions
   ```

2. Enhanced Endpoints:
   ```
   GET /mgmt/spaces/{space}/assets?include_shared=true
   GET /mgmt/spaces/{space}/assets/{asset}?source=shared
   ```

### Phase 4: Access Control & Security

1. Implement permission resolver that:
   - Checks direct space access to space assets
   - Checks shared library permissions for team assets
   - Respects team hierarchy for inherited access
   - Enforces readonly restrictions on shared assets

2. Add middleware for shared asset access:
   - `EnsureCanAccessSharedAsset`
   - `PreventSharedAssetModification`

### Phase 5: UI & Documentation

1. Update API documentation
2. Add usage examples
3. Create migration guide for existing assets

## Key Design Decisions

### Decision 1: Hybrid Storage Model

**Choice**: Keep assets in space databases, use global database for sharing metadata

**Rationale**:
- ✅ Maintains existing asset storage architecture
- ✅ No data migration required for existing assets
- ✅ Preserves space database isolation
- ✅ Minimal changes to existing code
- ✅ Supports gradual adoption

**Alternatives Considered**:
- Moving assets to global database (breaks multi-tenancy model)
- Creating separate shared asset database (adds complexity)

### Decision 2: Permission Model

**Choice**: Flexible permission model supporting library and individual asset permissions

**Rationale**:
- ✅ Supports both broad sharing (whole library) and specific sharing (single assets)
- ✅ Can implement inheritance through team hierarchy
- ✅ Extensible for future permission types
- ✅ Allows fine-grained access control

### Decision 3: Readonly by Default

**Choice**: Shared assets are readonly for consuming spaces

**Rationale**:
- ✅ Prevents unintended modifications affecting multiple spaces
- ✅ Original asset owner retains control
- ✅ Clear ownership and responsibility model
- ✅ Simpler permission model

### Decision 4: Reference Model (Not Copy)

**Choice**: SharedAsset references original asset, doesn't duplicate it

**Rationale**:
- ✅ Single source of truth
- ✅ No storage duplication
- ✅ Updates propagate automatically
- ✅ Consistent asset versions across spaces

## Security Considerations

### Multi-Tenancy Isolation

1. **Database Separation**: Space assets remain in space databases
2. **Permission Checks**: Every shared asset access validates permissions
3. **Team Boundaries**: Cross-team access requires explicit permissions
4. **Audit Trail**: Log all shared asset access

### Access Control

1. **Authentication**: User must be authenticated
2. **Team Membership**: User must belong to team or space
3. **Permission Validation**: Check SharedAssetPermission records
4. **Inheritance Validation**: Verify team hierarchy for inherited access

### Data Protection

1. **Readonly Enforcement**: Prevent modifications to shared assets from consumer spaces
2. **Soft Deletes**: Track asset removal without breaking references
3. **Access Logging**: Monitor who accesses shared assets

## Performance Considerations

### Optimization Strategies

1. **Caching**:
   - Cache permission checks (per-user, per-asset)
   - Cache shared library listings
   - Cache team hierarchy for inheritance resolution

2. **Indexing**:
   - Index `shared_assets` by library and source asset
   - Index `shared_asset_permissions` by accessor
   - Composite indexes for common queries

3. **Query Optimization**:
   - Eager load relationships
   - Use pagination for large libraries
   - Implement cursor-based pagination for better performance

4. **Database Strategy**:
   - Keep shared asset metadata minimal
   - Use database connections efficiently
   - Consider read replicas for shared asset queries

## Migration Strategy

### For Existing Assets

Option 1: **Opt-in Sharing**
- Existing assets remain space-specific
- Teams manually share assets they want centralized
- Gradual adoption path

Option 2: **Automated Migration**
- Provide migration tool to bulk-share existing assets
- Team administrators review and approve
- One-time migration event

**Recommendation**: Start with Option 1 (opt-in) to minimize disruption.

### Backward Compatibility

1. All existing APIs continue to work unchanged
2. Shared asset features are additive only
3. No breaking changes to existing asset models
4. Optional feature flags for gradual rollout

## Alternative Approaches Considered

### Alternative 1: Global Asset Database

**Description**: Store all assets in a global database with team ownership.

**Pros**:
- Simpler architecture
- Easier to query across all assets
- Natural sharing model

**Cons**:
- ❌ Breaks multi-tenancy model
- ❌ Requires migration of all existing assets
- ❌ Single database becomes bottleneck
- ❌ Security risks with shared database

### Alternative 2: Asset Replication

**Description**: Copy shared assets to each consuming space's database.

**Pros**:
- No cross-database queries
- Space isolation maintained

**Cons**:
- ❌ Storage duplication
- ❌ Version management complexity
- ❌ Update propagation issues
- ❌ Inconsistent asset versions

### Alternative 3: Federated Asset Service

**Description**: Create separate microservice for asset management.

**Pros**:
- Independent scaling
- Service isolation
- Technology flexibility

**Cons**:
- ❌ Significant architectural change
- ❌ Operational complexity
- ❌ Not aligned with monolithic architecture
- ❌ Requires service orchestration

## Implementation Roadmap

### Phase 1: Core Foundation (Week 1-2)
- [ ] Create database migrations
- [ ] Implement core models
- [ ] Write unit tests for models

### Phase 2: Business Logic (Week 3-4)
- [ ] Implement SharedAssetService
- [ ] Add permission checking logic
- [ ] Implement team hierarchy inheritance
- [ ] Write service layer tests

### Phase 3: API Development (Week 5-6)
- [ ] Create API controllers
- [ ] Add API routes
- [ ] Implement request validation
- [ ] Write API tests

### Phase 4: Integration (Week 7-8)
- [ ] Update existing asset endpoints
- [ ] Add shared asset filtering
- [ ] Implement caching layer
- [ ] Performance testing

### Phase 5: Documentation & Launch (Week 9-10)
- [ ] Update API documentation
- [ ] Create migration guides
- [ ] Write usage examples
- [ ] Deploy to production

## Success Metrics

1. **Adoption**: % of teams using shared asset libraries
2. **Storage Efficiency**: Reduction in duplicate asset storage
3. **Performance**: Asset retrieval latency < 100ms
4. **Reliability**: Zero security incidents related to asset sharing
5. **User Satisfaction**: Positive feedback from teams using shared assets

## Future Enhancements

1. **Asset Versioning**: Track versions of shared assets
2. **Asset Collections**: Curated collections of shared assets
3. **Asset Analytics**: Usage analytics for shared assets
4. **Asset Approval Workflow**: Approval process before sharing
5. **Asset Licensing**: License tracking for shared assets
6. **Asset Expiration**: Time-limited asset sharing
7. **Asset Synchronization**: Optional local copies with sync

## Conclusion

The proposed hybrid approach for shared asset management:

1. ✅ Maintains multi-tenant database isolation
2. ✅ Enables centralized DAM functionality
3. ✅ Supports team hierarchy inheritance
4. ✅ Provides readonly access to shared assets
5. ✅ Requires minimal changes to existing architecture
6. ✅ Is backward compatible
7. ✅ Scales with the platform

This solution balances the need for centralized asset management with the security and isolation requirements of a multi-tenant SaaS platform.
