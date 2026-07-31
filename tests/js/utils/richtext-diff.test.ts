import { describe, expect, it } from 'vitest'

import type { DiffBlock, RteDoc } from '~/utils/richtext-diff'

import { diffRichText, isRichTextDoc } from '~/utils/richtext-diff'

const text = (value: string, marks?: unknown[]) => ({ type: 'text', text: value, ...(marks ? { marks } : {}) })

const paragraph = (value: string, marks?: unknown[]) => ({
  type: 'paragraph',
  content: [text(value, marks)],
})

const doc = (...content: unknown[]) => ({ type: 'doc', content }) as unknown as RteDoc

/** kind + flattened text, which is what the version-history UI actually renders. */
const summary = (blocks: DiffBlock[]) =>
  blocks.map((block) => [block.kind, block.segments.map((segment) => segment.text).join('')])

const kinds = (blocks: DiffBlock[]) => blocks.map((block) => block.kind)

const segmentsOf = (block: DiffBlock) => block.segments.map((segment) => [segment.type, segment.text])

describe('isRichTextDoc', () => {
  it('accepts a doc node with content', () => {
    expect(isRichTextDoc({ type: 'doc', content: [] })).toBe(true)
  })

  it.each([
    ['a doc without content', { type: 'doc' }],
    ['another node type', { type: 'paragraph', content: [] }],
    ['null', null],
    ['a string', 'doc'],
    ['a number', 1],
    ['an array', []],
  ])('rejects %s', (_label, value) => {
    expect(isRichTextDoc(value)).toBe(false)
  })
})

describe('non-document input', () => {
  it('treats a non-doc value as empty on either side', () => {
    expect(summary(diffRichText(null, doc(paragraph('Hello'))))).toEqual([['added', 'Hello']])
    expect(summary(diffRichText(doc(paragraph('Hello')), 'nope'))).toEqual([['removed', 'Hello']])
  })

  it('produces nothing when neither side is a document', () => {
    expect(diffRichText(null, undefined)).toEqual([])
  })
})

describe('block collection', () => {
  it('reports an untouched paragraph as unchanged', () => {
    expect(summary(diffRichText(doc(paragraph('Hello')), doc(paragraph('Hello'))))).toEqual([
      ['unchanged', 'Hello'],
    ])
  })

  it('labels headings with their level', () => {
    const blocks = diffRichText(
      doc({ type: 'heading', attrs: { level: 2 }, content: [text('Title')] }),
      doc({ type: 'heading', attrs: { level: 2 }, content: [text('Title')] })
    )

    expect(blocks[0].label).toBe('H2')
  })

  it('defaults a heading with no level to H1', () => {
    expect(diffRichText(doc({ type: 'heading', content: [text('T')] }), null)[0].label).toBe('H1')
  })

  it('prefixes bullet list items', () => {
    const bulletList = {
      type: 'bulletList',
      content: [
        { type: 'listItem', content: [paragraph('One')] },
        { type: 'listItem', content: [paragraph('Two')] },
      ],
    }

    expect(summary(diffRichText(null, doc(bulletList)))).toEqual([
      ['added', '• One'],
      ['added', '• Two'],
    ])
  })

  it('numbers ordered list items from the start attribute', () => {
    const orderedList = {
      type: 'orderedList',
      attrs: { start: 3 },
      content: [
        { type: 'listItem', content: [paragraph('Third')] },
        { type: 'listItem', content: [paragraph('Fourth')] },
      ],
    }

    expect(summary(diffRichText(null, doc(orderedList)))).toEqual([
      ['added', '3. Third'],
      ['added', '4. Fourth'],
    ])
  })

  it('numbers from 1 without a start attribute', () => {
    const orderedList = {
      type: 'orderedList',
      content: [{ type: 'listItem', content: [paragraph('First')] }],
    }

    expect(summary(diffRichText(null, doc(orderedList)))).toEqual([['added', '1. First']])
  })

  it('prefixes blockquote content and nests prefixes', () => {
    const quotedList = {
      type: 'blockquote',
      content: [
        {
          type: 'bulletList',
          content: [{ type: 'listItem', content: [paragraph('Quoted')] }],
        },
      ],
    }

    expect(summary(diffRichText(null, doc(quotedList)))).toEqual([['added', '> • Quoted']])
  })

  it('renders a horizontal rule as a glyph, ignoring any prefix', () => {
    expect(summary(diffRichText(null, doc({ type: 'horizontalRule' })))).toEqual([['added', '―――']])
  })

  it('joins table cells with pipes', () => {
    const row = {
      type: 'tableRow',
      content: [
        { type: 'tableHeader', content: [paragraph('Name')] },
        { type: 'tableCell', content: [paragraph('Qty')] },
      ],
    }

    expect(summary(diffRichText(null, doc({ type: 'table', content: [row] })))).toEqual([
      ['added', 'Name | Qty'],
    ])
  })

  it('keeps code blocks as their own block', () => {
    expect(summary(diffRichText(null, doc({ type: 'codeBlock', content: [text('const a = 1')] })))).toEqual([
      ['added', 'const a = 1'],
    ])
  })

  it('descends through unknown container nodes', () => {
    expect(
      summary(diffRichText(null, doc({ type: 'mysteryWrapper', content: [paragraph('Inside')] })))
    ).toEqual([['added', 'Inside']])
  })

  it('produces an empty block for a paragraph with no content', () => {
    expect(summary(diffRichText(null, doc({ type: 'paragraph' })))).toEqual([['added', '']])
  })
})

