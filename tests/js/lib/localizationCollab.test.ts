import { describe, expect, it } from 'vitest'

import type { BlockStamp } from '~/lib/localizationCollab'

import {
  applyLocalizedFieldValue,
  getCollaborationFieldKey,
  getLocalizedFieldValue,
} from '~/lib/localizationCollab'

const stamp = (pathIndex: number, id: string, block = 'card'): BlockStamp => ({
  pathIndex,
  id,
  block,
})

describe('getCollaborationFieldKey', () => {
  it('joins a plain path with dots', () => {
    expect(getCollaborationFieldKey(['seo', 'title'])).toBe('seo.title')
  })

  it('stringifies numeric segments', () => {
    expect(getCollaborationFieldKey(['body', 0, 'headline'])).toBe('body.0.headline')
  })

  it('replaces a stamped index with the block item id', () => {
    expect(getCollaborationFieldKey(['body', 0, 'headline'], [stamp(1, 'item-a')])).toBe(
      'body.item-a.headline'
    )
  })

  it('gives the same key to peers holding the item at different indices', () => {
    expect(getCollaborationFieldKey(['body', 0, 'title'], [stamp(1, 'item-a')])).toBe(
      getCollaborationFieldKey(['body', 3, 'title'], [stamp(1, 'item-a')])
    )
  })

  it('replaces every stamped level independently', () => {
    expect(
      getCollaborationFieldKey(
        ['body', 2, 'items', 5, 'label'],
        [stamp(1, 'section-a'), stamp(3, 'card-b')]
      )
    ).toBe('body.section-a.items.card-b.label')
  })

  it('leaves unstamped indices as positions', () => {
    expect(getCollaborationFieldKey(['body', 0, 'tags', 2], [stamp(1, 'item-a')])).toBe(
      'body.item-a.tags.2'
    )
  })

  it('ignores a stamp pointing past the path', () => {
    expect(getCollaborationFieldKey(['title'], [stamp(7, 'item-a')])).toBe('title')
  })

  it('returns an empty key for an empty path', () => {
    expect(getCollaborationFieldKey([])).toBe('')
  })
})

describe('getLocalizedFieldValue', () => {
  const content = () => ({
    title: 'Home',
    seo: { title: 'Home | Site' },
    body: [
      { id: 'item-a', block: 'card', headline: 'First' },
      { id: 'item-b', block: 'card', headline: 'Second', tags: ['x', 'y'] },
    ],
  })

  it('reads a top-level value', () => {
    expect(getLocalizedFieldValue(content(), ['title'])).toBe('Home')
  })

  it('reads a nested object value', () => {
    expect(getLocalizedFieldValue(content(), ['seo', 'title'])).toBe('Home | Site')
  })

  it('reads through a positional array index', () => {
    expect(getLocalizedFieldValue(content(), ['body', 1, 'headline'])).toBe('Second')
  })

  it('resolves a stamped index by id, ignoring the sender position', () => {
    // The sender held item-b at index 0; locally it sits at index 1.
    expect(getLocalizedFieldValue(content(), ['body', 0, 'headline'], [stamp(1, 'item-b')])).toBe(
      'Second'
    )
  })

  it('returns undefined when the stamped item is absent locally', () => {
    expect(
      getLocalizedFieldValue(content(), ['body', 0, 'headline'], [stamp(1, 'item-ghost')])
    ).toBeUndefined()
  })

  it('returns undefined for a missing key', () => {
    expect(getLocalizedFieldValue(content(), ['nope'])).toBeUndefined()
  })

  it('returns undefined when the path runs through a primitive', () => {
    expect(getLocalizedFieldValue(content(), ['title', 'deeper'])).toBeUndefined()
  })

  it('returns undefined when the path runs through a null', () => {
    expect(getLocalizedFieldValue({ a: null }, ['a', 'b'])).toBeUndefined()
  })

  it('returns undefined for an out-of-range positional index', () => {
    expect(getLocalizedFieldValue(content(), ['body', 9, 'headline'])).toBeUndefined()
  })

  it('reads a value inside a plain array under a stamped item', () => {
    expect(
      getLocalizedFieldValue(content(), ['body', 0, 'tags', 1], [stamp(1, 'item-b')])
    ).toBe('y')
  })

  it('ignores a stamp when the level is not an array', () => {
    expect(getLocalizedFieldValue(content(), ['seo', 'title'], [stamp(1, 'item-a')])).toBe(
      'Home | Site'
    )
  })

  it('returns the whole content for an empty path', () => {
    const value = content()

    expect(getLocalizedFieldValue(value, [])).toBe(value)
  })
})

