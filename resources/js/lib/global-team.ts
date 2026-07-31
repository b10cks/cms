/**
 * The selected team must resolve from one cache entry everywhere — the router
 * guard (`ensureSelectedTeamAccess`) and the team switcher read the same list
 * under this exact key, so a mismatch would give them two different teams.
 *
 * This lives in `lib/` rather than next to `useGlobalTeam` on purpose:
 * `unplugin-auto-import` scans `resources/js/composables`, and a composable
 * that explicitly imports from a sibling composable drops that sibling's
 * exports out of the auto-import map — which silently turns every
 * `useGlobalTeam()` call site into a bare global (`ReferenceError` at runtime).
 */
export const GLOBAL_TEAM_QUERY_PARAMS = { include_space_context: true, per_page: 1000 }
