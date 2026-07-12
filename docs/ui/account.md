---
description: "Your account, teams, and spaces: profile, security, personal access tokens, and memberships."
---

# Account, Teams & Spaces

Settings that live above any single space: your personal account, the teams you belong to, and the spaces overview.

## Account

- **Profile** — name, avatar, and your read-only email and user ID.
- **Security** — change your password and manage **two-factor authentication** (with recovery codes shown at setup).
- **Personal access tokens** — API tokens for the [Management API](../api/overview.md): scripts and integrations authenticate as you with a `Bearer` token. Treat them like passwords; revoke what you don't use.
- **Invitations** — pending invites you've received to spaces and teams; accept or decline.
- **Notifications** — in-app and email notification preferences.

## Teams

Teams group people and spaces for organizations:

- **Create a team**, invite people, and assign **team roles** that control what members may do (e.g. whether they can create spaces in the team).
- A team's spaces are visible to its members according to their roles — no per-space invites needed for every hire.
- The team detail page manages **people** (members + pending invites, resend/revoke) and lists the team's **spaces**.
- Teams support hierarchy for larger organizations.

Per-space access on top of team access is managed in each space's **Settings → People** ([Space settings](settings.md#people)).

## Spaces overview

The home screen after login lists every space you can access — directly or via teams — with quick creation of new spaces (name, team, plan on billed installations). See [Spaces](../concepts/spaces.md) for what a space contains.
