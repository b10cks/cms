# W5 Management API documentation and local MCP parity

Base commit `f2e21923`. This slice documents the approved content operations lifecycle in the generated Management API spec and exposes it through the CMS's local Laravel MCP server. It does not finish the brief. No domain behavior, content plan, acceptance rule, UI, locale file or sibling SDK changed. D10 stays deferred: `review.derived_from` is documented as a record nothing reads.

## Integration requests

1. **Required.** `routes/private_mgmt.php` must carry the parent's line in the `spaces/{space}` group:

   ```php
   require __DIR__.'/ai_knowledge_impact.php';
   ```

   Until then `ManagementServerTest::every_operation_uri_matches_a_registered_route` fails on `ai.knowledgeImpact.*`, and those calls answer 404. It is the only failure in `tests/Feature/Mcp` and `tests/Feature/Documentation` on this branch. The new test files register the route file themselves when the line is missing.
2. The committed spec contains the `team_id` filter from the parent's `SpaceFilter` diff, unchanged. That diff is not in this branch. A regeneration without it drops the entry.
3. Parent can drop its copies of the five `Ai*Resource` diffs, the `KnowledgeResolver` duplicate removal and the spec diff. The resource files then hold the adopted changes plus more annotations.
4. `RunToolFactory` hooks stay with the parent. Nothing here reads them.
5. The W4 grouped questions hook in `AiOperations::launch()` is needed for an answer given over MCP or HTTP to reach the resumed batch. This slice does not own that file.

## MCP operations

All go through `b10cks_mgmt_call` and `InternalRequestDispatcher`, so they run the HTTP routes, FormRequests, policies and controllers. 49 operations added, 342 to 391. Paths are under `/mgmt/v1`.

"Provider" says whether the call itself can start paid provider work.

### Operation lifecycle, `ai.operations.*`, arguments `spaceId`, `id`

| Operation | Route under `spaces/{space}/ai/operations` | Provider |
| --- | --- | --- |
| `list` | `GET` | No |
| `create` | `POST` | No |
| `get` | `GET {id}` | No |
| `updatePlan` | `PATCH {id}` | No |
| `startPlanning` | `POST {id}/plan` | **Paid** |
| `approve` | `POST {id}/approve` `{plan_hash, scope_hash, max_usd}` | **Paid** |
| `refreshScope` | `POST {id}/scope` | No |
| `pause`, `cancel` | `POST {id}/pause`, `{id}/cancel` | No |
| `resume` | `POST {id}/resume` | **Paid** |
| `extendBudget` | `POST {id}/budget` `{max_usd}` | **Paid**: on a budget pause it also resumes |
| `items`, `arrivals` | `GET {id}/items`, `{id}/arrivals` | No |
| `questions`, `questionMembers`, `answeredQuestions` | `GET {id}/questions`, `{id}/questions/{questionId}/members`, `{id}/questions/answered` | No |
| `answerQuestions` | `POST {id}/questions/answers` | **Paid**: resumes the named outputs |
| `review`, `reviewItems` | `GET {id}/review`, `{id}/review/items` | No |
| `acceptGroup`, `rejectGroup` | `POST {id}/review/accept`, `{id}/review/reject` | No. Draft content only |

`ai.changesets.get`, `accept` and `reject` already existed. `accept` forwards `values` and `overrides`, and its description now states the rechecking flow.

### Markdown imports, `ai.glossaries.*` and `ai.teamGlossaries.*`

Arguments: `spaceId` or `teamId`, `glossaryId`, `importId`, `revisionId`. None of the three falls back to `id`, so a wrong argument cannot resolve two placeholders to one ID. Entry IDs travel in payloads. None of these starts provider work.

| Operation | Route under `{owner}/ai/glossaries/{glossaryId}` |
| --- | --- |
| `createMarkdownImport` | `POST markdown-imports`: `markdown` text, or `file: {filename, content_base64}` |
| `listMarkdownImports`, `getMarkdownImport` | `GET markdown-imports`, `markdown-imports/{importId}` |
| `markdownImportEntries` | `GET markdown-imports/{importId}/entries` |
| `resolveMarkdownImport`, `refreshMarkdownImport` | `POST markdown-imports/{importId}/resolve`, `/refresh` |
| `publishMarkdownImport` | `POST markdown-imports/{importId}/publish` |
| `concepts`, `revisions`, `getRevision` | `GET concepts`, `revisions`, `revisions/{revisionId}` |

