/**
 * Flattening a flat team list into a depth-tagged, parent-before-child order.
 *
 * Lives in `lib/` rather than next to a composable on purpose: see the note in
 * `global-team.ts` about the auto-import sibling trap.
 */

export interface HierarchicalTeam {
  id: string
  name: string
  parent_id?: string | null
}

export interface TeamTreeEntry<T extends HierarchicalTeam> {
  team: T
  depth: number
}

interface TreeNode<T extends HierarchicalTeam> {
  team: T
  children: TreeNode<T>[]
}

/**
 * Order teams parent-first, siblings by name, and tag each with its nesting
 * depth. A team whose parent is missing from the list — filtered out, or on
 * another page — becomes a root, so nothing silently disappears.
 */
export function flattenTeamHierarchy<T extends HierarchicalTeam>(
  teams: readonly T[]
): TeamTreeEntry<T>[] {
  const nodes = new Map<string, TreeNode<T>>()
  for (const team of teams) {
    nodes.set(team.id, { team, children: [] })
  }

  const roots: TreeNode<T>[] = []
  for (const team of teams) {
    const node = nodes.get(team.id)!
    const parentId = team.parent_id ?? null
    const parent = parentId && parentId !== team.id ? nodes.get(parentId) : undefined

    if (parent) {
      parent.children.push(node)
    } else {
      roots.push(node)
    }
  }

  const sortByName = (siblings: TreeNode<T>[]) => {
    siblings.sort((a, b) => a.team.name.localeCompare(b.team.name))
    for (const node of siblings) sortByName(node.children)
  }
  sortByName(roots)

  const flat: TeamTreeEntry<T>[] = []
  const walk = (siblings: TreeNode<T>[], depth: number) => {
    for (const node of siblings) {
      flat.push({ team: node.team, depth })
      if (node.children.length) walk(node.children, depth + 1)
    }
  }
  walk(roots, 0)

  return flat
}

/**
 * Collapse an already-nested tree (as `/teams/hierarchy` returns it) into a
 * flat list. Every node carries its own `parent_id`, so the result can be fed
 * straight back into {@link flattenTeamHierarchy}.
 *
 * `skipId` drops that team *and everything below it* — the shape a re-parent
 * picker needs, where a team may not move under one of its own descendants.
 */
export function flattenNestedTeams<T extends HierarchicalTeam & { children?: T[] }>(
  items: readonly T[],
  skipId?: string | null
): T[] {
  const flat: T[] = []

  const walk = (nodes: readonly T[]) => {
    for (const node of nodes) {
      if (skipId && node.id === skipId) continue
      flat.push(node)
      if (node.children?.length) walk(node.children)
    }
  }
  walk(items)

  return flat
}