describe('inline text flattening', () => {
  const inline = (...content: unknown[]) =>
    summary(diffRichText(null, doc({ type: 'paragraph', content })))[0][1]

  it('concatenates adjacent text nodes', () => {
    expect(inline(text('Hello '), text('world'))).toBe('Hello world')
  })

  it('renders a hard break as a newline', () => {
    expect(inline(text('a'), { type: 'hardBreak' }, text('b'))).toBe('a\nb')
  })

  it('renders a placeholder token by its key', () => {
    expect(inline({ type: 'placeholderToken', attrs: { key: 'first_name' } })).toBe('{first_name}')
    expect(inline({ type: 'placeholderToken' })).toBe('{}')
  })

  it('renders an AI mention by its label, falling back to the id', () => {
    expect(inline({ type: 'aiMention', attrs: { label: 'Ada', id: 'u1' } })).toBe('@Ada')
    expect(inline({ type: 'aiMention', attrs: { id: 'u1' } })).toBe('@u1')
    expect(inline({ type: 'aiMention' })).toBe('@')
  })

  it('descends into unknown inline nodes', () => {
    expect(inline({ type: 'mysterySpan', content: [text('deep')] })).toBe('deep')
  })

  it('renders a text node with no text as empty', () => {
    expect(inline({ type: 'text' })).toBe('')
  })
})

describe('added, removed and unchanged blocks', () => {
  it('reports an appended paragraph as added, leaving the rest unchanged', () => {
    expect(
      summary(diffRichText(doc(paragraph('One')), doc(paragraph('One'), paragraph('Two'))))
    ).toEqual([
      ['unchanged', 'One'],
      ['added', 'Two'],
    ])
  })

  it('reports a deleted paragraph as removed', () => {
    expect(
      summary(diffRichText(doc(paragraph('One'), paragraph('Two')), doc(paragraph('One'))))
    ).toEqual([
      ['unchanged', 'One'],
      ['removed', 'Two'],
    ])
  })

  it('reports a wholly different document as removed then added', () => {
    expect(summary(diffRichText(doc(paragraph('Alpha')), doc(paragraph('Zulu'))))).toEqual([
      ['removed', 'Alpha'],
      ['added', 'Zulu'],
    ])
  })
})

