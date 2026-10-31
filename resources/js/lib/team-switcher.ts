/**
 * Search, recents and tree helpers for the team switcher and the team
 * structure chart. Lives in `lib/` so composables and components can share
 * it without the auto-import sibling trap.
 */
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import type { HierarchicalTeam, TeamTreeEntry } from '~/lib/team-hierarchy'

export interface TeamSearchMatch<T extends HierarchicalTeam> {
  team: T
  depth: number
  score: number
  indices: number[]
}

/**
 * Teams whose name fuzzy-matches the query, best match first. Ties keep tree
 * order, so a parent comes before a child with the same score.
 */
export function searchTeams<T extends HierarchicalTeam>(
  query: string,
  entries: readonly TeamTreeEntry<T>[]
): TeamSearchMatch<T>[] {
  const normalized = prepareFuzzyQuery(query)
  if (!normalized) return []

  const matches: (TeamSearchMatch<T> & { order: number })[] = []
  entries.forEach(({ team, depth }, order) => {
    const match = fuzzyMatch(normalized, prepareFuzzyTarget(team.name))
    if (match) matches.push({ team, depth, score: match.score, indices: match.indices, order })
  })

  return matches
    .sort((a, b) => b.score - a.score || a.order - b.order)
    .map(({ order: _order, ...match }) => match)
}

export const RECENT_TEAMS_LIMIT = 5

/** Put a team at the front of the recent list, without duplicates, capped at `limit`. */
export function pushRecentTeam(
  recent: readonly string[],
  teamId: string,
  limit = RECENT_TEAMS_LIMIT
): string[] {
  return [teamId, ...recent.filter((id) => id !== teamId)].slice(0, limit)
}

export interface TeamTreeNode<T extends { id: string; parent_id: string | null }> {
  team: T
  children: TeamTreeNode<T>[]
}

/**
 * Nest a flat list (parent before or after child, any order) into trees.
 * Siblings keep their input order.
 */
export function nestTeams<T extends { id: string; parent_id: string | null }>(
  teams: readonly T[]
): TeamTreeNode<T>[] {
  const nodes = new Map(teams.map((team) => [team.id, { team, children: [] as TeamTreeNode<T>[] }]))
  const roots: TeamTreeNode<T>[] = []

  for (const team of teams) {
    const node = nodes.get(team.id)!
    const parent = team.parent_id ? nodes.get(team.parent_id) : undefined
    if (parent && parent !== node) parent.children.push(node)
    else roots.push(node)
  }

  return roots
}

/**
 * Whether `teamId` may be dropped onto `targetId`: not onto itself, its
 * current parent, or anything below it.
 */
export function canMoveTeam<T extends { id: string; parent_id: string | null }>(
  teams: readonly T[],
  teamId: string,
  targetId: string
): boolean {
  if (teamId === targetId) return false

  const byId = new Map(teams.map((team) => [team.id, team]))
  if (byId.get(teamId)?.parent_id === targetId) return false

  let current = byId.get(targetId)?.parent_id ?? null
  const seen = new Set<string>()
  while (current && !seen.has(current)) {
    if (current === teamId) return false
    seen.add(current)
    current = byId.get(current)?.parent_id ?? null
  }

  return true
}
