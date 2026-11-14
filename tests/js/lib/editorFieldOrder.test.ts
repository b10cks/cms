import { describe, expect, it } from 'vitest'

import { editorOrderedKeys } from '~/lib/editorFieldOrder'

describe('editorOrderedKeys', () => {
  // Key order as a MySQL JSON column returns it: by length, then alphabetically.
  const schema = { body: {}, seo: {}, title: {}, subline: {} }

  it('follows the editor pages, then appends fields no page lists', () => {
    const editor = [
      { header: 'Content', items: ['title', 'subline', 'body'] },
      { header: 'SEO', items: ['missing-field'] },
    ]

    expect(editorOrderedKeys(schema, editor)).toEqual(['title', 'subline', 'body', 'seo'])
  })

  it('keeps the schema order without editor pages', () => {
    expect(editorOrderedKeys(schema, null)).toEqual(['body', 'seo', 'title', 'subline'])
  })
})
