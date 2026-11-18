import { describe, expect, it } from 'vitest'

import {
  type BlockTranslations,
  carryBlockTranslations,
  forgetBlockTranslations,
  loadBlockTranslations,
  pendingBlockTranslations,
  remapBlockTranslations,
} from '~/lib/blockTranslations'
import { withFreshIds } from '~/lib/blockTreeEdits'

const teaser = () => ({
  id: 't1',
  block: 'teaser',
  title: 'Our team',
  image: { id: 'asset-1' },
  cards: [{ id: 'c1', block: 'card', label: 'Join' }],
})

const german = (): BlockTranslations => ({
  t1: {
    de: {
      item: {
        id: 't1',
        block: 'teaser',
        title: 'Unser Team',
        cards: [{ id: 'c1', block: 'card', label: 'Mitmachen' }],
      },
      lineage: [
        { unit: 'title', source_hash: 'a', source_text: 'Our team', origin: 'history' },
        { unit: 'cards.c1.label', source_hash: 'b', source_text: 'Join', origin: 'ai' },
      ],
    },
  },
})

const freshIds = () => {
  let count = 0
  return () => `new-${++count}`
}

describe('remapBlockTranslations', () => {
  it('moves items and lineage to the ids of the copies', () => {
    const ids = new Map([
      ['t1', 'n1'],
      ['c1', 'n2'],
    ])

    expect(remapBlockTranslations(german(), ids)).toEqual({
      n1: {
        de: {
          item: {
            id: 'n1',
            block: 'teaser',
            title: 'Unser Team',
            cards: [{ id: 'n2', block: 'card', label: 'Mitmachen' }],
          },
          lineage: [
            { unit: 'title', source_hash: 'a', source_text: 'Our team', origin: 'history' },
            { unit: 'cards.n2.label', source_hash: 'b', source_text: 'Join', origin: 'ai' },
          ],
        },
      },
    })
  })

  it('leaves out blocks that have no copy', () => {
    expect(remapBlockTranslations(german(), new Map([['other', 'n1']]))).toEqual({})
  })
})

describe('carried translations', () => {
  it('wait under the ids of the copy until the save that delivers them', async () => {
    const original = teaser()
    const copy = withFreshIds(original, freshIds())
    carryBlockTranslations(Promise.resolve(german()), [original], [copy])

    const waiting = await pendingBlockTranslations({ body: [copy] })

    expect(Object.keys(waiting)).toEqual([copy.id])
    expect(waiting[copy.id].de.item).toMatchObject({
      id: copy.id,
      cards: [{ id: copy.cards[0].id, label: 'Mitmachen' }],
    })
    expect(await pendingBlockTranslations({ body: [original] })).toEqual({})

    forgetBlockTranslations(waiting)
    expect(await pendingBlockTranslations({ body: [copy] })).toEqual({})
  })

  it('are copied again from an unsaved copy without asking the server for it', async () => {
    const original = teaser()
    const copy = withFreshIds(original, freshIds())
    carryBlockTranslations(german(), [original], [copy])
    const entry = { id: 'entry-1', i18n_parent_id: null, effective_i18n_mode: 'overlay' as const }
    const asked: string[][] = []

    const translations = await loadBlockTranslations(entry, [copy, original], async (_, ids) => {
      asked.push(ids)
      return german()
    })

    expect(asked).toEqual([['t1']])
    expect(Object.keys(translations).sort()).toEqual([copy.id, 't1'].sort())
    forgetBlockTranslations(translations)
  })

  it('are not read for a translation or an independent entry', async () => {
    const fetchSaved = async () => german()

    expect(
      await loadBlockTranslations(
        { id: 'entry-2', i18n_parent_id: 'entry-1', effective_i18n_mode: 'overlay' },
        [teaser()],
        fetchSaved
      )
    ).toEqual({})
    expect(
      await loadBlockTranslations(
        { id: 'entry-1', i18n_parent_id: null, effective_i18n_mode: 'independent' },
        [teaser()],
        fetchSaved
      )
    ).toEqual({})
  })
})
