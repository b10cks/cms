import { describe, expect, it } from 'vitest'

import { cn, digest } from '~/lib/utils'

describe('cn', () => {
  it('joins plain class strings', () => {
    expect(cn('a', 'b')).toBe('a b')
  })

  it('drops falsy values', () => {
    expect(cn('a', false, null, undefined, '', 0, 'b')).toBe('a b')
  })

  it('keeps a numeric 1, which clsx treats as truthy', () => {
    expect(cn(1, 'a')).toBe('1 a')
  })

  it('flattens arrays, including nested ones', () => {
    expect(cn(['a', ['b', ['c']]])).toBe('a b c')
  })

  it('takes the truthy keys of an object', () => {
    expect(cn({ a: true, b: false, c: 1, d: 0 })).toBe('a c')
  })

  it('mixes argument forms in order', () => {
    expect(cn('base', ['x'], { y: true }, undefined)).toBe('base x y')
  })

  it('returns an empty string for no arguments', () => {
    expect(cn()).toBe('')
  })

  // This is plain clsx — there is no tailwind-merge, so conflicting utilities
  // both survive and the stylesheet order decides which one wins.
  it('does NOT dedupe conflicting Tailwind utilities', () => {
    expect(cn('p-2', 'p-4')).toBe('p-2 p-4')
  })

  it('does not dedupe an exactly repeated class either', () => {
    expect(cn('a', 'a')).toBe('a a')
  })
})

describe('digest', () => {
  it('returns the SHA-256 of the input as lowercase hex', async () => {
    expect(await digest('hello')).toBe(
      '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824'
    )
  })

  it('hashes the empty string rather than short-circuiting', async () => {
    expect(await digest('')).toBe(
      'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
    )
  })

  it('hashes the UTF-8 bytes, so non-ASCII input is stable', async () => {
    expect(await digest('ünïcödé 🎉')).toBe(
      '3998e788957fc1d213d6b275971b45891fff1070385322df11de5d3a2b347bf5'
    )
  })

  it('always produces 64 hex characters, zero-padding each byte', async () => {
    const hex = await digest('a')

    expect(hex).toHaveLength(64)
    expect(hex).toMatch(/^[0-9a-f]{64}$/)
  })

  it('is deterministic and differs for differing input', async () => {
    expect(await digest('x')).toBe(await digest('x'))
    expect(await digest('x')).not.toBe(await digest('y'))
  })
})
