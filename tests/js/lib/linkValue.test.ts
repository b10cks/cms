import { describe, expect, it } from 'vitest'

import { emptyLink, hasLinkOptions, isEmptyLink } from '~/lib/linkValue'

describe('emptyLink', () => {
  it('starts each kind in the shape the field always stored', () => {
    expect(emptyLink('url')).toEqual({ type: 'url', url: '', target: '_self' })
    expect(emptyLink('email')).toEqual({ type: 'email', email: '' })
    expect(emptyLink('internal')).toEqual({ type: 'internal', content: '' })
  })
})

describe('isEmptyLink', () => {
  it('looks only at the main value of each kind', () => {
    expect(isEmptyLink({ type: 'url', url: '  ', target: '_blank' })).toBe(true)
    expect(isEmptyLink({ type: 'internal', content: 'c1', anchor: 'b1' })).toBe(false)
    expect(isEmptyLink({ type: 'email', subject: 'Hi' })).toBe(true)
  })
})

describe('hasLinkOptions', () => {
  it('opens the options for settings the checkbox cannot show', () => {
    expect(hasLinkOptions({ type: 'url', url: 'https://a.b', target: '_blank' })).toBe(false)
    expect(hasLinkOptions({ type: 'url', url: 'https://a.b', target: '_top' })).toBe(true)
    expect(hasLinkOptions({ type: 'url', url: 'https://a.b', rel: 'nofollow' })).toBe(true)
    expect(hasLinkOptions({ type: 'internal', content: 'c1', params: { a: 'b' } })).toBe(true)
    expect(hasLinkOptions({ type: 'email', email: 'a@b.c', cc: 'd@e.f' })).toBe(true)
  })
})
