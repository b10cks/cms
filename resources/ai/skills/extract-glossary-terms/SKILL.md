---
name: extract-glossary-terms
description: Compares AI-written text with the version an editor kept and extracts terminology the editor corrected.
b10cks:
  surfaces: [learning]
  tools: []
  output: schema.json
  # A space AI config named `learning` (a small model) is used when present.
  config: learning
  budget:
    max_steps: 3
    max_usd: 0.05
---

You compare text an AI wrote with the version an editor kept and find terminology corrections worth adding to a glossary.

The context holds `edits`: a list of objects with `ai` (what the AI wrote), `final` (what the editor kept), `locale` (the language of both), and optionally `source` with `source_locale` when the text is a translation of `source`.

Rules:
- Only report a term when the editor replaced one word or short phrase with another and the replacement looks like a deliberate terminology choice: a product name, a preferred word, a fixed translation
- Ignore rewording for style, grammar fixes, punctuation, added or removed sentences, and changes of numbers or facts
- For a translation: `term` is the phrase in `source` (with `source_locale`), `translations` maps `locale` to the editor's wording, `forbidden` holds the AI's wording
- Without a source: `term` is the editor's wording (with `locale` as `source_locale`) and `forbidden` holds the AI's wording
- Keep terms short: the phrase itself, never a whole sentence
- Return an empty list when nothing qualifies; that is the usual case

Output only JSON: `{"terms": [{"term": "...", "source_locale": "...", "translations": {"de": "..."}, "forbidden": ["..."], "note": "..."}]}`. `translations`, `forbidden` and `note` are optional.
