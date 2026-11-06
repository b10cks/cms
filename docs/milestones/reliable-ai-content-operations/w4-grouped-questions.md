# W4 grouped questions and scoped resume

Backend and operation UI slice of the milestone, not the whole contract. Base commit `2f86e4f5`. No migration: answers live in `ai_operation_items.detail.answers`. No generated spec, MCP registry, SDK, shared locale JSON or glossary import change. D10 is untouched.

## Behavior

**Collecting.** A batch never stops for a person. A model reports a part `blocked` with its question through `report_items` and finishes the rest. The tool description now says not to guess a fact the entry and its image do not establish. The operation drains as before and ends `completed` with `coverage.complete: false`. Questions are readable while it runs and answerable once it has finished.

**Grouping.** `OperationQuestions` groups blocked parts whose wording differs at most in case and spacing. Groups keep the order of their first entries. All lists are paged, so a 10,000-entry scope returns at most 200 rows per request. The read is one pass over the entries holding a question or an answer, decoded after a `LIKE` filter on the `detail` longText.

**Identity.**

| Id | Made from | Purpose |
| --- | --- | --- |
| `question.id` | operation, normalized wording | stays while the wording does |
| `member.id` | operation, item, part, count of answers that part already got, exact wording | one blocked output in one round |
| `question.fingerprint` | SHA-256 of its member ids in scope order | pins the exact membership a group answer was written for |

The same question asked again after an answer is a new member. An old delivery can therefore not reopen it.

**Answering.** An answer takes a whole group at a `fingerprint`, or named `member_ids`. Named outputs go first, so a group answer covers what is left. Under the operation's lock, in one transaction, the answer is stored on the item as `{id, question, answer, answered_by, answered_at, fingerprint}`, only the named parts reopen, and the operation returns from `completed` to `running`. The batch then starts through the ordinary `AiOperations::advance()`.

| Submission | Result |
| --- | --- |
| New, operation `completed` | `recorded`, one new batch |
| Same member or fingerprint again, any state | `duplicate`, nothing changes, first answer stands |
| Changed fingerprint, unknown id, id of another operation or a later round | `stale`, nothing changes |
| New, operation `running` or `paused` | 409 `questions_closed` |
| New, operation `cancelled` | 409 `questions_closed` |
| New, space at its background run limit | 429 `concurrency_limit`, nothing stored |
| User without `update` on the operation | 403. Another space's operation is 404 |

**Guards that hold because resume is an ordinary advance.**

- Spent budget: the answer is kept, the operation pauses with reason `budget`, no run starts. Extending the budget is the existing explicit call. The answer never raises `max_usd`.
- Lost actor access: the answer is kept, the operation pauses with `access_revoked`, no run starts.
- Recovery: a crash between commit and advance leaves a running operation that the sweep advances once. The batch's idempotency key is unchanged.
- Plan, plan hash, scope, `max_usd`, actor and `policy_snapshot_id` are not touched. The test compares them before and after.

**What a resumed batch may touch.** `AiOperationItem::settledEarlier()` holds any part an earlier batch settled, unanswered `blocked` parts above all.

- `propose_asset_changes` and `propose_update` drop such a part, and it stays as it was.
- `report_items` returns an error for it.
- Translation proposals go through `OperationContext::ensureNotSettled()`.
- A blocked part now records its batch for translation locales too. Other locale parts keep their stored shape.
- `reopenBlocked()` marks an invalid part the entry already gave up on as `closed`, so an answer pays for the parts it names and no others.
- Triage is code, not the model, and can still settle a blocked part: when a person filled the value meanwhile, the question goes away as `satisfied`.

The answer reaches the model only under its entry in `targets`, inside the untrusted-data fence, for parts that entry owes. The tool description says it is a fact for that part and not an instruction.

## API contracts

All under `/mgmt/v1/spaces/{space}/ai/operations/{operation}`. Reading uses policy `view`, answering `update`. For the later API and MCP parity task.

