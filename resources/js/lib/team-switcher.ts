/**
 * Search, recents and tree helpers for the team switcher and the team
 * structure chart. Lives in `lib/` so composables and components can share
 * it without the auto-import sibling trap.
 */
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import type { HierarchicalTeam, TeamTreeEntry } from '~/lib/team-hierarchy'

export interface TeamFilterRow<T extends HierarchicalTeam> {
  team: T
  depth: number
  /** Matched characters of the name; absent on ancestor rows shown for context. */
  indices?: number[]
  isMatch: boolean
}

export interface TeamFilterResult<T extends HierarchicalTeam> {
  rows: TeamFilterRow<T>[]
  /** Row index of the best-scoring match, the entry point for the keyboard. */
  best: number
}

/**
 * Filters the tree like the content tree search: every team whose name
 * fuzzy-matches the query, plus the ancestor chain that puts it in context,
 * in tree order. A blank query returns no rows.
 */
export function filterTeamTree<T extends HierarchicalTeam>(
  query: string,
  entries: readonly TeamTreeEntry<T>[]
): TeamFilterResult<T> {
  const normalized = prepareFuzzyQuery(query)
  if (!normalized) return { rows: [], best: -1 }

  const parentOf = new Map(entries.map(({ team }) => [team.id, team.parent_id ?? null]))
  const matches = new Map<string, { score: number; indices: number[] }>()
  for (const { team } of entries) {
    const match = fuzzyMatch(normalized, prepareFuzzyTarget(team.name))
    if (match) matches.set(team.id, match)
  }

  const visible = new Set(matches.keys())
  for (const id of matches.keys()) {
    let parentId = parentOf.get(id) ?? null
    while (parentId && !visible.has(parentId)) {
      visible.add(parentId)
      parentId = parentOf.get(parentId) ?? null
    }
  }

  let best = -1
  let bestScore = -Infinity
  const rows: TeamFilterRow<T>[] = []
  for (const { team, depth } of entries) {
    if (!visible.has(team.id)) continue
    const match = matches.get(team.id)
    if (match && match.score > bestScore) {
      bestScore = match.score
      best = rows.length
    }
    rows.push({ team, depth, indices: match?.indices, isMatch: !!match })
  }

  return { rows, best }
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
