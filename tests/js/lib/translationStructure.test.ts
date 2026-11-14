import { describe, expect, it } from 'vitest'

import { buildTranslationStructure, collectBlockPositions } from '~/lib/translationStructure'

const source = {
  title: 'Home',
  body: [
    { id: 'hero', block: 'hero', headline: 'Hi' },
    { id: 'cards', block: 'cards', items: [{ id: 'card-a', block: 'card', label: 'A' }] },
  ],
}

const stamp = (pathIndex: number, id: string, block: string) => ({ pathIndex, id, block })

describe('buildTranslationStructure', () => {
  const build = (editor: EditorPage[] | null = null) =>
    buildTranslationStructure(
      [
        { id: 'title', path: ['title'], status: 'current' },
        // The translation holds the cards block first, so its index there is 0.
        {
          id: 'label',
          path: ['body', 0, 'items', 0, 'label'],
          blockStamps: [stamp(1, 'cards', 'cards'), stamp(3, 'card-a', 'card')],
          status: 'missing',
        },
        { id: 'seo', path: ['seo'], status: 'outdated' },
      ],
      {
        editor,
        blockName: (slug) => slug.charAt(0).toUpperCase() + slug.slice(1),
        positions: collectBlockPositions(source),
      }
    )

  it('numbers blocks by their place in the source, not in the translation', () => {
    const { nodes } = build()

    expect(nodes.map((node) => [node.label, node.depth])).toEqual([
      ['Cards 2', 0],
      ['Card 1', 1],
    ])
  })

  it('groups top-level fields by editor page and counts statuses up the chain', () => {
    const { nodes, sectionOf } = build([
      { header: 'Content', items: ['title', 'body'] },
      { header: 'SEO', items: ['seo'] },
    ])

    expect(nodes.map((node) => node.label)).toEqual(['Content', 'Cards 2', 'Card 1', 'SEO'])
    expect(sectionOf.get('label')).toBe('card-a')
    expect(nodes[0].counts).toEqual({ current: 1, outdated: 0, missing: 1 })
  })
})
