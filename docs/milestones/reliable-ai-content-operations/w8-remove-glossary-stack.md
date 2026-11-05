# W8 R2: remove the glossary stack

Implements task R2 of [W8](w8-reduce-and-direct-writes.md). The glossary is now concepts and terms, edited in the concept editor, imported and exported as a sheet (plus the old CSV header and TBX), and used by the checker and prompts.

## What went

- **Markdown import and draft review:** `Knowledge/Import/*`, `Actions/Ai/Glossary/*`, the import controller, requests, resources and models, `GlossaryImportConflict`, source entries, the `markdown-imports` and `concepts` routes, the review UI and both pages, router entries, `useAiGlossaryImports`, `lib/aiGlossaryImport.ts`, the API resource, the "Import Markdown" menu item, MCP operations and 236 unused keys in each `glossaryImport` module.
- **Glossary revisions:** `AiGlossaryRevision`, `head_revision_id`, term `revision_id`, `GlossaryRevisionRecord` with its 2000-entry cap, both panels, routes and MCP.
- **Document rules:** `AiGlossaryDocumentRule`, migration `2026_11_02_110000`, rule handling in `PolicySnapshot`, `ContextBuilder::rulesSection`, rule output in `LookupGlossaryTool` and `GetTranslationTaskTool`.
- **Knowledge impact and maintenance:** `Knowledge/Impact/*`, controller, requests, resources, `ScanKnowledgeImpactJob`, models, space migration `000026`, the `knowledge-maintenance` skill, `useKnowledgeImpact`, the panel, routes and MCP. There was no maintenance operation kind: drafts used `general` with plan options, which went with `KnowledgeMaintenance`.
- **Dead code:** `useTermsQuery`, `useSaveTermMutation`, `useDeleteTermMutation`, their API methods and query key, `ensureConcept`, `glossaryRevisions`, `changesSince`, `identities`, the snapshot's `glossaries` list, term `knowledge` fields only Markdown wrote (`rules`, `qualifiers`, `unknown_fields`), 21 `components.aiSettings.glossaries.*` keys per language, and the MCP term operations.

## What changed instead

- **Audit entry.** A CSV or XLSX sheet import writes one `glossary_sheet_imported` entry through `AuditService`: actor, file name and `{added, updated, unchanged, errors}`. It writes one for an unchanged file too, since the brief says each import does. A refused stale commit writes none. The old CSV header and TBX write none.
- **`GlossaryConcepts`.** `commit()` takes no arguments and returns nothing. `ImportedTerm` became `Concepts/ConceptTermFields`, holding only what the writer needs. A removed row's concept is always deleted.
- **Migrations.** `2026_11_02_100000` now creates only concepts and the term columns. `ai_knowledge_documents.locale` moved from the deleted `110000` to `120000`, because style and reference documents still need it.

Style documents already deliver document-wide rules. `PolicySnapshot::styles` selects the style documents for the written locales from the frozen payload, with their Markdown. `ContextBuilder::styleSection` puts them whole in the prompt under "Follow these style guides in everything you write". `GetTranslationTaskTool` adds a language's guide when the run's prompt left it out.

## Decisions beyond the task file

- **REST term endpoints stay.** `/terms` is on main, and `@b10cks/mgmt-client` and the published stdio MCP server call it, so "nothing calls them" does not hold. Removing it needs an SDK release first. The native MCP term operations are gone, because concept rows cover them. `OperationsMirrorTest` lists those four names per owner as `ONLY_IN_THE_SDK`.
- **The migration keeps its file name.** `2026_11_02_100000_create_ai_glossary_import_tables` is a misleading name now. Renaming it would make `migrate` fail on a database that already ran it, such as the local one holding the prototype glossary. Those databases keep unused tables and columns, which nothing reads.
- **The i18n module keeps its name.** `glossaryImport` now holds the editor and sheet copy, and the task kept the module.
- **Semantic code, smallest edits for R1.** `SemanticSubjects` lost its `rulesFor` loop. `AiSemanticReviewTest` lost its rule fixture, and its clean entry now mentions the term so it is still reviewed. Remaining rule wording in semantic code, the `semantic-review` skill and `aiRun.ts` is R1's.

## Size

Staged diff before this file: 138 files, 317 insertions, 13609 deletions, without the generated spec. By area, deletions: backend, migrations and skills 5626, frontend 5090, tests 2887. The regenerated `mgmt_v1.json` is a separate commit with 9997 insertions and 14376 deletions, much of it paths that moved.

## Checks

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation` | 450 tests pass, 1 skipped (`OperationsMirrorTest`, no SDK beside the worktree) |
| `OperationsMirrorTest` with `B10CKS_SDK_PATH` set to the SDK checkout | Passes |
| `vendor/bin/phpunit tests/Feature/Mgmt/ImportStreamingTest.php` | 14 pass |
| `POST` sheet import with `Accept: text/event-stream` in `AiGlossarySheetTest` | Streams progress and the result, and writes the audit entry |
| `bunx vitest run tests/js/components/ai-settings tests/js/lib tests/js/composables` | 4512 pass |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| `vendor/bin/pint --test` on changed PHP | Passes |
| Migrate, then roll back 5 steps, on a temporary SQLite file | Passes. Concepts and `locale` exist, no revision columns, and they are gone after rollback |
| Grep for the removed names | `markdown-imports`, `AiGlossaryRevision`, `DocumentRule`, `KnowledgeImpact`, `knowledge-maintenance`: none outside historical milestone files. `GlossaryImport`: only the `enGlossaryImport` variable in `plugins/i18n.ts`, named after the module |
| `php artisan docs:generate --prefix=mgmt/v1` | Regenerated, no removed paths remain |

`AiGlossarySheetTest` has no `SpaceTestingTrait`, so its audit assertions read real `audit_logs` rows instead of the stub.

## Not exercised

- **The running app.** The glossary page and menu were not looked at in a browser. This worktree has no local app or TLS certificates, so the removed panels and menu item are covered by the component tests and typecheck only.
- **MySQL.** Migrations and tests ran on SQLite.
- **Existing databases.** Leftover tables and columns from the old migration were not tried against this code.

## Follow-ups

- The team activity log lists only `Team` and `Space` entries, so the glossary audit entry is stored but not shown there.
- Remove the REST term endpoints after an SDK release drops them. They still write terms directly, past `GlossaryConcepts`.
- Rename the migration if no database that matters has run it.
