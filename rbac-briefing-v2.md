# Authorization and RBAC briefing — CMS final
**Version 4**
**Status:** implementation-ready
**Validated against codebase:** 2026-03-12

---

## Executive summary

This version is the final implementation brief for introducing a proper `roles`
table into the CMS without breaking the current Laravel and Vue application.

The CMS today authorizes through:

- `team_user.role` string values
- `space_user.role` string values
- Laravel policies with inline role checks
- `users.is_root` as a super-admin override

That model is already showing drift:

- role names are duplicated across backend and frontend
- policies encode authorization logic inconsistently
- invites accept arbitrary role strings today
- space invite acceptance writes a synthetic `team_user.role = "space"` record
- there is no centralized ability resolver

The implementation path in this document introduces a `roles` table as the new
source of truth and performs a single cutover away from legacy string roles.

The implementation release must:

- add a `roles` table
- add `role_id` foreign keys to memberships and invites
- seed built-in team and space roles into `roles`
- backfill all valid legacy string roles to `role_id`
- remove legacy string role reads and writes in application code
- remove legacy `role` columns once the backfill succeeds
- centralize effective ability resolution in one service
- keep external APIs stable by accepting role keys and resolving them to role records

Because the system is still in early development, this is the correct choice.
It avoids carrying migration complexity, dual-write drift, and legacy fallback
paths into the long-term codebase.

---

## What is true in the CMS today

These points are validated from the current repository and define the migration
baseline:

- Team membership is stored on `team_user.role`
- Space membership is stored on `space_user.role`
- Teams are hierarchical via `teams.parent_id`
- Authorization does **not** currently resolve recursive inherited roles through
  the team tree
- Space access is effectively the union of direct `space_user` membership and
  selected direct membership on the owning team
- Laravel policies are registered in `App\Providers\AuthServiceProvider`
- The frontend is a Vue 3 SPA in `resources/js`, not a Nuxt app
- `/mgmt/v1/users/me` returns the authenticated user, but no authorization payload
- Subscriptions are per-space, not per-team
- The `permissions` table exists but is not part of active RBAC enforcement
- Space invite acceptance currently writes both a `space_user` membership and a
  synthetic `team_user.role = "space"` marker

The implementation must respect those realities while moving authorization onto
role records.

---

## Target architecture

### Core principle

Role assignments reference a role record by `role_id`. The role record defines:

- scope
- key
- display metadata
- precedence level
- system/custom ownership
- allowed abilities

Membership rows stop being the place where role semantics live. They become
assignment records only.

### Scope model

Two role scopes exist:

- `team`
- `space`

### Initial implementation constraint

The first production release of the `roles` table supports:

- built-in system team roles
- built-in system space roles
- custom team-defined space roles

That means:

- seed system team roles
- seed system space roles
- allow team-defined custom space roles in v1
- keep role keys stable across environments

---

## Final role model

### Team roles

System team roles:

| Key | Meaning | Level |
|---|---|---:|
| `owner` | Full control of the team, members, invites, child teams, and spaces | 300 |
| `admin` | Manage team members, invites, and create spaces under the team | 200 |
| `member` | Basic team visibility only | 100 |

Legacy-only team role keys:

| Key | Status |
|---|---|
| `guest` | deprecated; migrate to `member` or remove |
| `space` | invalid synthetic role created by current code; must be eliminated from writes |

### Space roles

System space roles:

| Key | Meaning | Level |
|---|---|---:|
| `owner` | Full control of the space, settings, people, tokens, billing, archive, and delete | 300 |
| `admin` | Administrative control of the space except destructive ownership-only actions | 200 |
| `editor` | Editorial and structured-content management | 150 |
| `member` | Collaborative access for content and comments | 120 |
| `viewer` | Read-only space access | 100 |

Custom space roles:

- are scoped to `scope = space`
- belong to the defining team via `roles.team_id`
- are available to that team and all descendant teams and spaces
- must not shadow a system role key within the same scope
- may define any subset of valid space abilities
- inherit all generic resolver and cache behavior described in this brief

### Level usage

`level` exists for:

- future assignment ceiling checks
- sorting roles in UIs
- deterministic conflict handling if needed later

The initial release does **not** implement arbitrary role hierarchy logic beyond
using `level` as metadata.

