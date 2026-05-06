# Changelog

All notable changes to b10cks are documented here.
Commits follow the [Gitmoji](https://gitmoji.dev/) convention.

## [v2026.5.6-da0dde8] — 2026-5-6

- 👔 Expose external_id in content api
- 👔 Allow to manage external_id

## [v2026.5.6-ddd13bd] — 2026-5-6

- ⬆️ Bump versions
- 🐛 Prefix external_id with table

## [v2026.5.4-afc4b8d] — 2026-5-4

- 👔 Link to software sites
- 🍱 Add marketing assets
- ⬆️ Bump versions

## [v2026.5.1-4609454] — 2026-5-1

- 🔧 Increase sensible defaults for images

## [v2026.5.1-ff2d344] — 2026-5-1

- ⬆️ Bump versions
- 🔧 set sensible defaults for image qualities

## [v2026.4.29-acc876f] — 2026-4-29

- 🧑🏻‍💻 Improve example .env
- 💡 Address pitfalls for local development in readme
- 🗃️ Make role_id nullable to accomodate `nullOnDelete`

## [v2026.4.28-11a614f] — 2026-4-28

- 🔧 Rename echo host

## [v2026.4.28-e9a5925] — 2026-4-28

- ⬆️ Fix versions

## [v2026.4.28-0e6c23c] — 2026-4-28

- ⬇️ downgrade versions

## [v2026.4.28-f743be2] — 2026-4-28

- 🔧 use correct public host
- ⬆️ Bump versions
- 💡 Improve readme

## [v2026.4.26-28e1061] — 2026-4-26

- 🐛 Resolve translated slugs correctly

## [v2026.4.26-2f85293] — 2026-4-26

- 👔 Use system prompt for each AI interaction
- 🚸 Prevent default values spill into new localization
- 🚸 Fix conten tree padding/overlap
- ⬆️ Upgrade to Laravel v13
- ✨ Add SAML2 auth self service

## [v2026.4.25-46a9994] — 2026-4-25

- ⬇️ Downgrade vite due to build errors

## [v2026.4.25-f623ade] — 2026-4-25

- ⬆️ Bump versions

## [v2026.4.25-f3cd59b] — 2026-4-25

- ✨ Add social logins

## [v2026.4.25-f47f71a] — 2026-4-25

- ⬆️ Bump versions
- ⬆️ Bump versions

## [v2026.4.22-52a3f55] — 2026-4-22

- 🩹 Fix publish date issue

## [v2026.4.21-e5c4b22] — 2026-4-21

- 🚸 Improve Stats
- 🔧 Pass public ENV based config via laravel to vue

## [v2026.4.21-c4f5a59] — 2026-4-21

- ✨ Add provider specific notes and help menu
- ⬆️ Bump versions
- 👔 improve handlebars preview

## [v2026.4.19-3245924] — 2026-4-19

- ⚡️ Improve canvas performance for large trees
- 🚸 Improve space icon

## [v2026.4.19-c01a1c7] — 2026-4-19

- 🍱 Replace favicons
- ⚡️ Cache content-menu and reduce payload

## [v2026.4.19-4def5d2] — 2026-4-19

- 💄 Improve StatsCard
- 👔 Improve redirects management with add and import/export

## [v2026.4.18-532d1e5] — 2026-4-18

- 🐳 Add redis as native php module

## [v2026.4.18-d06979f] — 2026-4-18

- ✨ Add animate image support

## [v2026.4.18-d344971] — 2026-4-18

- 🩹 Fix some vips based transformations
- 👔 add lower case access control headers
- ⬆️ Bump versions

## [v2026.4.18-5740dd7] — 2026-4-18

- 👔 Improve content publishing and updating with inlined translation support
- 👔 Use fit as default operation if both width and height are supplied
- 👔 Add link localization and better localization validation

## [v2026.4.17-22abbb5] — 2026-4-17

- 👔 Response with the original if no transformations are supplied
- 👔 Expose the full_path from the actual asset

## [v2026.4.15-62011cb] — 2026-4-15

- Revert "⚡️ Send cache header for web response"

## [v2026.4.15-8e09cdb] — 2026-4-15

- 👔 Improve ilum endpoints
- ⬆️ Bump versions
- ⚡️ Send cache header for web response

## [v2026.4.15-f19e928] — 2026-4-15

- ⬆️ Bump versions
- 👔 Get disk in image controller and pass down
- 👔 Expose more data from the merged asset
- 🐛 Improve block tag validation
- 👔 Allow to edit block tags
- 🚸 Hide icon from IconName if not present
- 🗃️ Harmonize content slug to max 70 chars
- 👔 Improve link validation and enrich email links
- ⚡️ Improve mysql indices for content management
- ⚡️ Reduce DB queries to 1 when saving content
- 🐛 Prevent storing of space settings when touching
- 👔 Improve for production-ready data

## [v2026.4.13-1ec473a] — 2026-4-13

- 💄 Improve UX for production data
- ✏️ Fix typo in tablename
- 🔧 Increase acceptable content size to 500mb
- 🐛 Fix folder validation in UpdateBlockRequest

## [v2026.4.12-ba9595a] — 2026-4-12

- ⬆️ Bump versions
- 🚸 Improve UX of settings table add

## [v2026.4.9-596297b] — 2026-4-9

- ⬆️ Bump versions
- ✨ Improve SEO with advanced meta field and a sitemap API
- 💄 Improve stats rendering
- 👔 Prevent auto translation creation

## [v2026.4.7-4f67b58] — 2026-4-7

- 👔 Bump versions
- 👔 Improve meta and translation AI triggers

## [v2026.4.6-750723a] — 2026-4-6

- ⬆️ Bump versions
- 🚸 Improve content validation feedback

## [v2026.4.4-3a44bfa] — 2026-4-4

- ✨ Add table block type

## [v2026.4.4-88c4b9d] — 2026-4-4

- 👔 Improve template-based block adding
- 🚸 Improve ContentHeader slots
- 👔 Adapt data source active flag handling
- ✨ Add multi options block type and allow to use data sets as source
- 🐛 Disable content tree horizontal scroll
- 🐛 Fix force deleting used assets
- ✨ Add space blueprints for reusable space setups
- 🚸 Prevent audit-log caching

## [v2026.4.3-5ca6a01] — 2026-4-3

- ✨ Add basic space internal audit logs
- ✨ Handle asset usage tracking
- ✨ Extract and resolve content relations
- ✨ Allow to restrict child block types
- ⬆️ Bump versions

## [v2026.3.28-0402bd4] — 2026-3-28

- 🐛 Fix focus fallback for assets

## [v2026.3.26-858c2de] — 2026-3-26

- ✨ Add ContextMenu to ContentTree
- ⬆️ Bump versions
- 👔 Improve Localization and fix some issues

## [v2026.3.24-7764460] — 2026-3-24

- 🐛 Fix auth guard for new space

## [v2026.3.24-8dca4b5] — 2026-3-24

- 👔 Use a new ContentSelect for reference block

## [v2026.3.24-9c73a9d] — 2026-3-24

- 🔇 Remove console.log
- 📈 Identify user with posthog

## [v2026.3.24-23c0294] — 2026-3-24

- 👔 Improve blocks management
- 🎨 Apply oxmft code style
- ⬆️ Bump versions
- 🚸 Save page size for results
- 🐛 Fix missing icon
- 💄 Improve canvas node
- 🚸 Reduce flicker on comment
- 🚸 Allow to clear non reqired Options
- ✨ Add languages icon
- 🚸 Add translatable icon to block field summary
- 💄 Align checkbox
- 💄 Fix vertical scrollbar
- 🚸 Improve editor tab navigation
- 📝 Add SECURITY.md with security policy
- 🐛 Add auto imports
- 🚸 Small UX improvements in canvas

## [v2026.3.24-20dd9a8] — 2026-3-24

- 🐛 Fix changelog generation
- 💄 Remove spacing
- 💄 Auto hide scrollbars
- ⬆️ Bump versions
- 💄 Add glass effect to AiText
- 🔖 Release v2026.3.24-64ecd34
- 📝 Add changelog

## [v2026.3.24-655536e] — 2026-3-24

- 🚸 Improve canvas infinite area
- 🚸 Add canvas help
- 🚸 Improve Canvas keyboard interactions

## [v2026.3.24-4f02fb1] — 2026-3-24

- 💬 Improve DE translations
- 💄 Improve styling of canvas node
- 📝 Improve readme
- ✨ Implement space role management
- 🚸 Add default environment setting
- 🔧 always inject posthog

## [v2026.3.24-2b2fc28] — 2026-3-24

- 🚸 Add default environment setting

## [v2026.3.24-0fb494c] — 2026-3-24

- 🔧 always inject posthog
- 💬 Replace hardcoded labels with translations
- 🩹 Fix posthog init
- 🚸 Improve canvas infinite area
- 🚸 Add canvas help
- 🚸 Improve Canvas keyboard interactions
- 🚸 Improve content canvas
- 💄 Improve empty content placeholder
- 🐛 Fix Add dropdown item restrictions
- 🚸 Improve content tree & miscellaneous UI
- 🛂 Enforce RBAC in frontend
- ✨ Allow to filter contents by id
- ✨ Improve multiplayer undo/redo in canvas

## [v2026.3.23-24754b0] — 2026-3-23

- ✨ Allow to filter contents by id
- ✨ Improve multiplayer undo/redo in canvas
- ✨ Improve Canvas with undo/redo, collapse

## [v2026.3.22-b7026fa] — 2026-3-22

- 💄 Align conten tree icons
- 🚸 Improve content breadcrumbs
- 🚸 Hide comment button when empty
- 🚸 Improve multiplayer cursor accuracy

## [v2026.3.21-dc63de4] — 2026-3-21

- ✨ Multi-player content wizard editing

## [v2026.3.21-6cdafff] — 2026-3-21

- ⬆️ Bump versions
- ✨ Add content wizard

## [v2026.3.20-f0bdeed] — 2026-3-20

- ✨ Allow to force save with validation errors
- 🚸 Improve copying multiple items
- ⚡️ Improve contens fetching
- 🐛 Fix creating empty content
- 🐛 Handle content collection correctly

## [v2026.3.20-6943516] — 2026-3-20

- ⚡️ Improve contens fetching
- 🐛 Fix creating empty content
- 🐛 Handle content collection correctly
- 🐛 Fix test link spilling into code

## [v2026.3.20-34e54ce] — 2026-3-20

- 🐛 Fix creating empty content
- 🐛 Handle content collection correctly
- 🐛 Fix test link spilling into code
- 👔 Improve default language handling
- ✨ Handle space-only members

## [v2026.3.20-4afe94d] — 2026-3-20

- 👔 Improve search api
- 👔 Improve APIs and documentation
- ⬆️ Bump versions
- 🩹 Fix assignment of changed names

## [v2026.3.19-a0276ce] — 2026-3-19

- ✨ Allow to hide blocks
- ✨ Add placeholders to RTE
- 🙈 Hide local folder
- 👔 Improve AI harness
- 💄 Use 2x resolution for avatar
- 🔇 Remove console logs
- 🔒 Clear vue-query cache on logout
- ✨ Handle out of schema fields
- ✨ Add conditional fields and validation rules

## [v2026.3.18-1475266] — 2026-3-18

- ✨ Handle out of schema fields
- ✨ Add conditional fields and validation rules
- 💄 Improve readability
- ✨ Implement new I18n handling
- ✨ Allow to show content JSON
- 🚸 Improve sidebar UI with togglable extended state
- 🐛 Allow to migrate in production mode for space setup

## [v2026.3.17-d52318d] — 2026-3-17

- ⚗️ Switch to sync setup jobs
- 🔒 Prevent data api for archived spaces
- 🐛 Fix content serialization in UpdateContentFullSlugsJob

## [v2026.3.17-86afa2d] — 2026-3-17

- 🚸 Improve sidebar UI with togglable extended state

## [v2026.3.17-4d7f614] — 2026-3-17

- 🐛 Allow to migrate in production mode for space setup
- ⚗️ Switch to sync setup jobs
- 🔒 Prevent data api for archived spaces

## [v2026.3.17-0231a74] — 2026-3-17

- ✨ Allow to show content JSON
- 🚸 Improve sidebar UI with togglable extended state
- 🐛 Allow to migrate in production mode for space setup
- ⚗️ Switch to sync setup jobs
- 🔒 Prevent data api for archived spaces
- 🐛 Fix content serialization in UpdateContentFullSlugsJob
- ⚡️ Improve icon grid rendering
- 💄 Implement a light checkerboard
- 🐛 Prevent event bubbles in file uploads
- ⬆️ Bump versions
- 🩹 Fix token name create error
- 🐛 Prevent background color spilling in normal avatar

## [v2026.3.16-35ffe2b] — 2026-3-16

- 🐛 Prevent event bubbles in file uploads
- ⬆️ Bump versions
- 🩹 Fix token name create error
- 🐛 Prevent background color spilling in normal avatar
- 🩹 Prevent collapse for now
- ✨ Handle live updates
- 🐛 Ensure ContentTable is visible
- 🧑🏻‍💻 Allow it index all spaces
- 🚸 Move setttings around
- 🐛 Fix search indexing
- 🚸 Improve IconName
- 🐛 Improve expanding items
- 🚸 Use px values for sidebar and allow to collapse
- 💄 Improve space header
- ✨ Add content tree dragging

## [v2026.3.15-c2273c1] — 2026-3-15

- ✨ Add content tree dragging
- 🚸 Adapt Space rendering
- 💄 Adapt light mode
- 💄 Improve settings UI
- 👔 Handle default block

## [v2026.3.15-3828906] — 2026-3-15

- 🐛 Fix search indexing
- 🚸 Improve IconName
- 🐛 Improve expanding items
- 🚸 Use px values for sidebar and allow to collapse
- 💄 Improve space header
- ✨ Add content tree dragging
- 🚸 Adapt Space rendering
- 💄 Adapt light mode
- 💄 Improve settings UI
- 👔 Handle default block
- ✨ Allow to have folder specific meta fields for assets
- 👔 Improve asset handling
- 🐛 Ensure driver config is used for default storage
- ⬆️ Bump versions
- 💄 Adapt app header menu
- 🚸 Improve subscription badge

## [v2026.3.14-062b856] — 2026-3-14

- ⬆️ Bump versions
- 💄 Adapt app header menu
- 🚸 Improve subscription badge
- 💄 Improve team non access
- 🚸 Improve empty spaces
- 🍱 Add more stickers
- 🚸 Improve initial team selection
- ✨ Add forget password flow
- ✨ Handle global notifications
- 🚸 Improve email verification
- 💄 Improve the mail logo
- 🏷️ Fix type import
- 🚸 Improve space rendering with image and badge
- 👔 Improve invite UX and handling
- ✨ Implement role management

## [v2026.3.13-bbc54aa] — 2026-3-13

- ✨ Implement role management

## [v2026.3.13-5d51ee7] — 2026-3-13

- 🏷️ Fix type import

## [v2026.3.13-2a3493c] — 2026-3-13

- 🚸 Improve space rendering with image and badge
- 👔 Improve invite UX and handling
- ✨ Implement role management
- ✨ Implement multi-block copying

## [v2026.3.12-cf33b7b] — 2026-3-12

- ✨ Implement multi-block copying
- 💬 Adapt more links

## [v2026.3.12-9dc666e] — 2026-3-12

- 💬 Use language specific links
- ✨ Add plans and subscriptions
- ♻️ Support vid selection and widen asset handler param
- 🐛 Fix rv retrieval
- 👔 Allow advanced filtering for parent_id
- ♻️ Cleanup content fetch

## [v2026.3.11-f7eb48f] — 2026-3-11

- 🐛 Correctly save default language

## [v2026.3.11-a553054] — 2026-3-11

- 🐛 Fix isDirty evaluation
- 🚸 Allow to resize preview in responsive mode
- 🚸 Improve preview buttons
- 👔 Adapt language prefix strategy for preview

## [v2026.3.10-88e29ca] — 2026-3-10

- 💥 Switch full_slug strategy

## [v2026.3.9-b252d61] — 2026-3-9

- ⬆️ Bump versions
- ⬆️ Bump versions
- ✨Implement space migration
- ✨ Add space:delete command

## [v2026.3.6-c5fb98d] — 2026-3-6

- ✨ Add optional badge to space
- ⬆️ Bump versions
- 🚸 Smaller UX improvements
- ⬆️ Bump versions

## [v2026.2.20-1c5d9cd] — 2026-2-20

- 🚸 Improve AI UX
- 💄 Improve ContentTree actions
- 🚸 Prevent unnecessary guard
- 🩹 Fix update from preview

## [v2026.2.17-5bcdc4c] — 2026-2-17

- 🩹 Fix content retrieval for non published versions
- 🚧 Impement content tree AI
- 🚧 Improve content AI interaction
- 🚧 Initial content AI implementation
- 🍱 Add shacnui input-group

## [v2026.2.14-e66012a] — 2026-2-14

- 🚸 Improve versions interactions as link
- 🚸 Add proper dirty tracking for content
- 🩹 Fix team selection
- 👔 Add dedicated middleware to expose APP_VERSION as header
- 🐛 Normalize image baseURL and src path
- ⬆️ Upgrade to latest TipTap
- 🩹 Fix content routing, again
- ⚡️ Improve PWA assets
- 🎨 Apply code styles
- ⬆️ Bump various frontend dependencies
- 👔 Remove routing issues
- ⚰️ Remove further deprecated code from the nuxt port
- 🩹 Fix some routing issues due to the nuxt port
- 🧑🏻‍💻 Improve vue integration
- 🩹 Prevent overlapping router views
- 🙈 Ignore dev-dist
- 🐛 Prevent // in ilum URLs

## [v2026.2.14-cb71e29] — 2026-2-14

- 👷 Adapt vite app deployment

## [v2026.2.14-9cc9672] — 2026-2-14

- 👔 Remove routing issues
- ⚰️ Remove further deprecated code from the nuxt port
- 🩹 Fix some routing issues due to the nuxt port
- 🧑🏻‍💻 Improve vue integration

## [v2026.2.14-3fd917a] — 2026-2-14

- 🩹 Fix team selection
- 👔 Add dedicated middleware to expose APP_VERSION as header

## [v2026.2.14-1e8b291] — 2026-2-14

- 🐛 Normalize image baseURL and src path
- ⬆️ Upgrade to latest TipTap
- 🩹 Fix content routing, again
- ⚡️ Improve PWA assets
- 🎨 Apply code styles
- ⬆️ Bump various frontend dependencies
- 👔 Remove routing issues
- ⚰️ Remove further deprecated code from the nuxt port
- 🩹 Fix some routing issues due to the nuxt port
- 🧑🏻‍💻 Improve vue integration
- 🩹 Prevent overlapping router views
- 🙈 Ignore dev-dist
- 🐛 Prevent // in ilum URLs
- 👷 Adapt vite app deployment
- 👷 Sync vite app to S3 Bucket

## [v2026.2.13-dbda75f] — 2026-2-13

- ✏️ Fix casing of app.vue import
- 👷 Actuall install dependencies with bun
- 👷 Bundle frontend in build process
- ✨ Integrate vue frontend
- 🐛 Fix logout

## [v2026.2.10-c4373d3] — 2026-2-10

- ✏️ Fix typo in filename
- 🐛 Reintroduce broadcast authentication

## [v2026.2.10-4f69512] — 2026-2-10

- 🐛 Reintroduce broadcast authentication
- 🔒 Add stateful middleware to auth routes
- 🚑 Fix login issues
- ✨ Switch to laravel sanctum based auth. Drop support for the JWT approach
- 👔 Add type hints and fix middleware args
- 👔 Adapt cache header strategy

## [v2026.2.9-c6e59e4] — 2026-2-9

- ✨ Switch to laravel sanctum based auth. Drop support for the JWT approach

## [v2026.2.9-6cafbeb] — 2026-2-9

- 🚑 Fix login issues
- ✨ Switch to laravel sanctum based auth. Drop support for the JWT approach
- 👔 Add type hints and fix middleware args
- 👔 Adapt cache header strategy
- ⬆️ Bump versions
- 👔 Expose rv in SpaceResource

## [v2026.2.5-e533eba] — 2026-2-5

- 🐛 Register teams/hierarchy before teams resource

## [v2026.2.5-5423468] — 2026-2-5

- 👔 Improve payloads
- 🚑 Revert to open CORS to handle user websites

## [v2026.2.4-fd13478] — 2026-2-4

- 🗃️ Move 2FA fields to initial users migration
- ⬆️ Bump versions
- 🔒 Restrict CORS origins and add mgmt path
- 🗃️ Prepare database notifications
- ✨ Implement block version and templates
- 🐛 Fix Dumper arguments (again2)
- 🐛 Fix Dumper arguments (again)
- 🐛 Fix Dumper arguments

## [v2026.2.3-4349b33] — 2026-2-3

- ✨ Implement block version and templates

## [v2026.2.3-588bd60] — 2026-2-3

- 🐛 Fix Dumper arguments (again2)
- 🐛 Fix Dumper arguments (again)

## [v2026.2.3-65a6da4] — 2026-2-3

- 🐛 Fix Dumper arguments
- 👔 Tailor backup to Docker container

## [v2026.2.2-8e8adc9] — 2026-2-2

- ✨ Add backup solution for db and storage
- 🚑 Allow non-2fa to login again
- 🚑 Fix verified middleware for mgmt routes

## [v2026.2.1-7fdb4ba] — 2026-2-1

- 🚑 Allow non-2fa to login again

## [v2026.2.1-0c38ee8] — 2026-2-1

- 🚑 Fix verified middleware for mgmt routes
- ✨ Implement user security features (2FA)
- ✨ Implement Team Members management
- 🐛 Fix zed commit errors

## [v2026.1.31-ff9b1fb] — 2026-1-31

- 👔 Add custom mail templates and assets
- ⬆️ Bump Composer dependencies
- 🩹 remove soft-deleted invites in unique check

## [v2026.1.31-a5904af] — 2026-1-31

- 🐛 Fix zed commit errors
- 👔 Add custom mail templates and assets
- ⬆️ Bump Composer dependencies

## [v2026.1.31-65acf82] — 2026-1-31

- 🩹 remove soft-deleted invites in unique check
- 🐛 Fix code review issues
- 🐛 Ignore soft-deleted rows in validation rules
- 👔 Emit ContentUpdated event on save and ContentDeleted on soft delete
- ✨ Implement user presence handling
- 🩹 Fix comment reaction authorization
- 👔 Improce comment policies and validation
- ✨ Implement commenting system with replies, reactions and resolution
- 👔 Switch to queued publishing workflow

## [v2026.1.29-a4cda16] — 2026-1-29

- ⬆️ Bump dependencies in composer.lock
- 🐛 Fix apparent issues in invite handling and validation
- 🐛 Guard team attach when team is null
- 🐛 Scope invite email uniqueness to provided target
- 🐛 Set role to owner for personal team
- ⚰️ Remove redundant null check in invite show
- ♻️ Refactor invite controllers and filter
- ♻️ Improve invite flow and user registration
- 🐛 Fix expected response for register
- 👔 adapt invite registration and split name fields
- ✨ Improve and finalize invite process
- 🐛 Fix authorize argument order in ReleaseController
- 🐛 Only publish releases with committed_at null
- ♻️ Move search indexing out of DB transaction
- ✨ Add release management features

## [v2026.1.26-376d2a4] — 2026-1-26

- 👔 Make content publishing atomic and robust
- ⚰️ Remove unused commented-out code
- 🩹 handle missing owner gracefully
- ✨ Add content scheduling process
- ⚡️ Boost space connection cache handling
- 🩹 Improve buggy SpaceSettings handling
- 👔 Adapt content asset handling for i18n

## [v2026.1.25-0746b51] — 2026-1-25

- ✨ Add a YAML-based import/export for assets data
- ✨ Add asset data import/export support
- ➕ Add maatwebsite/excel package
- 🎨 Improve code style

## [v2026.1.24-919401f] — 2026-1-24

- ✨ add language constraints to search
- 👔 Improve settings handling
- 🩹 Improve i18n overlay handling
- 👔 Enable locale-aware slugs generation
- ♻️ Cast settings to SpaceSettings
- 🩹 Prefer request space over app currentSpace
- ♻️ Use app('currentSpace') instead of route param

## [v2026.1.23-d316150] — 2026-1-23

- ⬆️ Bump composer dependencies
- 🧑🏻‍💻 Add Zed debug task for Xdebug
- ✨ Add search service and drivers

## [v2026.1.15-d663eb6] — 2026-1-15

- ⬆️ bump Composer dependencies
- 🩹 generalize header usage
- 👔 improve x-enforce-external-id validation for assets

## [v2026.1.11-c0d436b] — 2026-1-11

- 👔 improve x-enforce-external-id validation
- 🔧 adapt rate limiting again
- ✨ implement external_id validation when x-enforce-external-id is present
- 🩹 handle external_id for assets

## [v2026.1.11-4589236] — 2026-1-11

- 🩹 generalize header usage
- 👔 improve x-enforce-external-id validation for assets
- 👔 improve x-enforce-external-id validation
- 🔧 adapt rate limiting again
- ✨ implement external_id validation when x-enforce-external-id is present

## [v2026.1.11-39218b4] — 2026-1-11

- 👔 improve x-enforce-external-id validation for assets
- 👔 improve x-enforce-external-id validation
- 🔧 adapt rate limiting again
- ✨ implement external_id validation when x-enforce-external-id is present
- 🩹 handle external_id for assets
- 👔 improve rate limiting
- 👔 improve external_id handling
- ⬆️ upgrade nikic/php-parser to version 5.7

## [v2026.1.11-35f0717] — 2026-1-11

- 🔧 adapt rate limiting again
- ✨ implement external_id validation when x-enforce-external-id is present
- 🩹 handle external_id for assets
- 👔 improve rate limiting
- 👔 improve external_id handling
- ⬆️ upgrade nikic/php-parser to version 5.7
- ✨ handle external_id for content migration scenarios
- 🔧 disable rate limiting
- ✨ implement custom OpenApi based docs generation
- 🔥 remove dedoc/scramble implementation

## [v2026.1.11-6ba1014] — 2026-1-11

- 🔧 disable rate limiting
- 🩹 update validation rules for block and i18n_parent_id fields
- 🐳 fix docker copy

## [v2026.1.10-4d3551a] — 2026-1-10

- 🐳 fix docker copy
- 🩹 fix user database connection
- 👔 implement fixed validation rules
- 🩹 use currently configured driver as default when creating a new storage
- 👨🏼‍💻 render errors in non production envs
- 👔 expose content_updated_at in SpaceResource
- 👔 expose icon and color in content stats
- ⬆️ bump dependencies

## [v2025.12.11-098ab44] — 2025-12-11

- ⬆️ upgrade packages

## [v2025.11.8-3e2d252] — 2025-11-8

- ⬆️ upgrade packages
- 🔧 update php.ini configuration for performance and security

## [v2025.11.6-8bc1f5d] — 2025-11-6

- 🐳 improve dockerfile for structure and efficiency
- ⬆️ upgrade aws-sdk-php to version 3.359.6

## [v2025.11.5-453721f] — 2025-11-5

- 🐳 improve copy order for caching
- 👷‍ use jq to handle json payload for discord notification
- 👷‍♂️ remove sensitive details from discord notifications
- 🧑🏻‍💻 link project in discord notification
- ✅ fix failing tests
- 🔒 purify user fillable attributes
- 🔒 fix team policy roles
- ✏️ fix the logging index names
- 👷‍ improve discord webhook
- ⬆️ upgrade packages

## [v2025.11.5-1adb008] — 2025-11-5

- ✅ fix failing tests
- 🔒 purify user fillable attributes
- 🔒 fix team policy roles
- ✏️ fix the logging index names
- 👷‍ improve discord webhook
- ⬆️ upgrade packages
- 🧑🏻‍💻 post deployments to discord channel

## [v2025.11.4-0311895] — 2025-11-4

- 👔 add more statistics to space stats
- 👔 use hourly space stats for dashboard
- 💡 update doc blocks
- 👔 prevent tracking usage as default
- ✏️ use main- as image prefix

## [v2025.11.3-184c88c] — 2025-11-3

- 🐳 add composer.json to Dockerfile

## [v2025.11.3-056a962] — 2025-11-3

- 👔 use hourly space stats for dashboard
- 💡 update doc blocks
- 👔 prevent tracking usage as default
- ✏️ use main- as image prefix
- 🐳 add composer.json to Dockerfile
- ⬆️ upgrade packages
- 👔 run scheduler
- 🐳 include zlib extension
- ✨ implement cloudfront log parsing
- 🚚 rename ai_tables to follow convention
- ⬆️ upgrade packages

## [v2025.9.2-0457449] — 2025-9-2

- ✨ add redirect retrieval and usage functionality

## [v2025.8.26-67840c9] — 2025-8-26

- 👔 finish icon upload for space
- 🩹 update token execution queries to use 'started_at' instead of 'created_at'
- ⚡️ improve token execution handling

## [v2025.8.20-a555c74] — 2025-8-20

- 🩹 update token execution queries to use 'started_at' instead of 'created_at'

## [v2025.8.20-22fd6a9] — 2025-8-20

- ⚡️ improve token execution handling
- ✨ introduce AI model selection and usage tracking
- ✨ add meta tags AI generation feature
- 🩹 update content filter to use qualified column names

## [v2025.8.9-bfe9e4c] — 2025-8-9

- 🩹 update content filter to use qualified column names
- 👔 allow to filter content by parent_id

## [v2025.8.8-8729143] — 2025-8-8

- 🎨 use array for select statements
- 👔 force published as vid default in content request

## [v2025.8.8-51ece3e] — 2025-8-8

- 👔 get rid of version param and only use vid
- 👔 rename cache buster from ts to rv

## [v2025.8.7-23ff98c] — 2025-8-7

- 👔 expose thumbnails and duration in asset metadata
- 👔 allow floats regex pattern for gravity validation
- 🔒 enforce secure redirect for production environment
- 🔒 enforce secure redirect
- 👔 introduce ts in content responses

## [v2025.8.4-7ea906e] — 2025-8-4

- 🔒 enforce secure redirect for production environment

## [v2025.8.4-5b5d689] — 2025-8-4

- 🔒 enforce secure redirect
- 👔 introduce ts in content responses
- 🩹️ improve local storage processing logic
- ⬆️ upgrade dependencies in composer.lock
- 🔒 enforce secure redirect for production environment

## [v2025.7.30-9bbbb7a] — 2025-7-30

- 🔒 enforce secure redirect for production environment
- 👔️ simplify query parameter from b10cks_ts to ts

## [v2025.7.29-9011bf5] — 2025-7-29

- 👔 redirect if b10cks_ts is missing
- 🗃️ touch content_updated_at on updates
- 🗃️ add content_updated_at timestamp to Space model

## [v2025.7.28-c6d15dc] — 2025-7-28

- 👔 update pagination limit for block retrieval

## [v2025.7.28-7cce92d] — 2025-7-28

- 👔 handle non-image based assets

## [v2025.7.27-468a4f5] — 2025-7-27

- 🚧 temporary deactivate unique validation rules
- 🔧 update block request type validation to include 'universal'
- ⚡️ add Cache headers to data API
- 👷 tighten version tag pattern in deployment configuration

## [v2025.7.26-1071d9a] — 2025-7-26

- ⚡️ add Cache headers to data API
- 👷 tighten version tag pattern in deployment configuration
- 🔧 update php security and performance settings
- 🧱 add queue worker and reduce octane workers

## [v2025.7.26-45cc8f0] — 2025-7-26

- 👷 enhance Docker build process with caching support
- 🩹 handle null header in AcceptHeader locale parsing
- 🩹 configure trusted proxies and force HTTPS in production environment
- 👷 disable wait-for-service-stability in deployment workflow

## [v2025.7.25-ded640a] — 2025-7-25

- 🩹 adapt storage configuration resolution and factory

## [v2025.7.25-743e52d] — 2025-7-25

- 🩹 configure trusted proxies and force HTTPS in production environment
- 👷 disable wait-for-service-stability in deployment workflow
- 🩹 adapt storage configuration resolution and factory
- 🩹 fix asset URL generation to use ilum URL
- 👷 simplify tag creation in release script with short hash

## [v2025.07.25.2] — 2025-07-25

- 🩹 fix version extraction in deployment workflow

## [v2025.07.25-1] — 2025-07-25

- 🩹 add conditional initialization for PostHog API key

## [v2025.07.25] — 2025-07-25

- 🧱 add deployment workflow for AWS ECS
- 🩹 remove Slug cast from Block model
- 🚧 temporary disable database validations
- 👔 add 'url' to asset data in AssetHandler
- 🩹 ensure an object is returned
- 🔧 change 'type' to 'block' in ContentResource
- 🎉 I’m one with the Force. The Force is with me.
