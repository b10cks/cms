# Team management and provider console

Status: direction agreed with Mike on 2026-10-31, mockups awaiting greenlight. Mockups: `.claude/mockups/team-management/index.html` (gitignored, dark mode, open in a browser).

Internal briefing. Excluded from the public docs build (`srcExclude` in `docs/.vitepress/config.ts`).

## Goal

Team owners should see their whole organisation in one place: who has access to what, why, and what needs their attention. Agencies with client sub-teams and single companies count equally, so hierarchy only shows when a team has children.

Root users run the instance from one console, and they act inside a tenant only on purpose, with a reason and a trail.

## Settled decisions

1. **Team workspace.** The team area uses a left nav like space settings: Overview, People, Spaces, Roles, Security, AI, Activity, Settings. The hierarchy tree sidebar and the header tabs go away.
2. **Overview.** It starts with a KPI row of `StatTile`s: members, spaces, pending invites, expiring access, SSO status. Below it sits the structure panel, an org chart of teams with their spaces as leaves. Then come "Needs attention" and "Activity".
3. **Moving teams.** You drag a team onto another to move it. The confirm dialog lists what changes: who gains or loses inherited access, which custom roles start or stop applying, and that direct space roles stay. Moving to the top level stays root-only (`TeamController` rule).
4. **People.** Each row shows the team role, a summary of the person's access with its source (team role, direct, mixed, pending), the sign-in source, the last activity and expiry.
   - An "Access matrix" view shows people by spaces. Dashed cells are inherited from a team role, solid cells are direct. Cells are editable inline.
   - Cells the actor may not change are read-only, with the reason in a tooltip. `MembershipGuard` and the last-owner rule stay the authority.
5. **Space people.** Space Settings → People uses the same component. It shows inherited members next to direct ones, with their source.
6. **Team switcher.** The tree moves into the switcher. Three candidates are mocked: (A) a search popover with recent teams and the nested tree, (B) a breadcrumb path, (C) a breadcrumb whose segments open scoped search popovers. Mike picks after review. A is recommended.
7. **Team activity.** A team Activity page shows the membership audit `MembershipService::auditMembership` already writes, plus SSO and settings changes. A team-scoped audit route is needed, because today only `spaces/{space}/audit-logs` exists.
8. **Verified domains.** Out of scope for now. The built version (DNS verification, request to join, auto-join, require SSO) is parked on `feature/verified-team-domains`.
9. **Expiring access.** Invites and memberships at team and space level can carry an optional "access until" date.
   - A scheduled job removes expired access through `MembershipService`, so it is audited.
   - Overview lists access ending within 14 days.
   - No access reviews.
10. **Provider console.** `/provider` grows into the instance console. It keeps its route, root gate and notes. Nav: Dashboard, Tenants, Users, Usage, Audit, Health, Notes.
11. **Elevate on demand.**
    - Root opens any team as a viewer, with a banner and team actions disabled.
    - "Elevate" asks for a reason and a duration (30 minutes by default). It unlocks override actions instance-wide. The reason goes to the instance audit only, since an elevation has no team.
    - Today `AuthorizationService` grants root everything unconditionally. Elevation becomes the condition for write abilities.
12. **Impersonation.** The UI is built on the existing `POST/DELETE /impersonate`. It needs a reason, ends after 1 hour and shows a persistent banner. The existing `not-impersonating` blocks stay. Starting it requires a running elevation; returning never does.
13. **Suspend.** Suspending blocks login, ends sessions and revokes personal access tokens. Memberships and content stay, so restoring loses nothing. If the user is the last owner of a team, the dialog asks for a new owner, or flags the team as "No owner" in Tenants.
14. **Investor dashboard.** The provider Dashboard is root-only and has three sections: Revenue, Growth, and Engagement and delivery.
    - **Time framing:** monthly buckets over the trailing 12 months, with a range picker. Tiles show month-over-month change through `ChangeIndicator`, and year-over-year where data exists. There is a revenue retention grid by start month.
    - **Sharing:** a "Present" mode hides the chrome, and Export produces PNG and PDF. There is no public link.

## Screens

### Team overview

The page title is the team name with avatar and type badge, plus an info icon and no subtitle. Primary actions: Sub-team and Invite.