---

## Ability model

### Storage choice

Abilities are stored as a JSON array on the `roles` table.

Example:

```json
[
  "space.view",
  "content.view",
  "content.manage",
  "content.publish"
]
```

Why this is the right initial design:

- no `role_permissions` join table is needed for the first release
- ability resolution stays simple and cacheable
- roles are inspectable in one row
- the current application checks permissions in PHP, not SQL
- later custom roles can edit the same JSON structure without schema changes

### Ability catalog

Use flat string abilities. They are the API contract between role records,
policies, and the frontend.

#### Team abilities

```text
team.view
team.update
team.delete
team.members.view
team.members.manage
team.invites.view
team.invites.manage
team.spaces.create
team.children.view
team.children.manage
```

#### Space abilities

```text
space.view
space.update
space.archive
space.delete
space.members.view
space.members.manage
space.invites.view
space.invites.manage
space.billing.view
space.billing.manage
space.tokens.view
space.tokens.manage
assets.view
assets.manage
asset_folders.view
asset_folders.manage
asset_tags.view
asset_tags.manage
blocks.view
blocks.manage
block_templates.view
block_templates.manage
block_versions.view
block_versions.manage
content.view
content.manage
content.publish
content.history.view
comments.view
comments.create
comments.update_own
comments.delete_own
comments.resolve_own
comments.react
data_sources.view
data_sources.manage
data_entries.view
data_entries.manage
redirects.view
redirects.manage
releases.view
releases.manage
releases.publish
backups.view
backups.manage
migrations.view
migrations.manage
ai.view
ai.manage
```

---

## Role matrix

### Team role abilities

| Ability | owner | admin | member |
|---|---:|---:|---:|
| `team.view` | yes | yes | yes |
| `team.update` | yes | yes | no |
| `team.delete` | yes | no | no |
| `team.members.view` | yes | yes | no |
| `team.members.manage` | yes | yes | no |
| `team.invites.view` | yes | yes | no |
| `team.invites.manage` | yes | yes | no |
| `team.spaces.create` | yes | yes | no |
| `team.children.view` | yes | yes | no |
| `team.children.manage` | yes | yes | no |

### Space role abilities

| Ability group | owner | admin | editor | member | viewer |
|---|---:|---:|---:|---:|---:|
| `space.view` | yes | yes | yes | yes | yes |
| `space.update` | yes | yes | no | no | no |
| `space.archive`, `space.delete` | yes | no | no | no | no |
| `space.members.*`, `space.invites.*` | yes | yes | no | no | no |
| `space.billing.*` | yes | yes | no | no | no |
| `space.tokens.*` | yes | yes | no | no | no |
| `assets.*`, `asset_folders.*`, `asset_tags.*` | yes | yes | no | no | yes for `*.view` only |
| `blocks.*`, `block_templates.*`, `block_versions.*` | yes | yes | no | no | yes for `*.view` only |
| `content.view`, `content.history.view` | yes | yes | yes | yes | yes |
| `content.manage`, `content.publish` | yes | yes | yes | yes | no |
| `comments.view`, `comments.react` | yes | yes | yes | yes | yes |
| `comments.create` | yes | yes | yes | yes | no |
| `comments.update_own`, `comments.delete_own`, `comments.resolve_own` | yes | yes | yes | yes | no |
| `data_sources.view`, `data_entries.view` | yes | yes | yes | yes | yes |
| `data_sources.manage` | yes | yes | no | no | no |
| `data_entries.manage` | yes | yes | yes | no | no |
| `redirects.view`, `releases.view` | yes | yes | yes | yes | yes |
| `redirects.manage`, `releases.manage`, `releases.publish` | yes | yes | yes | no | no |
| `backups.*`, `migrations.*`, `ai.*` | yes | yes | no | no | yes for `*.view` where relevant |

---

## Effective access resolution

### Root override

If `users.is_root = true`, all checks allow immediately.

### Team context

To resolve team abilities:

1. Load the current team
2. Traverse its ancestor chain recursively to the root
3. Collect all direct `team_user` assignments for the user on that chain
4. Resolve each assignment through `role_id`
5. Union all team-role ability arrays
6. Return the deduplicated set

### Space context