### Knowledge impact, `ai.knowledgeImpact.*`, arguments `spaceId`, `scanId`

`status`, `startScan`, `getScan`, `resumeScan`, `candidates`, `createMaintenance`. None makes a provider call. `createMaintenance` answers a draft with no approved plan, no budget and no run. Starting it takes `ai.operations.approve`.

### Classification, `ai.assets.*`

`classify` (**paid**, values save directly on the asset) and `classifyStatus` (reads `field_report`). The controller reads the space from the query, so both take `params.spaceId`.

### Tool support

- `filesOptional` on a registry entry: an absent `file` leaves the JSON payload alone, a present one must be `{filename, content_base64}`. Text and upload share one operation. The existing bounds apply: 10 MB checked on the encoded length before decoding, the FormRequest's `max` on text, `extensions:md,markdown,txt`, and `prohibits` for sending both.
- Explicit `[]` and `null` are forwarded as given, on both the JSON and the multipart path.
- A write's `params` now reach the URL query. They went into the body before, where `request()->query()` cannot see them. No existing write operation accepted `params`.
- Error text keeps `reason`, `code`, `head_revision_id`, `base_revision_id`, `entry_ids`, `added`, `removed`, `count` and `limit`, so an agent can tell `stale_base` from `plan_changed`. The body is otherwise still withheld.
- `ManagementServer` instructions state that nothing in the lifecycle publishes content, and that approving, raising a budget and publishing an import are decisions for a person.

That last rule is a description, not enforcement. MCP runs with the caller's permissions, as HTTP does. An agent holding an owner's token can call `approve` or `extendBudget`, because they are the same explicit calls the API offers. What holds in code: every plan, hash and amount is required input, nothing raises a budget by itself, an answer never does, and these operations cannot publish content.

## API documentation

The spec comes from the existing generator. Two things it could not express needed a small addition to it, since a `JsonResponse` had no schema and any nested JSON column read as `string` or an empty object.

| Convention | Where | Effect |
| --- | --- | --- |
| `@resourceProperty field shape=@Name?` | Resource docblock | Extends the existing annotation |
| `@requestProperty field shape=...`, `@requestMultipart` | FormRequest docblock | An object keyed by ID, which rules read as a list. JSON and multipart bodies |
| `@apiResponse 409 @Name text`, `data:@Name`, `page:@Name` | Controller method | Envelope, statuses and errors |
| `@apiQuery name! shape text`, `@apiBody @Name` | Controller method | Inline validation |

`SchemaShape` expands the notation. `Schemas/ai_content_operations.php` holds 70 named shapes for what the code writes to JSON columns and service arrays: counts, coverage, plan, part outcomes, grouped questions, review groups, semantic review and edits, overrides, evidence, proposal policy, dependency record, provider retry, run policy, import mapping, entries, document rules, conflicts, revisions, impact changes and reasons, and classification reports. Everything else stays inferred.

Also added: a route parameter the controller names differently (`$owner` for `{space}`) is now declared, and `present` counts as required, so `base_revision_id` shows as required and nullable.

```text
APP_KEY=base64:... APP_URL=https://b10cks.test php artisan docs:generate --prefix=mgmt/v1 --output=docs/public/specs
```

Set `APP_URL`, since the spec's contact URL comes from it. Run it without `APP_ENV=testing`. Under it, 27 unrelated request bodies appear because some `rules()` behave differently there. 302 paths, 443 operations, 148 component schemas, up from 274 paths and 71 schemas in the parent's diff.

### Spec changes outside the AI paths

- Path parameters newly declared: `asset-folders/{asset_folder}`, `automation-actions/{automation_action}`, `data-sources/{data_source}` and its entries, `field-plugins/{field_plugin}`, `teams/{team}/users/{user}`.
- `members/{user}/expiry` and `teams/{team}/users/{user}/expiry`: the `present` field shows as required.
- `AssetResource` and `LinkedAssetContentResource` match the code on this base. The parent's spec was older.

## Outstanding composition hooks

The composition work is not on this base, so nothing here invents its fields. When it lands:

1. Translation plans with metadata: add its keys to `AiOperationPlan` in the catalog and the `ai.operations.updatePlan` description.
2. A pending create as a metadata target: any new part format goes in the `parts` description on `AiOperationItemResource`, new outcomes in `$outcomes`.
3. New dependency fields go in `AiProposalDependency`. Keep it described as recorded only unless D10 is decided.
4. A new route: add a registry entry, its placeholder in `MgmtCallTool::schema()` and the `ManagementServerTest` list, and `@apiResponse` on the controller.
5. Regenerate the spec with the command above.

