import { describe, expect, it } from 'vitest'

import { createEmptyRichTextDoc, isEmptyRichText, toRichTextDoc } from '~/lib/richtext'

describe('isEmptyRichText', () => {
  it.each([[null], [undefined], [[]], [{}]])('treats %o as empty', (value) => {
    expect(isEmptyRichText(value)).toBe(true)
  })

  it.each([[createEmptyRichTextDoc()], [['paragraph']], [{ foo: 1 }], ['']])(
    'does not treat %o as empty',
    (value) => {
      expect(isEmptyRichText(value)).toBe(false)
    }
  )
})

describe('toRichTextDoc', () => {
  it('turns the empty shapes PHP and schema defaults produce into an empty document', () => {
    expect(toRichTextDoc([])).toEqual(createEmptyRichTextDoc())
    expect(toRichTextDoc({})).toEqual(createEmptyRichTextDoc())
    expect(toRichTextDoc(null)).toEqual(createEmptyRichTextDoc())
  })

  it('passes a document through by identity', () => {
    const doc = { type: 'doc', content: [] }

    expect(toRichTextDoc(doc)).toBe(doc)
  })

  it.each([[{ foo: 1 }], [[{ type: 'doc' }]], ['<p>html</p>'], [42]])(
    'rejects %o as not a document',
    (value) => {
      expect(toRichTextDoc(value)).toBeNull()
    }
  )
})