To resolve space abilities:

1. Load the direct `space_user` assignment for the space
2. Traverse the owning team's ancestor chain recursively to the root
3. Collect all direct `team_user` assignments for the user on that chain
4. Resolve the space role and all ancestor team roles through `role_id`
5. Union all resulting ability arrays
6. Return the deduplicated set

### Important constraints

- no explicit deny rules
- no instance-specific permission rows
- no use of the existing `permissions` table for core ability decisions

Recursive traversal is part of the initial release. Inherited team roles flow
down the tree to descendant teams and to spaces owned by descendant teams.

---

## Schema design

### New table: `roles`

```sql
roles
  id            uuid/ulid      PK
  team_id       uuid/ulid      nullable FK -> teams.id
  scope         varchar(20)    not null    -- 'team' | 'space'
  key           varchar(100)   not null    -- machine name, e.g. 'owner'
  name          varchar(150)   not null    -- display name
  description   text           nullable
  level         integer        not null
  is_system     boolean        not null default true
  abilities     json/jsonb     not null
  created_at    timestamp
  updated_at    timestamp
  deleted_at    timestamp nullable
```

Constraints:

- unique on `(scope, team_id, key)` for active roles
- `team_id = null` for system roles
- custom team-defined space roles set `team_id` to the defining team

### Membership tables

Add nullable foreign keys first:

```sql
team_user
  role_id       uuid/ulid nullable FK -> roles.id

space_user
  role_id       uuid/ulid nullable FK -> roles.id

invites
  role_id       uuid/ulid nullable FK -> roles.id
```

The string `role` columns are temporary migration inputs only. They should be
dropped in the same release after backfill and code cutover.

### Initial indexes

- `roles(scope, team_id, key)`
- `team_user(team_id, user_id)`
- `team_user(role_id)`
- `space_user(space_id, user_id)`
- `space_user(role_id)`
- `invites(team_id, email)`
- `invites(space_id, email)`
- `invites(role_id)`

Do not index `abilities` initially. Ability checks happen in application memory.

---

## Seed data

Seed all built-in roles through a dedicated seeder or migration step.

### Seeded system team roles

- `team.owner`
- `team.admin`
- `team.member`

Stored as:

- `scope = team`
- `team_id = null`
- `key = owner|admin|member`
- `is_system = true`

### Seeded system space roles

- `space.owner`
- `space.admin`
- `space.editor`
- `space.member`
- `space.viewer`

Stored as:

- `scope = space`
- `team_id = null`
- `key = owner|admin|editor|member|viewer`
- `is_system = true`

Important:

- the seeder must be idempotent
- role IDs must be environment-local, not hard-coded in source
- application code should resolve roles by `(scope, key, team_id=null)` during
  backfill and tests

### Custom space roles in the initial release

The initial release must support CRUD for custom team-defined space roles.

Rules:

- only space-scoped custom roles are supported initially
- only team `owner` and `admin` may manage custom roles for that team
- child teams may use ancestor-defined custom roles but may not modify them
- a custom role may only contain abilities from the allowed space ability catalog
- deleting a custom role must fail with `422` while assignments or invites still reference it

---

## Migration and cutover plan

This section is the implementation path. It is a single-release cutover.

### One-go migration

Deliver in one coordinated change set:

1. create `roles`
2. seed built-in system roles
3. add `role_id` to `team_user`, `space_user`, and `invites`
4. add custom space role CRUD and validation
5. backfill `role_id` from existing legacy string roles
6. remediate invalid legacy rows before finalizing constraints
7. update all application reads and writes to use `role_id`
8. implement recursive ancestor traversal in the resolver
9. remove the synthetic `team_user.role = "space"` invite behavior
10. enforce `NOT NULL` on `role_id` where valid data is guaranteed
11. drop legacy `role` columns from `team_user`, `space_user`, and `invites`

Backfill rules:

- `team_user.role = owner|admin|member` -> matching system team role
- `space_user.role = owner|admin|editor|member|viewer` -> matching system space role
- `invites.team_id != null` -> resolve against team scope
- `invites.space_id != null` -> resolve against space scope
- `team_user.role = guest|space` -> migrate explicitly before constraints are enforced

### Invalid legacy row policy

