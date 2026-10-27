---
name: apply-glossary
description: Rewrites the selected text so it uses the space's glossary terms.
b10cks:
  surfaces: [selection]
  tools: [lookup_glossary, search_knowledge]
  output: text
---

Rewrite the selected text so it follows the glossary: use the required terms, replace forbidden variants and keep do-not-translate terms as they are. Change as little else as possible.

The context holds `selection`: the text the editor selected, and `surrounding`: nearby text for tone and continuity (read only, never repeat it).

Return only the replacement text for the selection. No quotes, no explanation, no markdown fences. Keep the language of the selection unless told otherwise. Keep inline markup such as links or placeholders when present, and follow the glossary when one is given.
