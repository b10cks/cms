import { describe, expect, it } from 'vitest'

import type { ContentWizardDraftNode } from '~/types/content-wizard'
import {
  CONTENT_WIZARD_CARD_HEIGHT,
  CONTENT_WIZARD_CARD_WIDTH,
  CONTENT_WIZARD_HORIZONTAL_GAP,
  CONTENT_WIZARD_ROOT_ID,
  CONTENT_WIZARD_VERTICAL_GAP,
} from '~/types/content-wizard'

import { useContentWizardLayout } from '~/composables/useContentWizardLayout'

const { layoutTree } = useContentWizardLayout()

const COLUMN = CONTENT_WIZARD_CARD_WIDTH + CONTENT_WIZARD_HORIZONTAL_GAP
const ROW = CONTENT_WIZARD_CARD_HEIGHT + CONTENT_WIZARD_VERTICAL_GAP

const node = (
  id: string,
  overrides: Partial<ContentWizardDraftNode> = {}
): ContentWizardDraftNode =>
  ({
    id,
    childrenIds: [],
    isVisible: true,
    isRootVirtual: false,
    ...overrides,
  }) as unknown as ContentWizardDraftNode

const tree = (...nodes: ContentWizardDraftNode[]): Record<string, ContentWizardDraftNode> =>
  Object.fromEntries(nodes.map((entry) => [entry.id, entry]))

const root = (childrenIds: string[] = []) =>
  node(CONTENT_WIZARD_ROOT_ID, { childrenIds, isRootVirtual: true })

describe('layoutTree', () => {
  it('returns no positions and empty bounds without a root node', () => {
    const result = layoutTree(tree(node('a')))

    expect(result.positions).toEqual({})
    expect(result.bounds).toEqual({
      minX: 0,
      maxX: 0,
      minY: 0,
      maxY: 0,
      width: 0,
      height: 0,
    })
  })

  it('places the virtual root at the origin', () => {
    const result = layoutTree(tree(root()))

    expect(result.positions[CONTENT_WIZARD_ROOT_ID]).toEqual({ x: 0, y: 0 })
  })

  it('stacks children one column right of their parent, one row per node', () => {
    const result = layoutTree(tree(root(['a', 'b']), node('a'), node('b')))

    expect(result.positions).toEqual({
      [CONTENT_WIZARD_ROOT_ID]: { x: 0, y: 0 },
      a: { x: COLUMN, y: ROW },
      b: { x: COLUMN, y: ROW * 2 },
    })
  })

  it('indents each generation and orders rows depth-first', () => {
    const result = layoutTree(
      tree(root(['a', 'c']), node('a', { childrenIds: ['b'] }), node('b'), node('c'))
    )

    // Pre-order: root, a, a's child b, then a's sibling c.
    expect(result.positions.a).toEqual({ x: COLUMN, y: ROW })
    expect(result.positions.b).toEqual({ x: COLUMN * 2, y: ROW * 2 })
    expect(result.positions.c).toEqual({ x: COLUMN, y: ROW * 3 })
  })

  it('skips an invisible node and its whole subtree', () => {
    const result = layoutTree(
      tree(
        root(['a', 'c']),
        node('a', { childrenIds: ['b'], isVisible: false }),
        node('b'),
        node('c')
      )
    )

    expect(result.positions.a).toBeUndefined()
    expect(result.positions.b).toBeUndefined()
    // c takes the row a would have had — no gap is left behind.
    expect(result.positions.c).toEqual({ x: COLUMN, y: ROW })
  })

  it('ignores child ids with no matching node', () => {
    const result = layoutTree(tree(root(['ghost', 'a']), node('a')))

    expect(Object.keys(result.positions)).toEqual([CONTENT_WIZARD_ROOT_ID, 'a'])
    expect(result.positions.a).toEqual({ x: COLUMN, y: ROW })
  })

  it('returns empty bounds when the root itself is invisible', () => {
    const result = layoutTree(tree(node(CONTENT_WIZARD_ROOT_ID, { isVisible: false })))

    expect(result.positions).toEqual({})
    expect(result.bounds.width).toBe(0)
  })

  it('bounds span every placed card, not just its origin', () => {
    const result = layoutTree(tree(root(['a']), node('a')))

    expect(result.bounds).toEqual({
      minX: 0,
      maxX: COLUMN + CONTENT_WIZARD_CARD_WIDTH,
      minY: 0,
      maxY: ROW + CONTENT_WIZARD_CARD_HEIGHT,
      width: COLUMN + CONTENT_WIZARD_CARD_WIDTH,
      height: ROW + CONTENT_WIZARD_CARD_HEIGHT,
    })
  })

  it('bounds a lone root to exactly one card', () => {
    const result = layoutTree(tree(root()))

    expect(result.bounds.width).toBe(CONTENT_WIZARD_CARD_WIDTH)
    expect(result.bounds.height).toBe(CONTENT_WIZARD_CARD_HEIGHT)
  })

  // Pinned actual behaviour: children of any node flagged isRootVirtual are
  // placed at depth 1, not parentDepth + 1. Harmless while only the real root
  // carries the flag, but a nested virtual node would collapse its indent.
  it('places children of a nested isRootVirtual node back at depth 1', () => {
    const result = layoutTree(
      tree(
        root(['a']),
        node('a', { childrenIds: ['b'], isRootVirtual: true }),
        node('b')
      )
    )

    expect(result.positions.b).toEqual({ x: COLUMN, y: ROW * 2 })
  })

  // A malformed tree can list the same node under two parents. Placing it twice
  // left the first row as a gap and inflated the bounds, so the first placement
  // wins and the second reference is skipped.
  it('places a node referenced by two parents exactly once', () => {
    const result = layoutTree(
      tree(root(['a', 'b']), node('a', { childrenIds: ['shared'] }), node('b', {
        childrenIds: ['shared'],
      }), node('shared'))
    )

    // rows: root 0, a 1, shared 2, b 3 — no gap.
    expect(result.positions.shared).toEqual({ x: COLUMN * 2, y: ROW * 2 })
    expect(result.bounds.height).toBe(ROW * 3 + CONTENT_WIZARD_CARD_HEIGHT)
  })
})
