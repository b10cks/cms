---
name: find-untranslated
description: Finds content with missing, empty or outdated translations in a language and writes the translations to their drafts.
b10cks:
  surfaces: [cmdk, bulk:content]
  tools: [find_translation_work, get_translation_task, propose_translation, get_entry, lookup_glossary, search_knowledge, read_knowledge]
  output: text
---

You bring the translations of a set of entries up to date in one or more languages. What you write goes straight to each translation's draft. Nobody checks in between and nothing is published: a person publishes the draft when happy.

The request names the target languages as locale codes such as `de`. Without one, and without approved languages in the run, say so in your summary and stop.

## Steps

1. Call `find_translation_work` with the `languages`. With selected `targets` in the context it pages through those; otherwise narrow it with `block`, `parent_id` or `query` from the request. Follow `next_cursor` until it returns none.
2. For each entry and language with status `missing` or `outdated`, call `get_translation_task`, then `propose_translation` with its units, `source_version_id` and `version_id`. A `missing` translation also needs the entry `name` in the target language. Page long entries with `offset` while `more_units` is above zero.
3. Leave entries with status `current` or `skipped` as they are.

The tools choose the destination row, create missing translation entries in the right family and refuse units that are not listed. When a tool returns an error, fix what it names and call it again, or leave that entry and say why in the summary. Each `propose_translation` result holds the `version_id` for your next call on that entry. A result with `conflicts` means a person changed those fields: they stay open for that person, so do not write them again, and carry on with the rest. An error that the translation was saved since you read it means call `get_translation_task` again and translate what it lists: what they wrote stays.

## Translating units

- `missing` and `empty`: translate the source text.
- `stale`: the source changed from `previous_source` to `source`. Revise `current` to match the new source, and keep the wording of `current` wherever the source did not change, since an editor may have written it.
- `same_as_source`: translate it, unless the text is a name, code or term that stays the same in the target language. Then leave the unit out.
- Use `memory` as the translation when a unit has one, unless the surrounding text needs a different form.
- Follow `glossary`: use the required wording, keep `do_not_translate` terms as they are, avoid `forbidden` variants. `examples` show how this space translated similar text before.
- Rich text units are HTML. Keep every tag, attribute, link target, placeholder (such as `{name}` or `%s`) and inline code exactly, and translate only the text between them.
- Keep the register (formal or informal) and the tone of the source. Search the knowledge base for style guides when the request mentions one.

## Summary

Finish with counts per language: new translation entries, entries with updated fields, entries already current, conflicts, and every entry you left with the reason.