Because this is a one-go migration, unresolved rows are not allowed to survive
the release.

Required remediation:

- `guest` team memberships must be converted to `member` or removed
- synthetic `space` team memberships must be removed
- invalid invite roles must be corrected or deleted

The migration must fail loudly if unresolved role strings remain before the
final constraint step.

### Custom role backfill policy

There is no legacy custom-role data to migrate in the current CMS. Custom roles
enter the system only after the `roles` table release. System roles are the only
backfill source.

---

## Backend implementation

### 1. Role enums and config

Create:

- `App\Enums\RoleScope`
- `App\Enums\TeamRoleKey`
- `App\Enums\SpaceRoleKey`
- `config/authorization.php`

The config file must hold:

- built-in role keys
- seeded ability arrays
- deprecated legacy role mappings

### 2. Role model

Create:

```php
final class Role extends Model
{
    protected $casts = [
        'abilities' => 'array',
        'is_system' => 'boolean',
    ];
}
```

Relationships:

- `Role belongsTo Team`
- `Team hasMany Role`

The initial release does not need dedicated membership relationships on `Role`
unless they simplify reporting or later cleanup.

### 3. Authorization service

Create a central service:

```php
final class AuthorizationService
{
    public function abilitiesForTeam(User $user, Team $team): array {}

    public function abilitiesForSpace(User $user, Space $space): array {}

    public function canInTeam(User $user, Team $team, string $ability): bool {}

    public function canInSpace(User $user, Space $space, string $ability): bool {}
}
```

Implementation rules:

- `is_root` short-circuits all checks
- use `role_id` only
- resolve ancestor teams through a single recursive CTE or equivalent efficient query
- return sorted unique ability strings
- build from a cached per-user authorization graph
- invalidate on membership writes, invite acceptance, role updates, team-parent changes, and team/space creation or reassignment

### 4. Cached authorization graph

Do not cache individual boolean `can()` answers. Cache the whole user's
authorization graph.

Recommended structure:

```php
[
    'user_id' => '01...',
    'is_root' => false,
    'teams' => [
        'team-id' => [
            'role_keys' => ['owner', 'admin'],
            'abilities' => ['team.view', 'team.update'],
        ],
    ],
    'spaces' => [
        'space-id' => [
            'team_role_keys' => ['admin'],
            'space_role_key' => 'editor',
            'abilities' => ['space.view', 'content.manage'],
            'plan' => ['id' => 'plan_01', 'slug' => 'scale', 'status' => 'active'],
        ],
    ],
]
```

Cache rules:

- cache key: `authz:user:{user_id}:v1`
- store the entire graph for the user
- resolver and frontend endpoint both read from the same cached graph
- use TTL as a safety net only; correctness comes from invalidation

Smart invalidation triggers:

- `team_user` attach, update, detach
- `space_user` attach, update, detach
- invite accept, revoke, resend if role changes
- role create, update, delete
- team parent change
- space team reassignment
- subscription plan/status change for spaces included in the snapshot

Invalidation strategy:

- invalidate the directly affected user on membership and invite acceptance changes
- invalidate all users assigned to a role when that role changes
- invalidate all members of a team subtree when `teams.parent_id` changes
- invalidate all members of a space and owning team ancestry when `space.team_id` changes

This is the required caching model for the initial release.

### 5. Policy refactor

Policies stay in Laravel. Their job becomes translation from domain action to ability.

Examples:

- `TeamPolicy::update()` -> `team.update`
- `InvitePolicy` in team context -> `team.invites.manage`
- `SpacePolicy::update()` -> `space.update`
- `TokenPolicy::create()` -> `space.tokens.manage`
- `DataSourcePolicy::create()` -> `data_sources.manage`
- `DataEntryPolicy::create()` -> `data_entries.manage`
- `ContentPolicy::publish()` -> `content.publish`

Resource ownership exceptions remain in policy code:

- `comments.update_own`
- `comments.delete_own`
- `comments.resolve_own`

### 6. Validation strategy

External request payloads should continue to use role keys in the initial rollout.

Examples:

- team invite request sends `"role": "admin"`
- space invite request sends `"role": "editor"`

Backend flow:

1. validate role key against allowed keys for the context
2. resolve matching `Role` record
3. write `role_id`

