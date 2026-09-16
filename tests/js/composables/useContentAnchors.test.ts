import { describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'

const content = ref<Record<string, unknown> | undefined>()
const blocks = ref<{ data: Partial<BlockResource>[] } | undefined>()

vi.mock('~/composables/useContent', () => ({
  useContent: () => ({
    useContentQuery: () => ({ data: content, isLoading: computed(() => !content.value) }),
  }),
}))

vi.mock('~/composables/useBlocks', () => ({
  useBlocks: () => ({ useBlocksQuery: () => ({ data: blocks }) }),
}))

const { useContentAnchors } = await import('~/composables/useContentAnchors')

const page = {
  id: 'page-1',
  content: {
    block: 'page',
    body: [
      { id: 'hero', block: 'hero', headline: 'Built for <teams>' },
      { id: 'faq', block: 'faq', question: '' },
      { id: 'gone', block: 'removed-block' },
    ],
  },
}

describe('useContentAnchors', () => {
  it('titles anchors with the preview template and falls back like the block editor', () => {
    content.value = page
    blocks.value = {
      data: [
        { slug: 'hero', name: 'Hero', icon: 'star', preview_template: '<b>{{headline}}</b>' },
        { slug: 'faq', name: 'FAQ' },
      ],
    }

    const { anchors, findAnchor, isLoaded } = useContentAnchors('space-1', 'page-1')

    expect(isLoaded.value).toBe(true)
    expect(anchors.value.map(({ id, title, block }) => ({ id, title, name: block.name }))).toEqual([
      { id: 'hero', title: '<b>Built for &lt;teams&gt;</b>', name: 'Hero' },
      { id: 'faq', title: 'FAQ', name: 'FAQ' },
      { id: 'gone', title: 'removed-block', name: 'removed-block' },
    ])
    expect(findAnchor('faq')?.block.name).toBe('FAQ')
    expect(findAnchor('missing')).toBeUndefined()
  })

  it('reports nothing loaded while the cached content belongs to another page', () => {
    content.value = { ...page, id: 'page-2' }

    const { anchors, isLoaded } = useContentAnchors('space-1', 'page-1')

    expect(isLoaded.value).toBe(false)
    expect(anchors.value).toEqual([])
  })

  it('stays idle without a space or content id', () => {
    content.value = page

    expect(useContentAnchors('', 'page-1').isLoaded.value).toBe(false)
    expect(useContentAnchors('space-1', null).isLoaded.value).toBe(false)
  })
})
