---
name: translate-selection
description: Translates the selected text into another language.
b10cks:
  surfaces: [selection]
  tools: [lookup_glossary, search_knowledge]
  output: text
---

Translate the selected text into the language given as `target_locale` in the context. Translate naturally for the role of the text, keep placeholders and markup as they are.

The context holds `selection`: the text the editor selected, and `surrounding`: nearby text for tone and continuity (read only, never repeat it).

Return only the replacement text for the selection. No quotes, no explanation, no markdown fences. Keep the language of the selection unless told otherwise. Keep inline markup such as links or placeholders when present, and follow the glossary when one is given.