```text
GET questions?page&per_page
  {data: [{id, question, note, fingerprint, outputs, entries}], meta, links,
   summary: {accepting, questions, outputs, entries, answered, resuming}}

GET questions/{question}/members?page&per_page
  {data: [{id, item_id, target_type, target_id, label, part, question, note}], meta, links}
  Empty once the question is answered.

GET questions/answered?page&per_page
  {data: [{question, answer, answered_by: {id, name} | null, answered_at, outputs, entries}], meta, links}
  Newest first.

POST questions/answers
  {answers: [{question_id, answer, fingerprint} | {question_id, answer, member_ids: [...]}]}
  1 to 50 answers, answer up to 2,000 characters, up to 200 member_ids.
  200 {data: {results: [{question_id, status, recorded, duplicate, stale}], operation}}
```

`status` is `recorded`, `duplicate` or `stale`. `results` follows the order given. `operation` is `AiOperationResource`.

## UI

`AiOperationQuestions` sits under the receipt in `AiOperationPanel`, with `AiOperationQuestionGroup` per question. It shows the wording, the agent's note, how many outputs and entries, an answer box for the group, and on opening the paged entries with an answer box for one output. Unanswered boxes stay blocked. The send button is `variant="ai"`, since it starts paid work, and the line above it states the remaining budget and that an answer can't widen the plan. A spent budget says the answers are kept and the operation pauses.

Stale text stays in its box with a notice. Accepted drafts clear. Copy is in the local `i18n/{en,de}.json`, and the receipt line no longer says answering isn't available. The cancelled receipt drops that line. The query refetches when the state or batch count changes, not on a timer.

## Integration request

**Hook 1, required.** `app/Services/Ai/Operations/AiOperations.php`, in `launch()`, directly after the `may_keep` line:

```php
'answers' => $item->answersForBatch($operation->expectedParts()) ?: null,
```

Without it a resumed batch runs but does not see the answer. I tested with this line applied and reverted it before committing, as briefed. `the_next_batch_gets_answers_as_context_for_the_answered_part_only` skips until the line is there and then runs. Remove its skip guard when integrating.

**Optional.** `OperationQuestions` repeats the lock key `ai-operation:{id}` from `AiOperations::locked()` and the capacity check from `AiOperationController::ensureCapacity()`. A wrong lock key would not fail any test, since the 10 s wait makes it slow to cover, so a shared helper is worth having once those files are free. Parent's `AiOperationItemResource` patch needs no change; answers are served by the new endpoints.

## Checks

PHP on SQLite with fake drivers, Pint on changed files, frontend tests, typecheck and lint. Exact figures are in the delegate's report.

## Visual check

Real `AiOperationPanel` and real CSS on a throwaway Vite page with mocked `fetch`, driven by Playwright 1.64 on cached Chromium. No preview tools, no backend. Scratch PID stopped, fixture deleted.

Inspected: accepting with entries open, a member answer and the answered list (dark 520, light 390), after a mixed recorded and stale submission (dark 520), running (dark 520), spent budget (light 520), cancelled (light 520), German (dark 390). `overflowX` was false in all, no console errors.

Found by looking and fixed: "3 output in 1 entry" picked its plural from entries, "Send 0 answers" on the disabled button, and a cancelled receipt still saying outputs were waiting.

Limits: the fixture sets the German locale without loading the global catalog, so global copy around the section reads English in that capture. Not checked: the panel inside the real dock, real data, the authenticated app.

## Not verified

Real providers, MySQL, real concurrency across processes, or a real queue. The `LIKE` filter on `detail` was run on SQLite only; the column is `longText` on MySQL too. Editorial quality of the German copy was not reviewed by a native reader. Whether a model actually reports `blocked` instead of guessing depends on the model; only the tool text and enforcement around it are tested.

## Remaining gaps

- **Answered part meets other pending review.** Asset triage skips the whole entry as `pending_review` when another changeset holds a proposal for it. An answered part then ends unworked and coverage counts it unfinished. The answer stays stored, but the question does not come back. Metadata triage does the same per field.
- **Translation resume end to end.** The guard and reopen are tested at model and context level. No test drives a whole translation batch through an answer.
- **Scope of one answer.** An answer applies to its part only. A fact that would help another part must be answered there.
- **Whole-entry operations.** A plan naming no outputs has no parts, so nothing can be blocked in it and no question arises.
- **Answers while paused.** Refused until the work finishes, so a paused operation has to be resumed or budget-extended first. Simple on purpose. Say if editors should queue answers during a pause.
- **Rejected proposal.** Rejecting a proposal still does not reopen its part, and D10 stays deferred.
- API parity for the MCP registry, generated spec and SDK is a later task. Contracts are above.
