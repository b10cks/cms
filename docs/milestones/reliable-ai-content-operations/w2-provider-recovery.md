# W2 provider recovery evidence

Branch `t3/provider-retry-recovery`, built on `6c351e9f`. One slice of the brief. It does not complete it.

## Gap reproduced before the fix

On `6c351e9f`, a scratch test sent an approved one-entry operation an OpenAI `ServerException` with HTTP 503. Result: one provider call, run `failed`, operation `paused` with `batch_failed`, `error` `provider_error: <ref>`, and the entry's `attempts` at 1. `ai.operations.max_attempts` is 2, so two outages would have marked entries failed. The scratch test was deleted.

## Behavior now

**Classification.** `ProviderFailure::classify()` reads exception classes, HTTP status, provider error codes and `Retry-After`. It never reads message text.

| Kind | Source | Retried | Item attempt | May bill unseen |
| --- | --- | --- | --- | --- |
| `rate_limited` | 429, `rate_limit_*`, `ThrottlingException` | yes | no | no |
| `unavailable` | 408, 425, 5xx except 501 and 505, `server_error`, `overloaded_error`, Bedrock outage codes | yes | no | no |
| `connection` | cURL could not resolve, connect or finish TLS | yes | no | no |
| `interrupted` | any of the above after the stream opened, read failures in an open stream, a timeout that may have reached the provider, a stream with no finish marker | yes, tighter bound | no | yes |
| `auth` | 401, 403 | no | no | no |
| `quota` | 402, `insufficient_quota` (a 429 at OpenAI), `ServiceQuotaExceededException` | no | no | no |
| `rejected` | other 4xx, `ValidationException` | no | yes | no |
| `unknown` | everything else, including a bare `ProviderCallFailed` | no | yes, as before | no |

"May bill unseen" records what the provider contract lets us conclude. It is not a billing guarantee. A 5xx is treated as unbilled, and no provider documents that for every case.

Installed sources checked: `openai-php/client` 0.21.0, `guzzlehttp/guzzle` 7.16.1, `aws/aws-sdk-php` 3.402.2. Streamed OpenAI requests use Guzzle `send()`, so a 5xx arrives as `TransporterException` wrapping Guzzle's `ServerException`. A generic check on `OpenAI\Exceptions\ServerException` alone would have missed every streamed 5xx. The tests send these through the real client.

**Truncated streams.** A stream that ends with no `finish_reason`, with OpenRouter's `error` finish reason, or with no Bedrock `message_stop` is now `interrupted`. Before, it became a short answer with possibly broken tool arguments.

**Retry.** Background runs only. An inline run fails at once as `provider_error`, because its caller is waiting and `runToCompletion` callers read the result straight away.

State lives in `ai_runs.setup.provider_retry`, so no migration is needed:

| Key | Meaning |
| --- | --- |
| `attempts` | retries since the last answered call |
| `not_before` | no provider call before this time |
| `total` | retries over the run |
| `waited_seconds` | backoff, excluded from `max_seconds` |
| `unconfirmed_calls` | interrupted calls, never reset |
| `failure` | kind, status and code being retried, no provider message |

Defaults, all configurable under `ai.runs.provider_retries`:

| Key | Env | Default |
| --- | --- | --- |
| `max_attempts` | `AI_RUN_PROVIDER_RETRY_ATTEMPTS` | 3 |
| `backoff_seconds` | `AI_RUN_PROVIDER_RETRY_BACKOFF_SECONDS` | 60 (60s, 120s, 240s) |
| `max_backoff_seconds` | `AI_RUN_PROVIDER_RETRY_MAX_BACKOFF_SECONDS` | 300 |
| `max_unconfirmed_calls` | `AI_RUN_PROVIDER_RETRY_MAX_UNCONFIRMED_CALLS` | 2 |

`Retry-After` can lengthen a wait up to the maximum. A longer `Retry-After` is cut to the maximum, so the retry may fail again and use an attempt.

**Sequence on a transient failure.** The worker checks its lease, records the reported usage and generation ID, stores the retry state, emits `provider_retry_scheduled`, releases the lease and dispatches a job delayed to `not_before`. Workers never sleep.

- **Early arrivals.** `advance()` returns without a provider call while `not_before` is ahead, after the cancel and access guard. A duplicate job, a recovery job and a resume all stop there.
- **Lost job.** `RunRecovery` picks the run up once it is past the recovery grace (300s) and past `not_before`, dispatches once and touches the row. A lost retry can therefore wait up to the grace plus the two-minute sweep interval.
- **Concurrent jobs.** The existing lease excludes them.
- **Pause and cancel.** A pause during a backoff cancels the run when the next job or sweep arrives, with no provider call and no attempt, and the operation pauses with reason `user`. Cancel is immediate. Revoked access is caught by the guard. A budget reached through reported usage stops at the guard with reason `budget`.

**Transcript.** A failed call adds nothing to it and the retry path does not checkpoint it, so the same request goes out again. There are no synthetic assistant messages. `InspectionImages::delivered()` counts an image only once a later assistant message exists, so a failed call claims no delivery. A successful response is appended and checkpointed before tools, as before. Completed tool results and proposals stay.

**Exhaustion.** The run fails with `provider_unavailable: <ref>`. The operation pauses with `batch_failed`, and its `error` carries that value. Entries return to pending with no attempt charged. Proposals stay. Nothing advances until an editor resumes, which starts a new batch with fresh consecutive counters.

