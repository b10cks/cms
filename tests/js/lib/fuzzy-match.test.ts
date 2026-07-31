import { describe, expect, it } from 'vitest'

import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'

const scoreOf = (query: string, text: string) => fuzzyMatch(query, text)?.score ?? -Infinity

describe('fuzzyMatch', () => {
  it('matches a contiguous substring and reports its indices', () => {
    expect(fuzzyMatch('blo', 'blocks')?.indices).toEqual([0, 1, 2])
  })

  it('matches a non-contiguous subsequence', () => {
    expect(fuzzyMatch('cnt', 'content')?.indices).toEqual([0, 2, 3])
  })

  it('returns null when the query is not a subsequence', () => {
    expect(fuzzyMatch('xyz', 'content')).toBeNull()
    expect(fuzzyMatch('conten7', 'content')).toBeNull()
  })

  it('returns null for an empty or whitespace-only query', () => {
    expect(fuzzyMatch('', 'content')).toBeNull()
    expect(fuzzyMatch('   ', 'content')).toBeNull()
  })

  it('returns null when the query is longer than the text', () => {
    expect(fuzzyMatch('contents', 'cont')).toBeNull()
  })

  it('is case insensitive', () => {
    expect(fuzzyMatch('BLO', 'blocks')?.indices).toEqual([0, 1, 2])
    expect(fuzzyMatch('blo', 'BLOCKS')?.indices).toEqual([0, 1, 2])
  })

  it('is diacritic insensitive on both sides', () => {
    expect(fuzzyMatch('uber', 'Über uns')?.indices).toEqual([0, 1, 2, 3])
    expect(fuzzyMatch('ü', 'uber')?.indices).toEqual([0])
  })

  it('scores a prefix match above a mid-word match', () => {
    expect(scoreOf('con', 'content')).toBeGreaterThan(scoreOf('con', 'my-content'))
  })

  it('scores a contiguous run above a gapped one', () => {
    expect(scoreOf('ab', 'abc')).toBeGreaterThan(scoreOf('ab', 'axxxxb'))
  })

  it('rewards word-boundary starts', () => {
    expect(scoreOf('as', 'asset settings')).toBeGreaterThan(scoreOf('as', 'aliases'))
  })

  it('rewards camelCase boundaries', () => {
    expect(scoreOf('ab', 'aBc')).toBeGreaterThan(scoreOf('ab', 'abc'))
  })

  it('picks the best anchor rather than the first', () => {
    // Both "c"s can start a match, but only the second runs contiguously
    // through "con".
    expect(fuzzyMatch('con', 'co content')?.indices).toEqual([3, 4, 5])
  })

  it('scores a match at index 0 the same as the identical match elsewhere', () => {
    // The run bonus rewards a character that continues a run, so the first
    // matched character never earns it — wherever the match is anchored.
    expect(scoreOf('ab', 'abc')).toBe(20)
    expect(scoreOf('ab', '-abc')).toBe(20)
  })
})

describe('prepared inputs', () => {
  it('prepareFuzzyTarget precomputes normalization and boundaries', () => {
    const target = prepareFuzzyTarget('Über uns')

    expect(target.raw).toBe('Über uns')
    expect(target.normalized).toBe('uber uns')
    expect(target.boundaries[0]).toBe(true)
    expect(target.boundaries[1]).toBe(false)
    // The space is a non-alphanumeric, so the character after it starts a word.
    expect(target.boundaries[5]).toBe(true)
  })

  it('prepareFuzzyQuery trims, lowercases and strips diacritics', () => {
    expect(prepareFuzzyQuery('  ÜBer ')).toBe('uber')
  })

  it('a prepared target and query match the plain-string path', () => {
    const plain = fuzzyMatch('ub', 'Über uns')
    const prepared = fuzzyMatch(prepareFuzzyQuery('ÜB'), prepareFuzzyTarget('Über uns'))

    expect(prepared).toEqual(plain)
  })
})