Do not expose raw `role_id` values in write payloads for the first release.

### 7. Custom role management

Add backend support for custom team-defined space roles:

- `GET /mgmt/v1/teams/{team}/roles/space`
- `POST /mgmt/v1/teams/{team}/roles/space`
- `PATCH /mgmt/v1/teams/{team}/roles/space/{role}`
- `DELETE /mgmt/v1/teams/{team}/roles/space/{role}`

Behavior:

- only custom space roles for the target team are mutable
- include inherited ancestor custom roles in read endpoints as read-only entries
- validate ability arrays against the known space ability catalog
- block deletion when active assignments or invites exist

---

## API contract

### Role catalog and authorization endpoint

Use a single frontend authorization endpoint for the current authenticated user:

`GET /mgmt/v1/authorization`

Supported query parameters:

- `space_id` optional
- `team_id` optional

Rules:

- if `space_id` is present, return space-context authorization for the current user
- if `team_id` is present, return team-context authorization for the current user
- if neither is present, return a lightweight global snapshot of accessible teams and spaces
- if both are present, the response may include both contexts, but the frontend should normally request one active context at a time

This endpoint must also return the role catalogs needed by the frontend so the
SPA does not need separate role-list requests for common authorization-aware views.

Initial response shape:

```json
{
  "data": {
    "user_id": "01...",
    "is_root": false,
    "team": {
      "id": "team_01",
      "role_keys": ["admin"],
      "abilities": ["team.view", "team.members.manage"]
    },
    "space": {
      "id": "space_01",
      "team_role_keys": ["admin"],
      "space_role_key": "editor",
      "abilities": ["space.view", "content.manage", "content.publish"],
      "plan": {
        "id": "plan_01...",
        "slug": "scale",
        "status": "active"
      }
    },
    "roles": {
      "team": [
        { "key": "owner", "name": "Owner", "level": 300, "is_system": true }
      ],
      "space": [
        { "key": "editor", "name": "Editor", "level": 150, "is_system": true },
        { "key": "translator", "name": "Translator", "level": 110, "is_system": false, "team_id": "team_01" }
      ]
    }
  }
}
```

The payload is for UI gating only. Backend access control still happens in policies.

---

## Frontend implementation for the current Vue SPA

This repository uses Vue Router and Vue Query in `resources/js`.

### Remove hard-coded role arrays

Current components hard-code role arrays for team and invite forms. Replace that
with data from `/mgmt/v1/authorization`.

Targets include:

- team member dialogs
- invite dialogs
- member filters
- invite filters

### Add authorization composables

Create:

- `useAuthorization(params)`
- `useAbility(ability, context)`

### Route gating

Use Vue Router meta, for example:

```ts
{
  path: '/:space/settings/people',
  name: 'space-settings-people',
  component: () => import('~/pages/[space]/settings/people.vue'),
  meta: {
    requiredAbility: 'space.members.manage'
  }
}
```

Enforce this in the existing global router guard after auth initialization.

### UI gating

Components must gate actions by abilities, not by role key comparisons.

Examples:

- invite controls require `team.invites.manage` or `space.invites.manage`
- token controls require `space.tokens.manage`
- subscription controls require `space.billing.manage`
- editorial actions require `content.manage` or `content.publish`
- custom role management controls require `team.members.manage` plus team admin/owner level as defined by policy

---

## Required bug fixes during the rollout

These are current codebase issues and must be fixed as part of the roles-table work:

- `StoreInviteRequest` builds role validation rules but does not apply them
- `TeamPolicy::update()` uses `wherePivot()` incorrectly with an array
- assets, asset folders, and asset tags are not consistently protected with policy checks
- space invite acceptance creates an invalid synthetic team role assignment
- frontend role selectors are broader and less consistent than the backend model
- there is no single frontend authorization payload today

The roles-table rollout is the right time to remove those inconsistencies.

---

## Deferred work after the initial roles-table release

These are explicitly out of scope for the initial implementation:

- team-defined custom team roles
- cross-team custom role editing UI beyond the scoped space-role CRUD described above
- inherited role provenance badges
- instance-scoped permissions
- replacing JSON abilities with a normalized `role_permissions` table

The schema proposed here supports that future work, but the first release should
not attempt it.
