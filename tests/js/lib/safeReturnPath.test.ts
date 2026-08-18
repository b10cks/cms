import { describe, expect, it } from 'vitest'

import { safeReturnPath } from '~/lib/safeReturnPath'

describe('safeReturnPath', () => {
  it.each([
    [undefined, '/'],
    [null, '/'],
    ['', '/'],
    ['/', '/'],
    ['//evil', '/'],
    ['https://evil', '/'],
    ['\\evil', '/'],
    ['/spaces/x', '/spaces/x'],
    ['/spaces/x?a=1', '/spaces/x?a=1'],
  ])('maps %j to %j', (input, expected) => {
    expect(safeReturnPath(input)).toBe(expected)
  })
})