The structure panel draws the org chart on a dotted canvas. Each node shows the team avatar, a stack of three people, a people count, and its spaces. Each space carries a dot: green when it has published content, gray when it doesn't. The current team has the accent border. Drop targets use `bg-tree-drop`. A "List" toggle falls back to the indented tree for very large hierarchies.

"Needs attention" holds the items that need an owner's action. Each has one action button:
- expiring access
- invites older than 7 days
- SAML certificates expiring within 30 days
- teams without an owner

### People

The search sits on the left, next to the segments All, Members, Pending and Expiring, each with a count. The List and Access matrix toggle sits on the right.

In the matrix, columns are spaces and can be filtered to one child team. An empty cell offers "+", which adds a direct role.

### Provider console

- **Tenants:** a tree table of teams with type, spaces, people, MRR, SSO and last activity. Flags: No owner, Trial ended, Payment failed. "Open" enters the team as a root viewer.
- **Users:** every user, with segments Active 30d, Suspended and Root. A row opens a sheet with three counts (teams, sessions, tokens), the memberships, recent activity, and the actions: Sign in as, Sign out everywhere, Transfer ownership, Suspend.
- **Usage:** per top-level team, the month's plan revenue, AI spend, and storage, API and traffic as a share of quota. Flags for storage above 85 %, failed payments and ended trials.
- **Audit and Health:** not mocked. Audit is the instance stream: impersonations, elevations, suspensions, ownership transfers, SSO changes. Health covers queue depth, failed jobs and space DB connectivity.

### Investor dashboard metrics

| Section | Figures | Source |
| --- | --- | --- |
| Revenue | MRR, ARR, net new MRR (new, expansion, churn), paying spaces, ARPA per paying team, revenue and logo churn, revenue by plan, retention grid | `SubscriptionPeriod.price` per period, normalised to monthly |
| Growth | new users, spaces, teams per month | `users`, `spaces`, `teams` created_at |
| Engagement | delivery API requests, CDN traffic | `SpaceApiHitHourly`, `SpaceTrafficUsageHourly`, `SpaceDownloadUsageHourly` |

Self-hosted (`EditionGate::billingEnabled()` false) hides Revenue. Growth and Engagement stay.

Figures that need per-space DB data (activation, weekly active editors, entries published, the AI section) are out of scope for now. They need the hourly `provider:collect-activity` rollup, which is parked with them on `feature/provider-activity`.

Definitions as built (`ProviderMetricsService`, `GET provider/metrics?range=6m|12m|24m|ytd`, cached 15 minutes):

- **MRR** is the sum of paid periods running at the end of each month: LemonSqueezy-backed, price above zero, a yearly price divided by 12, counted from the end of a trial. An account is the top-level team, so a new client space of an agency is expansion, not a new account.
- **Revenue churn** is contraction plus lost MRR over last month's MRR. **Logo churn** is the share of last month's paying accounts that pay nothing now.
- **API requests and traffic** come from the CloudFront hourly tables, traffic including asset package downloads, as on the space dashboard.
- Flows compare month to date with the same days of last month and of the month a year ago. Stocks compare with the value at the end of those months.

## What changes in the backend

- `AuthorizationService`: root write abilities require an active elevation, stored per user with an expiry and a reason. Reads stay unconditional.
- Users get `suspended_at`, `suspended_by` and `suspension_reason`, and an auth guard check. Suspending revokes Sanctum tokens and sessions.
- `expires_at` on `team_user` and `space_user`, and `access_expires_at` on `invites` (its `expires_at` is the link expiry). The `memberships:expire` sweep runs every five minutes through `MembershipService` and never removes a last owner.
- A team-scoped audit endpoint, a provider audit endpoint, provider users and tenants endpoints, and provider metrics endpoints with monthly aggregation (cached).

## Out of scope

Seat limits, SCIM provisioning, access reviews, verified domains (`feature/verified-team-domains`), the per-space activity rollup and the figures built on it (`feature/provider-activity`), and a redesign of the roles ability matrix. Roles, Security, Settings and AI keep their current content and move into the new nav as they are.

## Rules for DESIGN.md once greenlit

- The team area and the provider console use the settings-style left nav. No header tabs.
- Inherited access is drawn dashed and direct access solid, everywhere access is shown.
- Root context always shows a banner: neutral for viewing, warning for elevated, AI purple for impersonation.
