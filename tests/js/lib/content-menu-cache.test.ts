import { describe, expect, it } from 'vitest'

import { type ContentMenu, withMovedItems } from '~/lib/content-menu-cache'

const item = (id: string, pid: string | null, position: number): FlatContentMenuItem => ({
  id,
  name: id,
  slug: id,
  block_id: 'b1',
  position,
  type: 'root',
  color: null,
  pid,
  children: false,
  settings: {},
  i18n: [],
  pat: null,
  drf: true,
  uat: '2026-01-01T00:00:00+00:00',
})

const positions = (menu: ContentMenu, pid: string | null) =>
  Object.values(menu)
    .filter((entry) => entry.pid === pid)
    .sort((a, b) => a.position - b.position)
    .map((entry) => `${entry.id}:${entry.position}`)

describe('withMovedItems', () => {
  const menu: ContentMenu = {
    folder: { ...item('folder', null, 0), children: true },
    a: item('a', 'folder', 0),
    b: item('b', 'folder', 1),
    c: item('c', null, 1),
  }

  it('reparents and resequences like the server, updating both children flags', () => {
    const moved = withMovedItems(menu, { ids: ['a'], parent_id: null, after_id: 'folder' }, true)

    expect(positions(moved, null)).toEqual(['folder:0', 'a:1', 'c:2'])
    expect(positions(moved, 'folder')).toEqual(['b:1'])

    const emptied = withMovedItems(moved, { ids: ['b'], parent_id: 'c', position: 0 }, true)
    expect(emptied.folder.children).toBe(false)
    expect(emptied.c.children).toBe(true)
  })

  it('only reparents where the destination is not manually sorted', () => {
    const moved = withMovedItems(menu, { ids: ['c'], parent_id: 'folder', position: 0 }, false)

    expect(positions(moved, 'folder')).toEqual(['a:0', 'b:1', 'c:1'])
  })
})
