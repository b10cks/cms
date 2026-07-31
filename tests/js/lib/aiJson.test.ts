import { describe, expect, it } from 'vitest'

import { balancedSpans, parseAiJson, stripAiCodeFences } from '~/lib/aiJson'

describe('stripAiCodeFences', () => {
  it.each(['```json', '```javascript', '```js', '```', '```JSON'])(
    'strips a %s fence',
    (fence) => {
      expect(stripAiCodeFences(`${fence}\n{"a":1}\n\`\`\``)).toBe('{"a":1}')
    }
  )

  it('leaves unfenced text alone', () => {
    expect(stripAiCodeFences('{"a":1}')).toBe('{"a":1}')
  })

  it('only strips the outermost fence pair', () => {
    expect(stripAiCodeFences('```json\n{"code":"```"}\n```')).toBe('{"code":"```"}')
  })
})

describe('parseAiJson', () => {
  it('parses clean JSON', () => {
    expect(parseAiJson('{"a":1}')).toEqual({ a: 1 })
    expect(parseAiJson('[1,2,3]')).toEqual([1, 2, 3])
  })

  it('parses fenced JSON', () => {
    expect(parseAiJson('```json\n{"a":1}\n```')).toEqual({ a: 1 })
  })

  it('extracts an object embedded in prose', () => {
    expect(parseAiJson('Sure! Here you go:\n{"a":1}\nHope that helps.')).toEqual({ a: 1 })
  })

  it('extracts an array embedded in prose', () => {
    expect(parseAiJson('Result: [1,2] — done')).toEqual([1, 2])
  })

  it('picks whichever of { or [ comes first', () => {
    expect(parseAiJson('text [1] more {"a":1}')).toEqual([1])
    expect(parseAiJson('text {"a":1} more [1]')).toEqual({ a: 1 })
  })

  it('handles nested structures', () => {
    expect(parseAiJson('noise {"a":{"b":[1,{"c":2}]}} noise')).toEqual({ a: { b: [1, { c: 2 }] } })
  })

  it('ignores braces inside string literals', () => {
    expect(parseAiJson('x {"a":"}{"} y')).toEqual({ a: '}{' })
  })

  it('ignores escaped quotes inside string literals', () => {
    expect(parseAiJson('x {"a":"say \\"hi\\" }"} y')).toEqual({ a: 'say "hi" }' })
  })

  it.each([
    ['null input', null],
    ['undefined input', undefined],
    ['empty string', ''],
    ['whitespace only', '   \n  '],
    ['no JSON at all', 'sorry, I cannot help with that'],
    ['unterminated object', '{"a":1'],
    ['malformed object', '{"a":,}'],
  ])('returns null for %s', (_label, input) => {
    expect(parseAiJson(input)).toBeNull()
  })
})

describe('balancedSpans', () => {
  const spans = (text: string, open = '{', close = '}') => [...balancedSpans(text, open, close)]

  it('yields each top-level span', () => {
    expect(spans('{"a":1} {"b":2}')).toEqual([
      { start: 0, end: 6 },
      { start: 8, end: 14 },
    ])
  })

  it('yields the outer span only, not the nested ones', () => {
    expect(spans('{"a":{"b":1}}')).toEqual([{ start: 0, end: 12 }])
  })

  it('skips delimiters inside strings', () => {
    expect(spans('{"a":"}"}')).toEqual([{ start: 0, end: 8 }])
  })

  it('does not yield an unterminated trailing span', () => {
    expect(spans('{"a":1} {"b":')).toEqual([{ start: 0, end: 6 }])
  })

  it('ignores a stray closing delimiter', () => {
    expect(spans('} {"a":1}')).toEqual([{ start: 2, end: 8 }])
  })

  it('works for arrays', () => {
    expect(spans('[1,[2]] [3]', '[', ']')).toEqual([
      { start: 0, end: 6 },
      { start: 8, end: 10 },
    ])
  })

  it('yields nothing for text without delimiters', () => {
    expect(spans('plain text')).toEqual([])
  })
})
