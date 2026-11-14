import type { BlockStamp } from '~/lib/localizationCollab'

export type StructureStatus = 'current' | 'outdated' | 'missing'

export interface StructureField {
  id: string
  path: Array<string | number>
  blockStamps?: BlockStamp[]
  status: StructureStatus | null
}

/** A section of the translation list: an editor page of the entry, or a block item. */
export interface StructureNode {
  id: string
  label: string
  /** Block name for block items, null for editor pages. */
  type: string | null
  depth: number
  parentId: string | null
  /** Ids from the top section down to this one, this one included. */
  chain: string[]
  counts: Record<StructureStatus, number>
}

export interface TranslationStructure {
  /** In list order, parents before their children. */
  nodes: StructureNode[]
  nodeById: Map<string, StructureNode>
  /** The innermost section a field sits in. */
  sectionOf: Map<string, string>
}

const pageNodeId = (header: string) => `page:${header}`

/** Each block item id with its position in its list, read from the source content. */
export const collectBlockPositions = (content: unknown, positions = new Map<string, number>()) => {
  if (Array.isArray(content)) {
    content.forEach((item, index) => {
      if (item && typeof item === 'object' && typeof (item as { id?: unknown }).id === 'string') {
        positions.set((item as { id: string }).id, index)
      }
      collectBlockPositions(item, positions)
    })
  } else if (content && typeof content === 'object') {
    Object.values(content).forEach((value) => collectBlockPositions(value, positions))
  }

  return positions
}

/**
 * Sections of a translation list. Top-level fields group by the editor page
 * that lists them (only when the entry has more than one page), block items
 * nest under the page holding their list field and under their parent block.
 */
export const buildTranslationStructure = (
  fields: StructureField[],
  options: {
    editor: EditorPage[] | null | undefined
    blockName: (slug: string) => string
    positions: Map<string, number>
  }
): TranslationStructure => {
  const pages = (options.editor ?? []).filter((page) => page?.items?.length)
  const usePages = pages.length > 1
  const pageOf = (key: string | number): string | null =>
    usePages ? (pages.find((page) => page.items.includes(String(key)))?.header ?? null) : null

  const nodes: StructureNode[] = []
  const nodeById = new Map<string, StructureNode>()
  const sectionOf = new Map<string, string>()

  const ensure = (node: Omit<StructureNode, 'counts' | 'chain'>): StructureNode => {
    const existing = nodeById.get(node.id)
    if (existing) return existing

    const parent = node.parentId ? nodeById.get(node.parentId) : undefined
    const created = {
      ...node,
      chain: [...(parent?.chain ?? []), node.id],
      counts: { current: 0, outdated: 0, missing: 0 },
    }
    nodes.push(created)
    nodeById.set(created.id, created)
    return created
  }

  fields.forEach((field) => {
    const page = pageOf(field.path[0])
    let parent = page
      ? ensure({ id: pageNodeId(page), label: page, type: null, depth: 0, parentId: null })
      : null

    field.blockStamps?.forEach((stamp) => {
      const name = options.blockName(stamp.block)
      const position = options.positions.get(stamp.id)
      parent = ensure({
        id: stamp.id,
        label: position === undefined ? name : `${name} ${position + 1}`,
        type: name,
        depth: (parent?.depth ?? -1) + 1,
        parentId: parent?.id ?? null,
      })
    })

    if (!parent) return
    sectionOf.set(field.id, parent.id)
    if (field.status) {
      parent.chain.forEach((id) => {
        nodeById.get(id)!.counts[field.status!] += 1
      })
    }
  })

  return { nodes, nodeById, sectionOf }
}