describe('applyLocalizedFieldValue', () => {
  it('sets a top-level value', () => {
    const content: Record<string, unknown> = {}

    applyLocalizedFieldValue(content, ['title'], 'Startseite')

    expect(content.title).toBe('Startseite')
  })

  it('overwrites an existing value', () => {
    const content: Record<string, unknown> = { title: 'Home' }

    applyLocalizedFieldValue(content, ['title'], 'Startseite')

    expect(content.title).toBe('Startseite')
  })

  it('creates missing intermediate objects', () => {
    const content: Record<string, unknown> = {}

    applyLocalizedFieldValue(content, ['seo', 'title'], 'Startseite')

    expect(content).toEqual({ seo: { title: 'Startseite' } })
  })

  it('creates an array when the next segment is numeric', () => {
    const content: Record<string, unknown> = {}

    applyLocalizedFieldValue(content, ['tags', 0], 'x')

    expect(Array.isArray(content.tags)).toBe(true)
    expect(content.tags).toEqual(['x'])
  })

  it('pads a plain array up to the target index', () => {
    const content: Record<string, unknown> = { body: [] }

    applyLocalizedFieldValue(content, ['body', 2, 'headline'], 'Third')

    expect(content.body).toEqual([{}, {}, { headline: 'Third' }])
  })

  it('replaces a primitive standing where an object is needed', () => {
    const content: Record<string, unknown> = { seo: 'oops' }

    applyLocalizedFieldValue(content, ['seo', 'title'], 'Startseite')

    expect(content.seo).toEqual({ title: 'Startseite' })
  })

  it('writes into an existing positional index without disturbing siblings', () => {
    const content: Record<string, unknown> = {
      body: [{ id: 'a', headline: 'First' }, { id: 'b', headline: 'Second' }],
    }

    applyLocalizedFieldValue(content, ['body', 1, 'headline'], 'Zweite')

    expect(content.body).toEqual([
      { id: 'a', headline: 'First' },
      { id: 'b', headline: 'Zweite' },
    ])
  })

  it('does nothing for an empty path', () => {
    const content: Record<string, unknown> = { title: 'Home' }

    applyLocalizedFieldValue(content, [], 'ignored')

    expect(content).toEqual({ title: 'Home' })
  })

  describe('stamped indices', () => {
    it('writes to the local position of the stamped item, not the sender index', () => {
      const content: Record<string, unknown> = {
        body: [
          { id: 'item-a', block: 'card', headline: 'First' },
          { id: 'item-b', block: 'card', headline: 'Second' },
        ],
      }

      applyLocalizedFieldValue(content, ['body', 0, 'headline'], 'Zweite', [stamp(1, 'item-b')])

      expect(content.body).toEqual([
        { id: 'item-a', block: 'card', headline: 'First' },
        { id: 'item-b', block: 'card', headline: 'Zweite' },
      ])
    })

    it('materializes a missing block item rather than dropping the edit', () => {
      const content: Record<string, unknown> = { body: [] }

      applyLocalizedFieldValue(content, ['body', 4, 'headline'], 'Neu', [
        stamp(1, 'item-new', 'hero'),
      ])

      expect(content.body).toEqual([{ id: 'item-new', block: 'hero', headline: 'Neu' }])
    })

    it('appends the materialized item, leaving existing ones in place', () => {
      const content: Record<string, unknown> = {
        body: [{ id: 'item-a', block: 'card', headline: 'First' }],
      }

      applyLocalizedFieldValue(content, ['body', 0, 'headline'], 'Neu', [stamp(1, 'item-new')])

      expect((content.body as unknown[]).map((entry) => (entry as { id: string }).id)).toEqual([
        'item-a',
        'item-new',
      ])
    })

    it('creates the array itself when the sparse overlay has no such key', () => {
      const content: Record<string, unknown> = {}

      applyLocalizedFieldValue(content, ['body', 0, 'headline'], 'Neu', [stamp(1, 'item-new')])

      expect(content.body).toEqual([{ id: 'item-new', block: 'card', headline: 'Neu' }])
    })

    it('resolves stamped indices at two levels', () => {
      const content: Record<string, unknown> = {
        body: [
          { id: 'section-a', block: 'section', items: [{ id: 'card-b', block: 'card' }] },
        ],
      }

      applyLocalizedFieldValue(
        content,
        ['body', 3, 'items', 7, 'label'],
        'Etikett',
        [stamp(1, 'section-a', 'section'), stamp(3, 'card-b')]
      )

      expect(content.body).toEqual([
        {
          id: 'section-a',
          block: 'section',
          items: [{ id: 'card-b', block: 'card', label: 'Etikett' }],
        },
      ])
    })

    it('materializes both levels when neither exists locally', () => {
      const content: Record<string, unknown> = {}

      applyLocalizedFieldValue(
        content,
        ['body', 0, 'items', 0, 'label'],
        'Etikett',
        [stamp(1, 'section-x', 'section'), stamp(3, 'card-y')]
      )

      expect(content.body).toEqual([
        {
          id: 'section-x',
          block: 'section',
          items: [{ id: 'card-y', block: 'card', label: 'Etikett' }],
        },
      ])
    })

    it('does not pad up to a stamped index', () => {
      const content: Record<string, unknown> = { body: [] }

      applyLocalizedFieldValue(content, ['body', 9, 'headline'], 'Neu', [stamp(1, 'item-new')])

      expect((content.body as unknown[]).length).toBe(1)
    })

    it('ignores a stamp when the level is an object rather than an array', () => {
      const content: Record<string, unknown> = { seo: {} }

      applyLocalizedFieldValue(content, ['seo', 'title'], 'Startseite', [stamp(1, 'item-a')])

      expect(content.seo).toEqual({ title: 'Startseite' })
    })
  })

  describe('round trip with getLocalizedFieldValue', () => {
    it('reads back a stamped write from a differing local position', () => {
      const content: Record<string, unknown> = {
        body: [{ id: 'item-a', block: 'card' }, { id: 'item-b', block: 'card' }],
      }
      const stamps = [stamp(1, 'item-b')]

      applyLocalizedFieldValue(content, ['body', 0, 'headline'], 'Zweite', stamps)

      expect(getLocalizedFieldValue(content, ['body', 0, 'headline'], stamps)).toBe('Zweite')
    })

    it('reads back a write that materialized the item', () => {
      const content: Record<string, unknown> = {}
      const stamps = [stamp(1, 'item-new', 'hero')]

      applyLocalizedFieldValue(content, ['body', 2, 'headline'], 'Neu', stamps)

      expect(getLocalizedFieldValue(content, ['body', 2, 'headline'], stamps)).toBe('Neu')
    })
  })
})
