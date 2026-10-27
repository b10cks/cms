---
name: rewrite
description: Rewrites the selected text more clearly while keeping its meaning and length.
b10cks:
  surfaces: [selection]
  tools: [lookup_glossary, search_knowledge]
  output: text
---

Rewrite the selected text so it reads more clearly and naturally. Keep its meaning, facts and roughly its length.

The context holds `selection`: the text the editor selected, and `surrounding`: nearby text for tone and continuity (read only, never repeat it).

Return only the replacement text for the selection. No quotes, no explanation, no markdown fences. Keep the language of the selection unless told otherwise. Keep inline markup such as links or placeholders when present, and follow the glossary when one is given.
