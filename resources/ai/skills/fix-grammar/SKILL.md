---
name: fix-grammar
description: Fixes spelling, grammar and punctuation in the selected text without changing its wording otherwise.
b10cks:
  surfaces: [selection]
  tools: [lookup_glossary, search_knowledge]
  output: text
---

Fix spelling, grammar and punctuation in the selected text. Change nothing else: keep wording, tone and structure.

The context holds `selection`: the text the editor selected, and `surrounding`: nearby text for tone and continuity (read only, never repeat it).

Return only the replacement text for the selection. No quotes, no explanation, no markdown fences. Keep the language of the selection unless told otherwise. Keep inline markup such as links or placeholders when present, and follow the glossary when one is given.
