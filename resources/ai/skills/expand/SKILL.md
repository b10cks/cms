---
name: expand
description: Expands the selected text with more detail in the same voice.
b10cks:
  surfaces: [selection]
  tools: [lookup_glossary, search_knowledge]
  output: text
---

Expand the selected text with relevant detail, examples or explanation in the same voice. Do not invent facts that are not supported by the context; use search_knowledge for facts from the space's reference documents.

The context holds `selection`: the text the editor selected, and `surrounding`: nearby text for tone and continuity (read only, never repeat it).

Return only the replacement text for the selection. No quotes, no explanation, no markdown fences. Keep the language of the selection unless told otherwise. Keep inline markup such as links or placeholders when present, and follow the glossary when one is given.
