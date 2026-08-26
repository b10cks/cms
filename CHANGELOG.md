# Changelog

All notable changes to b10cks are documented here.
Commits follow the [Gitmoji](https://gitmoji.dev/) convention.

## [v2026.8.26-876f2e0b] — 2026-8-26

- 🐛 Write avatars and icons to the disk they are served from

## [v2026.8.26-1e7a3bf6] — 2026-8-26

- 📝 Regenerate the management API spec
- ✨ Show roles inherited from a parent team
- 🚸 Keep the create button locked until a parent is picked
- 🔒 Let only a root user set the team type
- 📝 Regenerate the management API spec
- 🚸 Show the team hierarchy in every parent picker
- 🚸 Offer the system blueprint only to root
- ♻️ Extract a shared hierarchical team selector
- ✅ Cover the blueprint create flow
- 🐛 Filter blueprint lists by operator dates
- 🐛 Stop sending the team in the blueprint create body
- 🐛 Wrap single blueprint responses in the data envelope again
- 🐛 Keep space-specific settings out of a blueprint
- ✨ Add the API for blueprints without a team
- 🐛 Let a blueprint snapshot a space from another team
- 🔒 Authorize the blueprint a new space is created from
- 🐛 Resolve blueprint permissions through the team hierarchy
- 🚸 Open header and team navigation in a new tab
- ✨ Duplicate a content entry in place
- 🚸 Keep single blocks out of reach once they exist
- 🐛 Stop two overlapping creates from taking the same slug
- 🚸 Lock the create button while the entry is being created
- ⬆️ Bump versions
- 🐛 Stop a saved block from counting as unsaved
- 🚸 Offer keep editing, discard or save when leaving unsaved changes
- 🚸 Open a new block instead of returning to the overview
- 🚸 Open the combobox list on click
- 🚸 Remove a field's asset without confirming first
- 🐛 Derive the URL scheme from APP_URL, not APP_ENV
- Bump actions/attest-build-provenance from 4.1.1 to 4.2.2

## [v2026.8.25-1571e931] — 2026-8-25

- ⬆️ Bump versions
- 🌐 Pick the initial language from the browser
- 🐛 Show the singular when an invite expires tomorrow
- 🚸 Lead invited users to sign up, not log in
- ✨ Mail an invite in the invitee's own language
- 🐛 Localize the relative dates in translated mails
- 🌐 Translate the notification mails into every supported language

## [v2026.8.25-a7f7adbf] — 2026-8-25

- 📝 Add a best practices guide on designing blocks with atomic design
- 📝 Rewrite the user guide for a non-technical audience
- 📝 Document field plugins, team SSO, and the missing env keys
- 🐛 Make generated schemas independent of parse order
- 🐛 Keep tall dialogs inside the viewport
- ⬆️ Bump versions
- 📝 Note the invitation language in the space people docs
- 🐛 Give generated list endpoints and enums a real schema
- ✨ Let inviters pick the language of an invitation

## [v2026.8.24-3a0a1af4] — 2026-8-24

- ⬆️ Bump versions
- 🚸 Dismiss password-manager overlays on login
- 🐛 Drop removed keys when a focused item is updated

## [v2026.8.19-61b6c9e1] — 2026-8-19

- 📝 Document the grid round trip and batched saves
- ♻️ Use the shared space authorization helpers in mass edit
- 🚸 Confirm hidden edits when a language is dropped
- 🔒 Hide save & publish without the publish ability
- 🚸 Send large mass-edit saves in batches and keep failed cells
- 🐛 Round-trip mass-edit grid exports without dropping cells
- ⚡️ Load mass-edit grid rows without an N+1 per content
- ⚡️ Resolve nested block fields without walking every nesting path
- ✅ Register massEdit in the space resource set fixture
- ✨ Add mass content editing
- 🐛 Keep a content tree rename alive once the field is clicked

## [v2026.8.18-3a365362] — 2026-8-18

- ✅ Give guard navigation fixtures a normalized query
- 💄 Draw connector guides and a real drop line in the content tree
- 🐛 Honor a folder's manual child sort when repositioning content
- 🐛 Fix latent renaming bug
- 🔒 Give invites their own rate limiter
- 🔒 Lock the content row inside the publish transaction
- 🐛 Keep image previews after an upload completes
- 🐛 Apply external editor docs the schema cannot parse
- ⚡️ Stringify snapshot dirty state lazily again
- 🐛 Keep language switches from carrying or duplicating drafts
- ⚡️ Stop stringifying the editor document on every keystroke
- 🔒 Open preview tabs without an opener
- 🐛 Revoke upload preview URLs when the dialog closes
- 🔒 Throttle invite create and resend
- 🐛 Compare content versions under a row lock
- 🐛 Keep dirty language edits from saving onto another version
- 🔒 Harden login redirects and fail closed while auth is loading
- 📝 Document that delivery tokens stay plaintext by design
- ⬆️ Bump versions

## [v2026.8.18-2a8bf24f] — 2026-8-18

- 💄 Draw connector guides and a real drop line in the content tree
- 🐛 Honor a folder's manual child sort when repositioning content
- 🐛 Fix latent renaming bug
- 🔒 Give invites their own rate limiter
- 🔒 Lock the content row inside the publish transaction
- 🐛 Keep image previews after an upload completes
- 🐛 Apply external editor docs the schema cannot parse
- ⚡️ Stringify snapshot dirty state lazily again
- 🐛 Keep language switches from carrying or duplicating drafts
- ⚡️ Stop stringifying the editor document on every keystroke
- 🔒 Open preview tabs without an opener
- 🐛 Revoke upload preview URLs when the dialog closes
- 🔒 Throttle invite create and resend
- 🐛 Compare content versions under a row lock
- 🐛 Keep dirty language edits from saving onto another version
- 🔒 Harden login redirects and fail closed while auth is loading
- 📝 Document that delivery tokens stay plaintext by design
- ⬆️ Bump versions

## [v2026.8.13-13524779] — 2026-8-13

- 🏗️ Regenerate auto-import declarations
- 💄 Add missing table empty states
- 🚸 Guard unsaved changes in the block editor
- ✨ Add a keyboard shortcut registry and app-wide navigation
- ⬆️ Bump versions
- 🚸 Improve session timeout experience

## [v2026.8.12-ea491530] — 2026-8-12

- 🐛 Fix live preview for a focused block

## [v2026.8.12-91983492] — 2026-8-12

- 🐛 Fix build: drop UploadError re-export from useFileUpload

## [v2026.8.12-0b381da3] — 2026-8-12

- ⬆️ Bump versions
- ♻️ Extract generic Import/Export dialogs
- ♻️ Extract a CRUD composable factory and a query-key generator
- ♻️ Share list-table query state via useTableQueryState
- ♻️ Extract shared content editor page wiring
- ♻️ Unify the import/export driver stacks on a shared base
- ♻️ Deduplicate XHR uploads and asset backfills
- ♻️ Drop hand-rolled destroy try/catch, unify space context & per_page clamps
- ♻️ Collapse repetitive space-migration table copies
- ♻️ Consolidate space authorization into a helper and a base policy
- 🔥 Drop dead code and share the delivery content lookup

## [v2026.8.11-68245eba] — 2026-8-11

- 📝 Document custom posters for arbitrary assets
- ✨ Surface custom asset thumbnails across the UI
- 🐛 Enrich share thumbnails with full_path server-side
- ✨ Allow custom posters on any non-image asset, reversibly

## [v2026.8.11-eb4fb8bd] — 2026-8-11

- 🐛 Fix inert Switch bindings in the automations UI

## [v2026.8.11-835b0a1c] — 2026-8-11

- ✨ Add an automations guide to the native MCP server
- ✨ Reconcile system roles during b10cks:upgrade
- 📝 Document content actions and the role sync command
- ✨ Trigger manual automations from the content tree
- 🐛 Fix preview bridge live-update races

## [v2026.8.10-cf0e7ac1] — 2026-8-10

- 📝 Regenerate the management OpenAPI spec
- ✨ Publish a new language version without saving it first
- ⬆️ Bump CI actions and PHP/JS dependencies

## [v2026.8.10-44b30b98] — 2026-8-10

- 🐛 Stop draft saves from taking live content off the delivery API

## [v2026.8.10-d2c56545] — 2026-8-10

- 🔥 Delete the dead presence-peek composable
- 🚚 Collapse bulk data-entry broadcasts into one content-changed event
- 📶 Arm the reconnect catch-up on fast socket drops too
- 🔇 Stop broadcasting saves back to the client that made them
- 🐛 Treat broadcast patches as an overlay, not a substitute for refetch
- 🐛 Check truncated slug candidates against the DB, not the LIKE prefetch
- 🐛 Exempt public share endpoints from CSRF
- 🔒 Move space broadcast channels behind their auth guards
- 🌐 Auto-update the public share page over a token-named channel
- 🤝 Live-update asset collections, packages and shares in the space UI
- 📡 Extend live updates to data sources, block templates and content lists
- 🚀 Patch caches from broadcasts instead of refetching
- ⚡ Memoize the per-save parent sort-field lookup in ContentUpdated
- 📶 Catch up space queries after a socket drop
- 🗑️ Wire content:deleted into the tree, drop the dead presence event
- 🔤 Expand umlauts in slugs via one shared, language-aware normalizer
- 🗃️ Widen content slugs from 70 to 75 characters
- 📝 Close the last two open findings in the sweep record
- ✅ Land the frontend test suite and the fixes it surfaced
- 👷 Enforce typecheck and linting in CI
- 🏷️ Fix the remaining type errors across components and pages
- 🏷️ Align the ambient and API resource types with the backend
- 🏷️ Type the shared form, table and select primitives
- 🔥 Drop the dead Nuxt image provider
- 🐛 Fix shadowed identifiers and a clobbered maxlength
- 🐛 Return last_used_at on personal access tokens
- ➕ Add qrcode types and pin a single prosemirror-model
- ♿️ Label icon-only buttons and make click targets keyboard-operable
- 🏷️ Restore global ambient types and type the i18n composer
- ➖ Remove the unused @tanstack/vue-table dependency
- 🐛 Apply assorted backend hardening quick wins
- 🐛 Stop long-running jobs from being re-dispatched mid-flight
- 🔒 Sanitize block preview titles and lock down the preview bridge
- 🥅 Report unhandled exceptions to PostHog error tracking
- 🌐 Sync the German locale with the English key set
- 🔒 Enforce delivery-token abilities and gate drafts behind preview
- 🔒 Keep a stale tenant space from leaking across Octane requests
- 🐛 Open content transactions on the space connection
- 📝 Fold the deleted v2026.7.29-739eae81 changelog entry into the shipped release
- Bump actions/cache from 4.3.0 to 6.1.0
- Bump docker/login-action from 3.7.0 to 4.6.0
- Bump softprops/action-gh-release from 2.6.2 to 3.0.2
- Bump docker/setup-buildx-action from 3.12.0 to 4.2.0
- Bump docker/build-push-action from 6.19.2 to 7.3.0

## [v2026.7.29-80aae291] — 2026-7-29

- ✅ Fake the Vite manifest in the app-shell payload test
- 👷 Key the test-suite concurrency group on the calling workflow
- 📝 Fix the CACHE_STORE key and pin advice in the self-hosting docs
- 👷 Gate the production deploy on the test suite
- 🔒 Fix self-hosting blockers and gate the release on tests
- 📝 Ready the README, docs and community files for launch
- 👷 Pin workflow actions and attest the release artifacts
- 🔒 Harden the installer, compose defaults and the setup endpoint
- ✨ Close self-hosted registration once the first account exists
- 📝 Document the get.b10cks.com one-line install
- 🐛 Fix second-pass self-hosting review findings
- 👷 Add the get.b10cks.com installer and GHCR release mirror
- 🐛 Scope the transfers disk root to the local driver
- 🐛 Harden self-hosting review findings
- 🐛 Serve /setup outside the web middleware group
- 📝 Document the self-hosted distributions and edition configuration
- 👷 Publish Docker Hub image + webhost package on release tags
- 📦 Add webhost release package script and cron-driven queue draining
- 🐳 Add self-host Docker entrypoint, compose stack and env template
- ✨ Make the mail footer imprint edition-aware
- ✨ Support a local transfers disk with signed app-served downloads
- ✨ Provision shared-profile space databases without admin privileges
- ✨ Add b10cks:setup installer with profiles and HTTP /setup endpoint
- ✨ Add plans:setup --self-hosted preset
- ✨ Add feature flags to the SPA config and make realtime optional
- ✨ Add edition gate for SaaS-only billing surface

## [v2026.7.29-5acd0668] — 2026-7-29

- 🔒 Run the container as an unprivileged app user
- ✨ Apply block templates when adding on the canvas
- ⬆️ Bump versions

## [v2026.7.28-6d2a2bd6] — 2026-7-28

- 🔒 Harden ilum media delivery and expand its coverage
- 📝 Document media delivery, poster frames and IMAGE_* configuration
- ✨ Add range-aware media delivery and poster API to ilum

## [v2026.7.28-4e221629] — 2026-7-28

- 📝 Document the breadcrumb endpoint
- ✨ Serve content breadcrumbs from the delivery API
- 🐛 Honor a resource collection's wrapper in the generated spec

## [v2026.7.28-85571bb8] — 2026-7-28

- 📝 Document field plugins, named sitemaps, and the wider MCP catalog
- ✨ Bring the MCP surface on par with the management API

## [v2026.7.28-46c5d190] — 2026-7-28

- 🐛 Widen audit_logs.hash to fit HMAC-SHA256 hashes
- 🐛 Build MCP sub-requests on APP_URL so TrustHosts accepts them
- 👷‍♂️ Improve deployment

## [v2026.7.28-4b1353ef] — 2026-7-28

- ✨ Named per-type sitemaps with database-side pagination
- 🐛 Set fix name for index

## [v2026.7.28-eef36fcd] — 2026-7-28

- 💡 Update self hosting docs with new ENV
- ⚡️ Absorb the publish stampede with an origin micro-cache
- ⚡️ Skip redundant space reconnects and usage-row hydration
- ⚡️ Cache LemonSqueezy invoice listings for five minutes
- ⚡️ Validate and prefetch bulk-created contents in one pass
- ⚡️ Walk content trees level by level
- ⚡️ Keep version payloads out of release listings
- ⚡️ Stream data exports and chunk imports
- ⚡️ Push member directory filtering and paging into SQL
- ⚡️ Look up linked assets in the database, not in PHP
- ⚡️ Clamp redirect page size and index icons.updated_at
- ⚡️ Drop relation loads the delivery resolver redoes anyway
- ⚡️ Batch-resolve search hits on the delivery API
- ⚡️ Resolve mgmt content lists once per page
- ⚡️ Batch owner lookups when listing audit logs
- ⚡️ Increment usage stats instead of recounting the period
- ⚡️ Hash audit logs with an HMAC instead of bcrypt
- 🔒 Require a second factor to mint an API token
- ⚡️ Remember resolved link families for the request
- ✅ Stop the data source factory rolling a dice for is_active
- 🔒 Escape the heading row of CSV exports too
- 🔒 Delete avatars from the disk they are written to
- 🔒 Pin webhook connections to the addresses the SSRF guard approved
- 🔒 Store avatars and icons by their content, on the public disk
- 🔒 Validate environment URLs, and stop dropping settings that had no rule
- 🔒 Keep unpublished entries out of relation and link expansion
- 🔒 Require a consented membership before a team IdP may assert an address
- 🔒 End other sessions on a password change, and leave API tokens alone
- 🔒 Re-authenticate and throttle the two-factor management endpoints
- 💡 Regenerate docs
- ⬆️ Bump versions
- 🐛 Order the comment listing deterministically
- ✅ Update the broadcast payload tests to the menu-shaped payload
- 🔒 Keep unpublished entries out of the published delivery scope
- 🔒 Close a set of smaller credential and upload exposures
- ✅ Cover OutboundUrlGuard, and normalize IPv4-in-IPv6 before filtering
- 🔒 Keep space.update out of AI settings
- 🔒 Serve icon SVG inert
- 🔒 Throttle image delivery and count frames in the pixel guard
- 🔒 Neutralize formula injection in CSV and XLSX exports
- 🔒 Require proven email ownership before linking a social profile
- 🔒 Throttle two-factor and step-up verification attempts
- 🔒 Revoke API tokens on logout and password change
- 🔒 Authorize the block folder endpoints
- 🔒 Escape absolute URLs in the handlebars image helper
- 🔒 Keep the field-plugin iframe sandbox opaque
- 🔒 Run MCP tool calls as the calling user, not a shared admin token
- 🐛 Fix two authorization calls that always threw
- 🔒 Record the impersonator server-side and fix leaving impersonation
- 🔒 Actually enforce email verification
- 🔒 Scope the SAML email fallback to the provider's own team
- 🔒 Trust only configured hosts and proxies, and set security headers
- 🔒 Stop serving the private storage disk over HTTP
- ✨ Add heatmap to dashboard
- 🚸 Better handled expired sessions
- 👔 Harden serial field
- 🐛 Harden serial fields: post-create edits, duplication, moves
- ✨ Add serial fields: auto-generated content identifiers

## [v2026.7.22-6077c32f] — 2026-7-22

- ⬆️ Bump versions
- 🐛 Handle larger content.updates with pusher

## [v2026.7.21-82efd522] — 2026-7-21

- ✨ Add native MCP server for the Management API

## [v2026.7.20-be93bbe8] — 2026-7-20

- 💄 Clarify simple vs. structured data source setup
- ✨ Add shaped data source entries
- 📝 Regenerate mgmt OpenAPI spec for blocks/sync
- ✨ Add bulk block schema sync endpoint
- ✨ Harden team membership and add team details management
- 💡 Improve docs
- ✨ Add agency payment proposals and free-plan downgrade
- 💄 Polish subscription & usage settings UI
- ✨ Harden subscription system for production
- 👔 Merge invitations and members into one UX
- ✨ Add field plugins

## [v2026.7.16-091889c7] — 2026-7-16

- 👷‍♂️ Fix changelog
- ⬆️ Bump versions
- 💫 Improve sidebar sizing animation
- ⚡️ Vue-performance improvements

## [v2026.7.15-fa3c2b1c] — 2026-7-15

- 💫 Add animated icons
- ⬆️ Bump versions
- 💄 Improve RTE input

## [v2026.7.15-e01cb21e] — 2026-7-15

- ✨ Add onboarding guide for new spaces
- 💄 Improve theme
- 🐛 Fix test rot and the production bugs it was hiding
- ✨ Sync system roles from config on demand
- 🐛 Fix "Space not found" on broadcast events

## [v2026.7.14-fd53335d] — 2026-7-14

- ⬆️ Bump versions
- 💡 Improve docs

## [v2026.7.13-472ee9d6] — 2026-7-13

- 👷‍♂️ Fix docs build hang: restore specs clobbered by composer, make docs non-fatal

## [v2026.7.13-51ce183a] — 2026-7-13

- 👷‍♂️ Improve build workflow with vite press

## [v2026.7.13-651a9fc6] — 2026-7-13

- 🐛 Make doc CTAs absolute

## [v2026.7.13-4ad1de9d] — 2026-7-13

- 👷‍♂️ Publish docs in s3 bucket

## [v2026.7.13-423d4ba2] — 2026-7-13

- ⬆️ Bump versions
- 💡 Prepare docs
- ✨ Add asset collections & DAM sharing

## [v2026.7.12-ba8404e3] — 2026-7-12

- 🩹 Fix publishing
- 💄 Improve font in AlertDialog

## [v2026.7.12-e4f8fc87] — 2026-7-12

- 🐛 Fix clicking an item in the filtered content tree
- 🐛 Clear field dirty state when reverted to clean value
- 🚸 Allow multiline edits in data source entries

## [v2026.7.12-d7f7c3a4] — 2026-7-12

- ⚡️ Use system ui font
- ⬆️ Bump versions
- 🚸 Improve versions empty state
- 🚸 Improve avatar and access denied
- 🍱 Improve icons empty placeholder
- 💄 Fix redirects search bar appearance
- 💄 Fix ugly wrap in block detail header

## [v2026.7.12-c9a7dae1] — 2026-7-12

- 🧱 Reduce bundle size
- 💄 Improve TeamSelector in Appheader
- 🌐 Add loading and localization translation keys
- ✨ Add loading skeletons and dim-on-refetch states
- ♻️ Adopt Button loading prop across dialogs and forms
- ♻️ Replace hand-rolled spinners with Spinner component
- ✨ Add Spinner component and Button loading state
- ⚡ Add query caching, route/hover prefetch and faster navigation
- ✨ Add copy-source-text button to localization editor
- ✨ Add editable HTML classes to rich-text blocks
- 🐛 Use advanced range/date filter operators in RedirectFilter
- ✨ Add content translation import/export
- ✨ Unify external & internal link editing in the richtext toolbar
- ✨ Add fine-grained richtext config, Word paste cleanup & list styles
- 🐛 Fix translation preview pushes to match the site SDK contract
- ✨ Route visual-editor edits into the translation draft
- ✨ Add live collaboration to the translation editor
- 🐛 Fix block sub-field diff review findings
- ✨ Render typed sub-field diffs inside block item entries
- ✨ Sort folder children by first-level content fields
- ✨ Add per-content child sorting for content trees
- 🐛 Fix field diff review findings
- ✨ Add schema-aware diffs for all field types in version history
- 🐛 Fix rich text diff review findings
- ✨ Add proper rich text diff to content version history
- ✨ Apply block-defined default values when creating content
- ✨ Add content cache settings with TTL, tags and webhook delivery
- 💬 Add missing translations
- 👔 Extend preview URLs with flexible site locales
- ✨ Implement fuzzy search in content tree
- 🔒 Fix AI rights for content managments
- ⚡️ Drop redundant indexes to cut write overhead
- ⚡️ Add navigation indexes for content/asset filters
- ⚡️ Trim + paginate content version history query
- ⚡️ Keep searchable_content LONGTEXT out of delivery reads
- ⚡️ Add unique index on tokens.token for delivery lookups
- ⚡️ Use exists() over count()>0 in folder delete guards
- ⚡️ Drop per-keystroke deep-watch + deepClone in block editors
- ⚡️ Cut asset-manager selection/render churn
- ⚡️ Keep previous page data on paginated asset/icon queries
- ⚡️ Cut the content editor's per-keystroke stringify storm
- ⚡️ Key itemTrailIndex off a structure version, not a deep tree walk
- ⚡️ Stop TiptapEditor double-stringifying the doc per prop update
- ⚡️ Bundle lucide/flag icon collections instead of runtime API
- ⚡️ Make content menu get-children O(1) via memoized index
- 👔 Allow regional price codes like US-USD
- 🚸 Increase preview image size

## [v2026.7.8-b7cb2abb] — 2026-7-8

- 👔 Improve multi assets selection

## [v2026.7.8-87ce209c] — 2026-7-8

- 👔 Revert to single click action in AssetGrid
- 👔 Improve comments with live whisper

## [v2026.7.8-e434a6f1] — 2026-7-8

- 👔 Improve live collaboration dirty changes
- 🚸 Further improve image quality
- 🔧 Adapt default qualities
- 💄 Improve dirty indicator style

## [v2026.7.8-e2096e05] — 2026-7-8

- 👔 Improve live collaboration on content

## [v2026.7.8-7ab58350] — 2026-7-8

- 🚸 Improve preview quality in detail

## [v2026.7.8-9ec1f13d] — 2026-7-8

- 👔 Improve asset dialog
- 🚸 Improve image clearity in AM
- ⬆️ Bump versions
- 👔 Add block preview
- 👔 Fold hourly usage rows into single rows per day

## [v2026.7.7-807b07af] — 2026-7-7

- ⬆️ Bump versions
- 🩹 Fix lingering currentSpace issues
- ⬆️ Upgrade to php@8.5
- 👔 Auto-rotate exif images

## [v2026.7.6-d458602f] — 2026-7-6

- ⬆️ Bump versions
- 👔 Improve content validation

## [v2026.7.6-fdc7dc27] — 2026-7-6

- 👔 Use CommandPalette for add new block
- ✨ Allow renaming a schema field key
- 🐛 Fix v-model sync, dead-end states & a11y in SearchFilter
- 🐛 Import useFieldClipboard explicitly in SchemaEditor
- ✨ Duplicate and copy/paste schema fields across blocks
- ✨ Add changes/preview toggle to block version history
- ✨ Show where a block can be nested or referenced
- 🐛 Remove deleted block tags from blocks' tag arrays
- ✨ Finder-style selection & bulk actions in asset manager

## [v2026.7.3-5859c8de] — 2026-7-3

- ⚗️ Try to improve backfill in production

## [v2026.7.3-f142627f] — 2026-7-3

- 👔 Improve asset color detection

## [v2026.7.3-9ff7f3c2] — 2026-7-3

- 🚸 Improve overflowing issues
- 👔 Harden color and a11y extraction

## [v2026.7.3-bb26607e] — 2026-7-3

- ⬆️ Bump versions
- ✨ Add a11y image meta data and dominant color

## [v2026.7.2-128fac09] — 2026-7-2

- 🩹 Fix icon issues

## [v2026.7.2-6b767c75] — 2026-7-2

- 🐛 Commit icon files hidden by a broken global gitignore rule
- ✨ Add icon-set import UX to the icon manager
- ✨ Add Iconify icon-set import API for spaces
- 🩹 Introduce SubscriptionStatus enum as billing status source of truth
- 🧹 Remove unused frontend dependencies
- 🔒 Split CORS: open public delivery API, lock down management API
- 🔒 Guard image processing against decompression bombs
- 🔒 Harden icon CSS params and constrain ilum name segment
- 🔒 Stop account enumeration on one-time-token endpoints
- 🩹 Restore currentSpace after space-scoped services run
- ⏪ Revert CORS allowlist — delivery API serves unknown tenant origins
- ✅ Add tenant-isolation regression tests
- 🩹 Cap per_page on list endpoints to bound query cost
- 🩹 Fix QueuedJob failure semantics, tenant leak and runtime env()
- ⚡ Stream backups and drop a per-row query in space migration
- ⚡ Add indexes for redirect lookups and cross-space identity
- 🔒 Block SSRF, webhook replay, token log leak and invite over-disclosure
- 🔒 Restrict CORS to an allowlist and throttle OTP login
- 🔒 Sanitize CMS-controlled markup in the editor UI
- 🔒 Block active-content uploads and harden asset delivery
- 🔒 Enforce tenant isolation on space-scoped routes

## [v2026.7.1-a99b5b13] — 2026-7-1

- 👔 Allow to define a stroke width

## [v2026.7.1-86295515] — 2026-7-1

- 🩹 Fix video thumbnail delivery

## [v2026.7.1-5fa2bb1a] — 2026-7-1

- ✨ Add asset versioning and rights management

## [v2026.7.1-c67f001e] — 2026-7-1

- 🚸 Streamline asset manager UX
- 🐛 Fix asset manager issues

## [v2026.6.30-6f99ea4b] — 2026-6-30

- 👔 Further improve the asset manager

## [v2026.6.30-0e2f749b] — 2026-6-30

- 👔 Improve Asset Manager
- 🚸 Handle video assets better

## [v2026.6.30-53a71970] — 2026-6-30

- ⬆️ Bump versions
- ✨ Add price field

## [v2026.6.29-4c95e2a] — 2026-6-29

- 🐛 Fix issues identified in error log

## [v2026.6.29-6fe57f1] — 2026-6-29

- ⬆️ Bump versions
- 🩹 Improve dirty detection

## [v2026.6.29-ae74f30] — 2026-6-29

- 🩹 Fix dirty detection

## [v2026.6.29-654f909] — 2026-6-29

- 🩹 Fix saving issue

## [v2026.6.29-0eede65] — 2026-6-29

- 👔 Improve icon handling
- ♻️ Fix import issues
- 🚸 Simplify sidebar
- ⬆️ Bump versions
- ♻️ Extract shared AI-stream, Iconify & notification frontend modules
- ♻️ Harden AI drivers & de-duplicate stream controllers
- ✨ Improve AI system prompts and fix Bedrock system prompt
- ✨ Stream all AI features with fail-closed quota & coded error UX
- ✨ Add in-app notifications with read-gated email fallback
- ✨ Add geo coordinates field type
- ✨ Make Teams Management production-ready
- ✨ Add icon registry field with Iconify integration
- ✨ Broadcast space model changes via Reverb for real-time cache invalidation
- ✨ Detect and handle content version conflicts on save
- 🩹 Fix teams and spaces dropdowns
- 🌍 Add missing translations to i18n files
- ✨ Implement content sorting
- 🔒 Fix roles
- ✨ Track historic subscription data
- ✨ Add proper date range filter to audit log
- ⬆️ Bump versions
- 👔 Handle space subscriptions
- 👔 Improve handling of malformed ilum request

## [v2026.6.19-0e60a54] — 2026-6-19

- 👔 Allow to actually translate date fields

## [v2026.6.19-b78f295] — 2026-6-19

- 👔 Allow date fields to be translated

## [v2026.6.18-3cb23c6] — 2026-6-18

- 👔 Further improve translation handling
- 👔 Improve translation overlay handling in data API

## [v2026.6.17-690a67d] — 2026-6-17

- 👔 Improve content filtering for canonicals

## [v2026.6.17-1ad5f73] — 2026-6-17

- ✨ Add canonical_id and canonical_parent_id as content filter

## [v2026.6.17-7fc0a93] — 2026-6-17

- 👔 Improve content field localization
- 🐛 Fix verification resend

## [v2026.6.17-f6048e3] — 2026-6-17

- ✨ Handle orphaned translated content

## [v2026.6.16-aa67189] — 2026-6-16

- 🐛 Improve translation handling
- 🐛 Fix overflow for multi assets

## [v2026.6.15-0d79744] — 2026-6-15

- 👔 Handle language overlay via id

## [v2026.6.15-2f90cd2] — 2026-6-15

- 👔 Adapt slug handling for translated content
- ⚡️ Improve sitemap generation

## [v2026.6.10-6e7eed8] — 2026-6-10

- ✨ Add import/export to data entries
- 👔 Improve RTE experience
- ✨ Allow to filter for missing data entries translations
- 👔 Improve rich text search indexing
- 🐛 Fix data entries translation not handling all

## [v2026.6.10-79f7571] — 2026-6-10

- ✨ Add quick publish action to content tree

## [v2026.6.9-9d34e47] — 2026-6-9

- ✨ Allow to use params with internal links

## [v2026.6.8-6ecd148] — 2026-6-8

- ⬆️ Bump versions
- 🚸 Improve multi assets UX
- 🚸 Add padding to prevent AI overlap

## [v2026.6.7-678e1f4] — 2026-6-7

- 🚸 Improve Link handling for emails

## [v2026.6.6-0dd9fc6] — 2026-6-6

- 🐛 Fix content publish/unpublish triggers: use wasChanged/getOriginal
- 🔖 Release v2026.6.6-f212e59
- ✨ Add content.published and content.unpublished automation triggers
- 🐛 Fix boot-time circular instantiation in BroadcastsModelEvents trait
- 👔 Fix PHP 8.4 deprecation: explicit nullable type in Action value object
- 👔 Fix rebase: remove incompatible laravel-finite dep, add navigation i18n keys
- ✨ Add automations engine
- 👔 Improve redirects import

## [v2026.6.6-f212e59] — 2026-6-6

- ✨ Add content.published and content.unpublished automation triggers
- 🐛 Fix boot-time circular instantiation in BroadcastsModelEvents trait
- 👔 Fix PHP 8.4 deprecation: explicit nullable type in Action value object
- 👔 Fix rebase: remove incompatible laravel-finite dep, add navigation i18n keys
- ✨ Add automations engine
- 👔 Improve redirects import

## [v2026.6.6-f88ea97] — 2026-6-6

- 👔 Handle local links within richtext

## [v2026.6.6-29d32a6] — 2026-6-6

- 🐛 Dedupe prosemirror to avoid issues

## [v2026.6.5-3614cc8] — 2026-6-5

- 🐛 Correctly handle unsaved watcher
- 🐛 Correctly build the history preview URL
- 🐛 Fix the diff history view

## [v2026.6.5-d865cf9] — 2026-6-5

- ✨ Allow to reduce resultset with take or except query params
- ✨ Allow to sort contents against a content field

## [v2026.6.5-ff44cca] — 2026-6-5

- 🐛 Another use of parent_id in slug uniqueness validation

## [v2026.6.5-751095d] — 2026-6-5

- ⬆️ Bump versions
- 🐛 Use parent_id in slug uniqueness validation

## [v2026.6.5-db2b5eb] — 2026-6-5

- 🐛 Disable trim text

## [v2026.6.5-4189bd0] — 2026-6-5

- 🐛 Don't trim text from tiptap
- 🐛 Remove whitespace preserve

## [v2026.6.5-54d1338] — 2026-6-5

- 🐛 Preserve whitespace in TipTap

## [v2026.6.3-21848f9] — 2026-6-3

- 🧱 Switch to a single instance reverb setup

## [v2026.6.3-240dc7e] — 2026-6-3

- 🔧 Fix crashes

## [v2026.6.3-7bd8554] — 2026-6-3

- 🔊 Log php errors

## [v2026.6.3-dbdab5a] — 2026-6-3

- 🔊 Use log level warn for more output

## [v2026.6.3-92699bf] — 2026-6-3

- ⬆️ Bump versions

## [v2026.6.2-1266b10] — 2026-6-2

- ⬆️ Bump versions
- 👔 Handle richtext translation values
- 💡 Add madewithvue badge

## [v2026.5.30-55a4f58] — 2026-5-30

- 🐛 Prevent empty defaults on tree operations

## [v2026.5.30-e526f3f] — 2026-5-30

- 👔 Improve meta tags generation

## [v2026.5.29-a98862f] — 2026-5-29

- ⬆️ Bump versions
- 🐛 Fix creating content from templates
- 🐛 Fix option field validation
- 🚸 Improve MultiAssets
- 🚸 Remember last folder locations for asset selection

## [v2026.5.26-dc50492] — 2026-5-26

- 🩹 Use crop=fit for asset detail dialog

## [v2026.5.24-eabcbb6] — 2026-5-24

- ✨ Implement data entries translations
- ⬆️ Bump versions

## [v2026.5.17-50ab758] — 2026-5-17

- ⬆️ Bump PHP versions

## [v2026.5.17-13132f8] — 2026-5-17

- ⬆️ Bump versions
- ⬆️ Bump versions

## [v2026.5.7-c13cdca] — 2026-5-7

- ⬆️ Bump versions
- 🩹 Fix redirect

## [v2026.5.6-50f3f41] — 2026-5-6

- ⬆️ Bump versions
- 👔 Redirect to app.url if otherwise reached web frontend

## [v2026.5.6-2889a35] — 2026-5-6

- 👔 Expose external_id in content api
- 👔 Allow to manage external_id

## [v2026.5.6-aca0e4f] — 2026-5-6

- ⬆️ Bump versions
- 🐛 Prefix external_id with table

## [v2026.5.4-b08b646] — 2026-5-4

- 👔 Link to software sites
- 🍱 Add marketing assets
- ⬆️ Bump versions

## [v2026.5.1-413e08e] — 2026-5-1

- 🔧 Increase sensible defaults for images

## [v2026.5.1-d187b6d] — 2026-5-1

- ⬆️ Bump versions
- 🔧 set sensible defaults for image qualities

## [v2026.4.29-c02b4f8] — 2026-4-29

- 🧑🏻‍💻 Improve example .env
- 💡 Address pitfalls for local development in readme
- 🗃️ Make role_id nullable to accomodate `nullOnDelete`

## [v2026.4.28-39d6e21] — 2026-4-28

- 🔧 Rename echo host

## [v2026.4.28-71c650f] — 2026-4-28

- ⬆️ Fix versions

## [v2026.4.28-b868cd6] — 2026-4-28

- ⬇️ downgrade versions

## [v2026.4.28-7d3e979] — 2026-4-28

- 🔧 use correct public host
- ⬆️ Bump versions
- 💡 Improve readme

## [v2026.4.26-90bce68] — 2026-4-26

- 🐛 Resolve translated slugs correctly

## [v2026.4.26-d18dad6] — 2026-4-26

- 👔 Use system prompt for each AI interaction
- 🚸 Prevent default values spill into new localization
- 🚸 Fix conten tree padding/overlap
- ⬆️ Upgrade to Laravel v13
- ✨ Add SAML2 auth self service

## [v2026.4.25-354a5b0] — 2026-4-25

- ⬇️ Downgrade vite due to build errors

## [v2026.4.25-24223ca] — 2026-4-25

- ⬆️ Bump versions

## [v2026.4.25-377078a] — 2026-4-25

- ✨ Add social logins

## [v2026.4.25-89b2a54] — 2026-4-25

- ⬆️ Bump versions
- ⬆️ Bump versions

## [v2026.4.22-b5134bd] — 2026-4-22

- 🩹 Fix publish date issue

## [v2026.4.21-c36a62b] — 2026-4-21

- 🚸 Improve Stats
- 🔧 Pass public ENV based config via laravel to vue

## [v2026.4.21-45b508c] — 2026-4-21

- ✨ Add provider specific notes and help menu
- ⬆️ Bump versions
- 👔 improve handlebars preview

## [v2026.4.19-f71a4f7] — 2026-4-19

- ⚡️ Improve canvas performance for large trees
- 🚸 Improve space icon

## [v2026.4.19-cea7c38] — 2026-4-19

- 🍱 Replace favicons
- ⚡️ Cache content-menu and reduce payload

## [v2026.4.19-f667c2b] — 2026-4-19

- 💄 Improve StatsCard
- 👔 Improve redirects management with add and import/export

## [v2026.4.18-88ddeb4] — 2026-4-18

- 🐳 Add redis as native php module

## [v2026.4.18-e71edb4] — 2026-4-18

- ✨ Add animate image support

## [v2026.4.18-538f006] — 2026-4-18

- 🩹 Fix some vips based transformations
- 👔 add lower case access control headers
- ⬆️ Bump versions

## [v2026.4.18-691bc1e] — 2026-4-18

- 👔 Improve content publishing and updating with inlined translation support
- 👔 Use fit as default operation if both width and height are supplied
- 👔 Add link localization and better localization validation

## [v2026.4.17-4ecba87] — 2026-4-17

- 👔 Response with the original if no transformations are supplied
- 👔 Expose the full_path from the actual asset

## [v2026.4.15-cecde37] — 2026-4-15

- Revert "⚡️ Send cache header for web response"

## [v2026.4.15-716ad8c] — 2026-4-15

- 👔 Improve ilum endpoints
- ⬆️ Bump versions
- ⚡️ Send cache header for web response

## [v2026.4.15-4acd831] — 2026-4-15

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

## [v2026.4.13-3a9c822] — 2026-4-13

- 💄 Improve UX for production data
- ✏️ Fix typo in tablename
- 🔧 Increase acceptable content size to 500mb
- 🐛 Fix folder validation in UpdateBlockRequest

## [v2026.4.12-8a2ce52] — 2026-4-12

- ⬆️ Bump versions
- 🚸 Improve UX of settings table add

## [v2026.4.9-0861ac9] — 2026-4-9

- ⬆️ Bump versions
- ✨ Improve SEO with advanced meta field and a sitemap API
- 💄 Improve stats rendering
- 👔 Prevent auto translation creation

## [v2026.4.7-259de17] — 2026-4-7

- 👔 Bump versions
- 👔 Improve meta and translation AI triggers

## [v2026.4.6-a72497c] — 2026-4-6

- ⬆️ Bump versions
- 🚸 Improve content validation feedback

## [v2026.4.4-f61b804] — 2026-4-4

- ✨ Add table block type

## [v2026.4.4-54a754f] — 2026-4-4

- 👔 Improve template-based block adding
- 🚸 Improve ContentHeader slots
- 👔 Adapt data source active flag handling
- ✨ Add multi options block type and allow to use data sets as source
- 🐛 Disable content tree horizontal scroll
- 🐛 Fix force deleting used assets
- ✨ Add space blueprints for reusable space setups
- 🚸 Prevent audit-log caching

## [v2026.4.3-472a6ae] — 2026-4-3

- ✨ Add basic space internal audit logs
- ✨ Handle asset usage tracking
- ✨ Extract and resolve content relations
- ✨ Allow to restrict child block types
- ⬆️ Bump versions

## [v2026.3.28-faafed2] — 2026-3-28

- 🐛 Fix focus fallback for assets

## [v2026.3.26-caaff06] — 2026-3-26

- ✨ Add ContextMenu to ContentTree
- ⬆️ Bump versions
- 👔 Improve Localization and fix some issues

## [v2026.3.24-353cde7] — 2026-3-24

- 🐛 Fix auth guard for new space

## [v2026.3.24-36ef892] — 2026-3-24

- 👔 Use a new ContentSelect for reference block

## [v2026.3.24-1c39792] — 2026-3-24

- 🔇 Remove console.log
- 📈 Identify user with posthog

## [v2026.3.24-edd2112] — 2026-3-24

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

## [v2026.3.24-93122ea] — 2026-3-24

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