`OperationsMirrorTest` lists what the CMS has ahead of the SDK in `NOT_IN_THE_SDK_YET`. Remove it when the SDK catches up.

## Checks

SQLite in memory, PHP 8.5.12, PHPUnit 13.4.5, fake driver, no paid calls, no migration, no running server, throwaway `APP_KEY`, cloned `vendor/`.

```text
With the route line applied:
vendor/bin/phpunit tests/Feature/Mcp tests/Feature/Documentation                 OK (31 tests, 2137 assertions, 1 skipped)
B10CKS_SDK_PATH=../sdk vendor/bin/phpunit tests/Feature/Mcp/OperationsMirrorTest.php  OK

Before the last annotation edit, with the route line applied:
tests/Feature/Mcp tests/Feature/Documentation, AiOperationQuestionsTest, AiKnowledgeImpactTest,
AiGlossaryMarkdownImportTest, AiPolicySnapshotTest, AiSemanticReviewTest, AiOperationCompletionTest,
AssetClassificationTest                                                          OK (143 tests, 3227 assertions, 2 skipped)

As committed, without the line: tests/Feature/Mcp tests/Feature/Documentation    1 failure, request 1
```

The skips are the SDK mirror without a sibling path, and `the_next_batch_gets_answers_as_context_for_the_answered_part_only`, which waits for the W4 `answersForBatch` hook in `AiOperations::launch()`. On this base an MCP answer is stored and resumes the batch, but the batch does not see the answer until that hook lands. See integration request 5.

`AiContentOperationsMcpTest` drives real controllers through the dispatcher:

- Markdown import through resolve and publish with `document_rule_keys: []` and `base_revision_id: null`, then 409 `stale_base` with the head ID, then the rule published and the draft closed.
- Text and base64 upload, both refused together, oversize of each, wrong extension.
- Separate identifiers, foreign and team-owned rows at 404, an outsider refused.
- Plan, approval hash and budget errors, grouped answers with `recorded` and `duplicate`, a spent budget refusing resume until the explicit call.
- `overrides` forwarded, a blank reason refused, the reason recorded, the entry unpublished after acceptance.
- Discovery through maintenance draft with no driver call and no new run.
- `field_report` through the query parameter, on a read and a write.

Spec checks:

- Two generations are byte-identical. Every `$ref` resolves. AI paths declare every placeholder.
- `AiContentOperationsSpecTest` asserts the nested shapes, request objects and new statuses.
- `@scalar/openapi-parser` from the parent's `node_modules`, run once as a scratch script: 9 errors, none on a path or schema of this slice. The parent's spec had 12.

The 9 are existing problems in `provider/users` and `spaces/{space}` request bodies (empty `properties` as a JSON list) and `content-menu` and `releases` filter parameters (`oneOf` with `type: null`). `GET provider/notes/{providerNote}` also declares no parameter, because that controller has no `show`.

Pint passes on the MCP, resource, request and controller files and on the new files. `RouteAnalyzer`, `OpenApiGenerator`, `SchemaBuilder`, both parsers and `config/docs.php` already failed Pint at the base and were not reformatted. `git diff --check` is clean.

## Not verified and limits

- MySQL, a real queue, the HTTP MCP transport end to end (the test harness calls the tool with a session user) and real providers.
- `bun run docs:build` was not run, as builds were out of scope. The VitePress renderer has hung on bad specs before, so run it before deploying docs.
- Schema shapes were written by reading the code that fills those columns. Only the fields named in the tests are asserted against real responses. A drift in a JSON column would not fail anything else.
- Single-resource `data` envelopes are documented on the AI endpoints only. Elsewhere the spec still omits the wrapper, which is wrong and predates this work.
- `document_rule_keys: []` alone with no selected `entry_ids` is a 422 on `entry_ids`, because `required_without_all` treats an empty list as absent. Same as HTTP, and left as it is.
- `sanitize()` drops keys named `constructor` and `prototype`, also as a Markdown label in `mapping.fields`.
- No endpoint reads a policy snapshot or live document rules by ID. IDs and frozen rule text reach clients through runs, proposals, findings, drafts and revisions. A read route would be new runtime surface.
- `ai.glossaries.import` and `export` still have no team variant. Not part of this lifecycle.
- `docs/guides/mcp-server.md` and `docs/api/management-api.md` do not mention this lifecycle yet.
