import { describe, expect, it } from 'vitest'

import type { IlumModifiers } from '~/lib/ilum'

import { buildIlumUrl, generateIlumOperations } from '~/lib/ilum'

describe('generateIlumOperations', () => {
  it('returns an empty string for no modifiers', () => {
    expect(generateIlumOperations({})).toBe('')
  })

  it('maps each known modifier to its short key', () => {
    expect(
      generateIlumOperations({
        width: 100,
        height: 50,
        crop: 'fill',
        gravity: 'face',
        x: 1,
        y: 2,
        targetWidth: 300,
        targetHeight: 400,
      })
    ).toBe('w_100,h_50,c_fill,g_face,x_1,y_2,tw_300,th_400')
  })

  it('preserves insertion order rather than the keyMap order', () => {
    expect(generateIlumOperations({ height: 50, width: 100 })).toBe('h_50,w_100')
  })

  it('skips undefined values', () => {
    expect(generateIlumOperations({ width: 100, height: undefined })).toBe('w_100')
  })

  it('keeps a zero value — falsy but valid', () => {
    expect(generateIlumOperations({ width: 0, x: 0 })).toBe('w_0,x_0')
  })

  it('ignores format and quality, which travel as query parameters instead', () => {
    expect(generateIlumOperations({ format: 'webp', quality: 80 })).toBe('')
  })

  it('ignores keys that are not in the map', () => {
    expect(
      generateIlumOperations({ nope: 1, width: 10 } as unknown as IlumModifiers)
    ).toBe('w_10')
  })

  // Only `undefined` is filtered, so an explicit null is stringified into the
  // operation list and produces a nonsense `w_null` segment.
  it('skips null values, which a nullable asset field spreads in', () => {
    expect(generateIlumOperations({ width: null } as unknown as IlumModifiers)).toBe('')
  })

  it('encodes values so they cannot break out of the path segment', () => {
    expect(generateIlumOperations({ gravity: 'a b/c' })).toBe('g_a%20b%2Fc')
  })
})

describe('buildIlumUrl', () => {
  it('returns the source path alone with no modifiers and no base', () => {
    expect(buildIlumUrl('/img/a.png')).toBe('/img/a.png')
  })

  it('prepends a leading slash to a relative source', () => {
    expect(buildIlumUrl('img/a.png')).toBe('/img/a.png')
  })

  it('appends the operations as a path segment', () => {
    expect(buildIlumUrl('/img/a.png', { width: 100, height: 50 })).toBe('/img/a.png/w_100,h_50')
  })

  it('prefixes the base URL', () => {
    expect(buildIlumUrl('/img/a.png', { width: 100 }, 'https://ilum.test')).toBe(
      'https://ilum.test/img/a.png/w_100'
    )
  })

  it('strips a single trailing slash from the base URL', () => {
    expect(buildIlumUrl('/img/a.png', {}, 'https://ilum.test/')).toBe('https://ilum.test/img/a.png')
  })

  // Only one slash is removed, so a doubled trailing slash leaks through.
  it('strips every trailing slash from the base, not just the last one', () => {
    expect(buildIlumUrl('/img/a.png', {}, 'https://ilum.test//')).toBe('https://ilum.test/img/a.png')
  })

  it('adds format and quality as query parameters', () => {
    expect(buildIlumUrl('/img/a.png', { format: 'webp', quality: 80 })).toBe(
      '/img/a.png?format=webp&quality=80'
    )
  })

  it('emits quality=0, which is falsy but explicitly allowed', () => {
    expect(buildIlumUrl('/img/a.png', { quality: 0 })).toBe('/img/a.png?quality=0')
  })

  it('omits an empty format string', () => {
    expect(buildIlumUrl('/img/a.png', { format: '' })).toBe('/img/a.png')
  })

  it('combines operations and query parameters in that order', () => {
    expect(
      buildIlumUrl('/img/a.png', { width: 100, format: 'avif', quality: 60 }, '/ilum')
    ).toBe('/ilum/img/a.png/w_100?format=avif&quality=60')
  })

  it('URL-encodes the query values', () => {
    expect(buildIlumUrl('/img/a.png', { format: 'a b' })).toBe('/img/a.png?format=a+b')
  })

  it('handles an empty source by producing a bare slash', () => {
    expect(buildIlumUrl('')).toBe('/')
  })

  it('does not mutate the modifiers it is given', () => {
    const modifiers: IlumModifiers = { width: 100, format: 'webp', quality: 80 }

    buildIlumUrl('/img/a.png', modifiers)

    expect(modifiers).toEqual({ width: 100, format: 'webp', quality: 80 })
  })
})
