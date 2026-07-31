import { describe, expect, it } from 'vitest'

import { diffTextSegments, toDisplayText } from '~/utils/text-diff'

const text = (segments: ReturnType<typeof diffTextSegments>) =>
  segments.map((segment) => segment.text).join('')

describe('toDisplayText', () => {
  it('returns an empty string for null and undefined', () => {
    expect(toDisplayText(null)).toBe('')
    expect(toDisplayText(undefined)).toBe('')
  })

  it('passes strings through', () => {
    expect(toDisplayText('hello')).toBe('hello')
    expect(toDisplayText('')).toBe('')
  })

  it('stringifies falsy-but-valid scalars instead of blanking them', () => {
    expect(toDisplayText(0)).toBe('0')
    expect(toDisplayText(false)).toBe('false')
    expect(toDisplayText(NaN)).toBe('NaN')
  })

  it('JSON-encodes objects and arrays', () => {
    expect(toDisplayText({ a: 1 })).toBe('{"a":1}')
    expect(toDisplayText([1, 'x'])).toBe('[1,"x"]')
    expect(toDisplayText([])).toBe('[]')
  })

  it('renders a bigint and a symbol description', () => {
    expect(toDisplayText(10n)).toBe('10')
    expect(toDisplayText(Symbol('s'))).toBe('Symbol(s)')
  })

  // A function is not `typeof 'object'`, so it is stringified as source text
  // rather than JSON — surprising, but harmless in practice.
  it('stringifies a function as its source', () => {
    expect(toDisplayText(() => 1)).toContain('=>')
  })

  // Dropping undefined members would render `{ a: undefined, b: 1 }` and
  // `{ b: 1 }` identically, and the version diff would report "no change".
  it('keeps a key whose value is undefined, serialising it as null', () => {
    expect(toDisplayText({ a: undefined, b: 1 })).toBe('{"a":null,"b":1}')
    expect(toDisplayText({ a: undefined, b: 1 })).not.toBe(toDisplayText({ b: 1 }))
  })

  it('returns undefined-as-a-value from JSON.stringify for a Date-free cycle guard', () => {
    expect(toDisplayText(new Date('2026-07-29T00:00:00.000Z'))).toBe('"2026-07-29T00:00:00.000Z"')
  })

  // Input is arbitrary block content, so a display helper must degrade rather
  // than throw out of the renderer.
  it('degrades instead of throwing on a circular object', () => {
    const cyclic: Record<string, unknown> = {}
    cyclic.self = cyclic

    expect(toDisplayText(cyclic)).toBe('[object Object]')
  })
})

describe('diffTextSegments', () => {
  it('marks identical text as a single equal segment', () => {
    expect(diffTextSegments('hello world', 'hello world')).toEqual([
      { type: 'equal', text: 'hello world' },
    ])
  })

  it('returns nothing for two empty strings', () => {
    expect(diffTextSegments('', '')).toEqual([])
  })

  it('marks a wholly new text as added', () => {
    expect(diffTextSegments('', 'hello')).toEqual([{ type: 'added', text: 'hello' }])
  })

  it('marks a wholly deleted text as removed', () => {
    expect(diffTextSegments('hello', '')).toEqual([{ type: 'removed', text: 'hello' }])
  })

  it('isolates the changed word and keeps the surrounding text equal', () => {
    const segments = diffTextSegments('the quick fox', 'the slow fox')

    expect(segments.filter((s) => s.type === 'removed').map((s) => s.text)).toEqual(['quick'])
    expect(segments.filter((s) => s.type === 'added').map((s) => s.text)).toEqual(['slow'])
    expect(segments.some((s) => s.type === 'equal' && s.text.includes('the'))).toBe(true)
  })

  it('preserves whitespace, so the old text can be reconstructed', () => {
    const segments = diffTextSegments('a  b\nc', 'a  b\nd')

    expect(text(segments.filter((s) => s.type !== 'added'))).toBe('a  b\nc')
    expect(text(segments.filter((s) => s.type !== 'removed'))).toBe('a  b\nd')
  })

  it('reconstructs both sides for a multi-edit diff', () => {
    const oldText = 'one two three four'
    const newText = 'one 2 three four five'
    const segments = diffTextSegments(oldText, newText)

    expect(text(segments.filter((s) => s.type !== 'added'))).toBe(oldText)
    expect(text(segments.filter((s) => s.type !== 'removed'))).toBe(newText)
  })

  it('never emits a segment that is both added and removed', () => {
    for (const segment of diffTextSegments('alpha beta', 'alpha gamma')) {
      expect(['equal', 'added', 'removed']).toContain(segment.type)
    }
  })

  it('is case sensitive', () => {
    expect(diffTextSegments('Hello', 'hello').some((s) => s.type !== 'equal')).toBe(true)
  })

  it('detects a whitespace-only change', () => {
    expect(diffTextSegments('a b', 'a  b').some((s) => s.type !== 'equal')).toBe(true)
  })
})
