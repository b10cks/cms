import { describe, expect, it } from 'vitest'

import { readSkillFields, writeSkillFields } from '~/lib/skillManifest'

const SOURCE = `---
name: product-copy
description: Writes product copy.
b10cks:
  surfaces:
    - selection
    - cmdk
  tools: [get_entry, search_knowledge]
  output: text
---

Write in our voice.
`

describe('skillManifest', () => {
  it('reads the form fields and writes them back without touching other frontmatter', () => {
    expect(readSkillFields(SOURCE)).toEqual({
      name: 'product-copy',
      description: 'Writes product copy.',
      surfaces: ['selection', 'cmdk'],
      instructions: 'Write in our voice.',
    })

    const written = writeSkillFields(SOURCE, {
      name: 'product-copy',
      description: 'Copy: short, warm',
      surfaces: ['bulk:content'],
      instructions: 'New instructions.',
    })

    expect(written).toBe(`---
name: product-copy
description: "Copy: short, warm"
b10cks:
  surfaces: [bulk:content]
  tools: [get_entry, search_knowledge]
  output: text
---

New instructions.
`)
    expect(readSkillFields(written).description).toBe('Copy: short, warm')
  })
})
