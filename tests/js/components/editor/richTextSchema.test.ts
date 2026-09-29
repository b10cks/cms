import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { Editor, getSchema } from '@tiptap/core'
import { afterEach, describe, expect, it } from 'vitest'

import { createRichTextExtensions } from '~/components/editor/extensions/richTextExtensions'

/**
 * Schema parity with the preview editor. The fixture is shared byte-identical
 * with the SDK (packages/client/src/__fixtures__), whose `@b10cks/richtext`
 * editor and renderer run the same documents.
 */
type Features = Partial<Record<RichTextFeature, boolean>>

const fixture: {
  documents: { name: string; document: Record<string, unknown> }[]
  rejected: { name: string; config: { features?: Features }; document: Record<string, unknown> }[]
} = JSON.parse(
  readFileSync(resolve(__dirname, '../../../fixtures/richtext-documents.json'), 'utf8')
)

const editors: Editor[] = []

afterEach(() => {
  editors.splice(0).forEach((editor) => editor.destroy())
})

describe('rich text fixture', () => {
  it.each(fixture.documents)('round-trips "$name" unchanged', ({ document }) => {
    const editor = new Editor({ extensions: createRichTextExtensions(), content: document })
    editors.push(editor)

    expect(editor.getJSON()).toEqual(document)
  })

  it.each(fixture.rejected)('cannot hold "$name"', ({ config, document }) => {
    const schema = getSchema(createRichTextExtensions(config.features))

    expect(() => schema.nodeFromJSON(document).check()).toThrow()
  })
})