A run with more than 2 interrupted calls also stops here, even with retry attempts left. An answered call resets `attempts` but never `unconfirmed_calls`.

## Spend: what is and is not promised

- A failed call's reported tokens and generation ID go through `recordUsage`, so caps see them and `ReconcileAiRunCostJob` looks those generations up.
- OpenAI-compatible streams report usage only at the end, so an interrupted one usually adds zero tokens. Bedrock reports input tokens at the start and output tokens only near the end.
- **Spend can therefore undercount.** Each `unconfirmed_call` may have cost money that `cost_usd` and `spent_usd` do not show. Default exposure is at most 3 such calls per run: two retried and the third that stops it. Budget caps act only on recorded spend, the same limit a killed worker already has.
- `operation.counts.provider_retries` and `operation.counts.unconfirmed_provider_calls` show this on the existing resource after each batch settles. The keys appear only when above zero. Planning runs are not included.
- `cost_confirmed_at` covers only known generations. An interrupted call that failed before its first chunk has no ID, so a run can read as confirmed while still having an unconfirmed call. Receipts must read `unconfirmed_provider_calls` next to it.
- If OpenRouter returns no cost for an interrupted generation, the reconcile job exhausts its tries and the estimate stays unconfirmed. Not exercised against OpenRouter.

## API and behavior changes

- New run event `provider_retry_scheduled` with `attempt`, `max_attempts`, `retry_at`, `failure`, `possibly_billed`, `unconfirmed_calls`. It is stored for background runs. The frontend's `EVENT_TYPES` drops unknown types, so it has no effect there yet.
- New run error reason `provider_unavailable`. `provider_error` remains for everything else.
- `ProviderCallFailed` constructor is now `(message, ?ProviderFailure, ?Throwable previous)`. `new ProviderCallFailed('text')` still works and stays terminal.
- Auth and quota failures no longer charge an item attempt. They used to.
- Truncated streams now fail or retry instead of returning partial output, including for inline runs, where they fail as `provider_error`.
- `RunEventType`, `ai.runs.provider_retries` and `operation.counts` gain additive keys. `runContext` lists its count keys explicitly, so the new ones do not reach prompts.

## Deployment

No migration, so 000023 stays unused. The scheduler must run `ai:recover-runs`, as W1 already requires. `RunAgentJob` needs a queue that honors `delay`. On the sync driver the delay is ignored, the job stops at the backoff check and the sweep carries the retry.

## Checks

All from this checkout, with its own copied `vendor/`, on SQLite and fake or mocked providers.

```
vendor/bin/phpunit tests/Feature/Ai/AiProviderRecoveryTest.php    OK (40 tests)
vendor/bin/phpunit tests/Feature/Ai                               OK (196 tests, 1211 assertions)
vendor/bin/phpunit tests/Unit/Services/Ai                         OK (65 tests, 153 assertions)
vendor/bin/phpunit tests/Feature/Mgmt/AssetClassificationTest.php OK (28 tests, 135 assertions)
vendor/bin/phpunit tests/Feature/Mgmt/DataEntryTranslationStreamTest.php OK (2 tests, 5 assertions)
vendor/bin/phpunit tests/Feature/Services/SpaceDatabaseMigratorTest.php  OK (2 tests, 6 assertions)
vendor/bin/pint --test <all changed PHP>                          passed
php artisan config:show ai.runs.provider_retries                  shows the defaults above
```

`AiRunServiceTest.php` is unchanged and passes. `AiOperationTest`'s 10,000-entry test keeps its bare `ProviderCallFailed` as terminal with one attempt charged, unchanged.

Removing each key guard made the new tests fail: the backoff check in `advance()`, the sweep's waiting check, the job's pause check, the no-attempt rule and the backoff credit in `max_seconds`. The sweep check first survived this, so the test gained an assertion on pushed jobs.

## Remaining gaps and limits

- **Not exercised:** real providers, real paid calls, MySQL, real queue delays, multiple processes and real network failures. Provider behavior comes from installed client source and mocked responses.
- Which codes each provider sends in practice, especially mid-stream, is unverified. An unrecognized code stays `unknown` and pauses, which is the safe direction.
- The Bedrock client keeps the AWS SDK's default retries. For errors before the stream those run inside the worker with the SDK's own short delays, on top of this bound. No code here sleeps. See the requests below.
- Text streamed before an interruption stays in stored events, so a follower can see it repeated after the retry. W1 has the same limit for killed workers.
- `AiServiceException` from `driverFor` (for example, not provisioned) still fails at once. It is not a provider call failure.
- A run reaching `max_seconds` from work time, rather than backoff, still completes as partial and charges attempts, unless its last call failed with a sparing kind.
- Retries have no jitter. Concurrency is bounded by `max_concurrent_background` per space, so I left it out.
- Field and locale outcomes, receipts and API parity belong to later waves.

## Integration requests

1. `AiRunResource`: expose `setup.provider_retry` (`not_before`, `attempts`, `failure`, `unconfirmed_calls`) so a waiting run and its exposure show without reading events.
2. Frontend: add `provider_retry_scheduled` to `AiRunEvent` and `EVENT_TYPES`, and show the wait and the `provider_unavailable` reason. Optionally clear partial text on the event.
3. Receipts and spec: document `counts.provider_retries` and `counts.unconfirmed_provider_calls`, and show them next to spend.
4. Decide whether Bedrock runs should set `retries: 0` so one bound applies. Other callers share that client, so I did not change it.