describe('word-level pairing', () => {
  it('word-diffs a similar pair into one changed block', () => {
    const blocks = diffRichText(
      doc(paragraph('The quick brown fox jumps')),
      doc(paragraph('The quick red fox jumps'))
    )

    expect(kinds(blocks)).toEqual(['changed'])
    expect(segmentsOf(blocks[0])).toEqual([
      ['equal', 'The quick '],
      ['removed', 'brown'],
      ['added', 'red'],
      ['equal', ' fox jumps'],
    ])
  })

  it('keeps a dissimilar pair as separate removed and added blocks', () => {
    expect(
      summary(
        diffRichText(
          doc(paragraph('Completely unrelated sentence here')),
          doc(paragraph('Nothing alike whatsoever friend'))
        )
      )
    ).toEqual([
      ['removed', 'Completely unrelated sentence here'],
      ['added', 'Nothing alike whatsoever friend'],
    ])
  })

  it('carries the new label onto a changed block', () => {
    const blocks = diffRichText(
      doc({ type: 'heading', attrs: { level: 1 }, content: [text('The quick brown fox')] }),
      doc({ type: 'heading', attrs: { level: 3 }, content: [text('The quick red fox')] })
    )

    expect(blocks[0].label).toBe('H3')
  })

  it('skips a removed block whose successor is the better partner', () => {
    const blocks = diffRichText(
      doc(paragraph('Wholly different opening line'), paragraph('The quick brown fox jumps')),
      doc(paragraph('The quick red fox jumps'))
    )

    expect(kinds(blocks)).toEqual(['removed', 'changed'])
    expect(blocks[0].segments[0].text).toBe('Wholly different opening line')
    expect(segmentsOf(blocks[1])).toEqual([
      ['equal', 'The quick '],
      ['removed', 'brown'],
      ['added', 'red'],
      ['equal', ' fox jumps'],
    ])
  })

  it('skips an added block whose successor is the better partner', () => {
    const blocks = diffRichText(
      doc(paragraph('The quick brown fox jumps')),
      doc(paragraph('Wholly different opening line'), paragraph('The quick red fox jumps'))
    )

    expect(kinds(blocks)).toEqual(['added', 'changed'])
    expect(blocks[0].segments[0].text).toBe('Wholly different opening line')
  })

  it('drains a longer removed run after the pairs are exhausted', () => {
    expect(
      kinds(
        diffRichText(
          doc(paragraph('Alpha one'), paragraph('Beta two'), paragraph('Gamma three')),
          doc(paragraph('Alpha uno'))
        )
      )
    ).toEqual(['changed', 'removed', 'removed'])
  })

  it('drains a longer added run after the pairs are exhausted', () => {
    expect(
      kinds(
        diffRichText(
          doc(paragraph('Alpha one')),
          doc(paragraph('Alpha uno'), paragraph('Beta two'), paragraph('Gamma three'))
        )
      )
    ).toEqual(['changed', 'added', 'added'])
  })

  it('flushes a pending removed run before an unchanged block', () => {
    expect(
      summary(
        diffRichText(
          doc(paragraph('Dropped entirely here'), paragraph('Shared')),
          doc(paragraph('Shared'))
        )
      )
    ).toEqual([
      ['removed', 'Dropped entirely here'],
      ['unchanged', 'Shared'],
    ])
  })
})

describe('formatting-only changes', () => {
  it('flags a mark-only change as changed and formattingOnly', () => {
    const blocks = diffRichText(
      doc(paragraph('Hello world')),
      doc(paragraph('Hello world', [{ type: 'bold' }]))
    )

    expect(blocks).toHaveLength(1)
    expect(blocks[0].kind).toBe('changed')
    expect(blocks[0].formattingOnly).toBe(true)
    expect(segmentsOf(blocks[0])).toEqual([['equal', 'Hello world']])
  })

  it('flags a retargeted link as formatting-only', () => {
    const link = (href: string) =>
      doc({ type: 'paragraph', content: [text('Docs', [{ type: 'link', attrs: { href } }])] })

    expect(diffRichText(link('https://a.test'), link('https://b.test'))[0].formattingOnly).toBe(true)
  })

  it('flags a heading level change as formatting-only', () => {
    const heading = (level: number) =>
      doc({ type: 'heading', attrs: { level }, content: [text('Title')] })
    const blocks = diffRichText(heading(2), heading(3))

    expect(blocks[0].formattingOnly).toBe(true)
    expect(blocks[0].label).toBe('H3')
  })

  it('does not flag a text change as formatting-only', () => {
    const blocks = diffRichText(
      doc(paragraph('The quick brown fox')),
      doc(paragraph('The quick red fox'))
    )

    expect(blocks[0].formattingOnly).toBe(false)
  })

  it('leaves formattingOnly unset on plain added and removed blocks', () => {
    const blocks = diffRichText(doc(paragraph('One')), doc(paragraph('One'), paragraph('Two')))

    expect(blocks[1].kind).toBe('added')
    expect(blocks[1].formattingOnly).toBeUndefined()
  })
})

describe('mixed documents', () => {
  it('keeps blocks in document order across every kind', () => {
    const before = doc(
      { type: 'heading', attrs: { level: 1 }, content: [text('Title')] },
      paragraph('Intro stays the same'),
      paragraph('The quick brown fox jumps'),
      paragraph('Trailing paragraph to drop')
    )
    const after = doc(
      { type: 'heading', attrs: { level: 1 }, content: [text('Title')] },
      paragraph('Intro stays the same'),
      paragraph('The quick red fox jumps'),
      paragraph('Freshly written closing note')
    )

    expect(kinds(diffRichText(before, after))).toEqual([
      'unchanged',
      'unchanged',
      'changed',
      'removed',
      'added',
    ])
  })

  it('diffs an emptied document as all removed', () => {
    expect(
      kinds(diffRichText(doc(paragraph('One'), paragraph('Two')), doc()))
    ).toEqual(['removed', 'removed'])
  })
})
