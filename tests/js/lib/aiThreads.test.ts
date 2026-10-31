import { describe, expect, it } from 'vitest'

import type { AiThread, AiThreadChip } from '~/lib/aiThreads'
import { groupThreads } from '~/lib/aiThreads'

const thread = (id: string, context: AiThreadChip[] = [], overrides: Partial<AiThread> = {}): AiThread => ({
  id,
  kind: 'chat',
  title: id,
  status: 'done',
  shared: false,
  quiet: false,
  archived: false,
  last_activity_at: null,
  owner: null,
  is_owner: true,
  entry_count: 0,
  context,
  ...overrides,
})

describe('groupThreads', () => {
  it('puts threads about the current screen first, a field chip counting as its entry', () => {
    const screen: AiThreadChip[] = [{ type: 'content', id: 'entry-1', label: 'Home' }]
    const { page, mine } = groupThreads(
      [
        thread('other', [{ type: 'content', id: 'entry-2', label: 'About' }]),
        thread('field', [{ type: 'field', id: 'entry-1', label: 'Title', field: 'title' }]),
        thread('entry', [{ type: 'content', id: 'entry-1', label: 'Home' }]),
      ],
      screen
    )

    expect(page.map((item) => item.id)).toEqual(['field', 'entry'])
    expect(mine.map((item) => item.id)).toEqual(['other'])
  })

  it('ignores attached knowledge documents with a matching id', () => {
    const { page } = groupThreads([thread('doc', [{ type: 'document', id: 'x', label: 'Guide' }])], [
      { type: 'asset', id: 'x', label: 'photo.jpg' },
    ])

    expect(page).toEqual([])
  })
})
