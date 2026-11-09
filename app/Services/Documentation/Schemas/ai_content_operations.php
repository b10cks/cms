<?php

/**
 * JSON columns and service arrays of the AI content operations lifecycle, in
 * SchemaShape notation. The code writes these shapes; resources and controllers
 * reference them through `shape=` and `@apiResponse`.
 */
$outcomes = 'enum:written|proposed|conflict|satisfied|intentionally_empty|uninferable|refused|blocked|invalid|unfinished|skipped|unchanged|failed|closed';

return [
    // Operation lifecycle
    'AiOperationScope' => [
        'type' => 'enum:content|data_entry|asset',
        'ids' => 'string[]? -- Explicit entries, up to 1000.',
        'root_id' => 'string? -- Root of a recursive scope.',
        'recursive' => 'boolean',
        'filters' => [
            'block' => 'string?',
            'language' => 'string?',
            'missing_locale' => 'string?',
            'query' => 'string?',
            'data_source' => 'string?',
        ],
    ],
    'AiOperationCounts' => [
        '#' => 'Frozen scope counts, plus coverage stored when the operation completes, pauses or is cancelled.',
        'total' => 'integer',
        'supported' => 'integer',
        'unsupported' => 'integer',
        'groups' => 'map:integer',
        'tokens' => 'integer',
        'scope_hash' => 'string -- Send back as `scope_hash` when approving.',
        'coverage' => '@AiOperationCoverage?',
    ],
    'AiOperationCoverage' => [
        '#' => 'Read `complete`, not the state: a completed operation can hold exceptions. `verified: false` with `complete: null` means the plan named no outputs.',
        'verified' => 'boolean',
        'complete' => 'boolean?',
        'outputs' => 'map:integer -- Requested outputs by outcome.',
        'entries' => 'map:integer -- Entries by ledger status.',
    ],
    'AiOperationPlan' => [
        '#' => 'Validated per kind by OperationPlan. Keys of other kinds are refused. `plan_hash` is the hash of this plan.',
        'summary' => 'string',
        'rules' => 'string[]',
        'options' => 'object',
        'locales' => 'string[] -- translation: target locales.',
        'include' => 'string[] -- translation: kinds of work.',
        'permissions' => 'map:boolean -- assets: what the operation may change. Moves and overwrites need their own permission.',
        'overwrite_fields' => 'string[] -- assets: fields an overwrite may replace.',
        'languages' => 'string[] -- assets: metadata language keys.',
        'structure' => [
            '#' => 'assets: new folders and tags, reviewed before the assets that use them.',
            'folders' => [[
                'key' => 'string',
                'name' => 'string',
                'parent_id' => 'string?',
                'parent_key' => 'string?',
                'reason' => 'string',
                'examples' => 'string[]',
            ]],
            'tags' => [['name' => 'string', 'reason' => 'string', 'examples' => 'string[]']],
        ],
        'outputs' => [
            '#' => 'assets: requested outputs. With this key, coverage is per field.',
            'fields' => 'string[]',
            'tags' => 'boolean',
        ],
        'metadata' => [
            '#' => 'Requested page metadata on general or translation operations. Translation metadata is written from the translated draft; coverage includes both outputs.',
            'keys' => 'string[]',
            'overwrite_keys' => 'string[] -- Keys that may replace a stored value. Others fill empty values only.',
            'fields' => 'string[] -- Empty means all meta fields, including nested blocks. Select a nested field with block-slug.field-key.',
            'locales' => 'string[] -- Empty means all applicable locales. Translation plans stay within their approved target locales.',
        ],
    ],
    'AiOperationBudget' => [
        'max_usd' => 'number',
        'spent_usd' => 'number',
        'remaining_usd' => 'number',
    ],
    'AiOperationUsage' => ['input_tokens' => 'integer', 'output_tokens' => 'integer'],
    'AiOperationProgress' => [
        'by_status' => 'map:integer -- Every ledger status.',
        'owed' => 'integer -- Pending or running.',
        'reasons' => 'map:map:integer -- Skipped and failed entries by status, then reason code.',
        'batches' => 'integer',
    ],
    'AiOperationCurrentRun' => ['id' => 'string', 'state' => 'enum:running|awaiting_input|completed|failed|cancelled'],
    'AiOperationPartOutcome' => [
        'outcome' => $outcomes,
        'reason' => 'string?',
        'note' => 'string?',
        'question' => 'string? -- Set on a blocked part.',
        'batch' => 'integer?',
    ],
    'AiOperationItemUnits' => ['written' => 'string[]', 'remaining' => 'string[]'],
    'AiOperationConflict' => [
        'message' => 'string',
        'reason' => 'enum:state|plan_changed|scope_changed|budget|busy|questions_closed',
        'added' => 'integer -- scope_changed only.',
        'removed' => 'integer -- scope_changed only.',
    ],
    'AiConcurrencyLimit' => ['message' => 'string', 'reason' => 'enum:concurrency_limit'],
    'AiScopeTooLarge' => ['message' => 'string', 'reason' => 'enum:scope_too_large', 'count' => 'integer', 'limit' => 'integer'],
    'AiOperationArrivals' => [
        'count' => 'integer',
        'entries' => [['id' => 'string', 'name' => 'string']],
    ],

    // Grouped questions
    'AiOperationQuestion' => [
        'id' => 'string -- Stable while the wording stays the same.',
        'question' => 'string?',
        'note' => 'string?',
        'fingerprint' => 'string -- Hash of the exact members. Send back to answer the whole group.',
        'outputs' => 'integer',
        'entries' => 'integer',
    ],
    'AiOperationQuestionPage' => [
        'data' => '@AiOperationQuestion[]',
        'links' => 'object',
        'meta' => 'object',
        'summary' => [
            'accepting' => 'boolean -- Answers are taken only once the operation has completed.',
            'questions' => 'integer',
            'outputs' => 'integer',
            'entries' => 'integer',
            'answered' => 'integer',
            'resuming' => 'integer',
        ],
    ],
    'AiOperationQuestionMember' => [
        'id' => 'string -- One blocked output in one round. Asked again after an answer, it gets a new ID.',
        'item_id' => 'string',
        'target_type' => 'string',
        'target_id' => 'string',
        'label' => 'string?',
        'part' => 'string',
        'question' => 'string?',
        'note' => 'string?',
    ],
    'AiOperationAnsweredQuestion' => [
        'question' => 'string?',
        'answer' => 'string',
        'answered_by' => ['?' => true, 'id' => 'string', 'name' => 'string'],
        'answered_at' => 'date-time',
        'outputs' => 'integer',
        'entries' => 'integer',
    ],
    'AiOperationAnswerResults' => [
        'results' => [[
            'question_id' => 'string',
            'status' => 'enum:recorded|duplicate|stale -- Stale changes nothing. A duplicate keeps the first answer.',
            'recorded' => 'integer',
            'duplicate' => 'integer',
            'stale' => 'integer',
        ]],
        'operation' => '@AiOperationResource',
    ],

    // What changed
    'AiOperationChanges' => [
        'data' => '@AiOperationChangedEntry[]',
        'summary' => ['changed' => 'integer -- Entries with something in their draft.', 'conflicts' => 'integer -- Fields not written because someone changed them.'],
        'meta' => ['total' => 'integer', 'page' => 'integer', 'per_page' => 'integer'],
    ],
    'AiOperationChangedEntry' => [
        'target_id' => 'string? -- Null only for an entry not written yet.',
        'label' => 'string?',
        'written' => 'integer',
        'conflicts' => 'integer',
    ],

    // What AI left on an entry for a person
    'AiContentFlagCounts' => [
        'data' => 'object -- Entry ID => {conflicts: integer}. Entries without a conflict are left out.',
    ],
    'AiContentFlagEntry' => [
        'content_id' => 'string',
        'name' => 'string',
        'language_iso' => 'string?',
        'flags' => '@AiContentFlag[]',
    ],
    'AiContentFlag' => [
        'kind' => 'enum:conflict -- A person changed the field and it waits for a decision.',
        'path' => 'string? -- Null for a whole new entry.',
        'field' => 'string? -- The field\'s name.',
        'thread_id' => 'string? -- The thread that decides it.',
    ],

    // Review of proposals: targets without a draft layer
    'AiOperationReviewGroup' => [
        'key' => 'string -- Send back as `group`.',
        'label' => 'string?',
        'operation' => 'string',
        'target_type' => 'string',
        'path' => 'string?',
        'field_type' => 'string?',
        'counts' => 'map:integer -- By item status.',
        'bulk' => 'integer -- Open items a group accept takes.',
        'individual' => 'integer -- Open items that need an individual decision. A group accept leaves them.',
        'queued' => 'enum:accept|reject? -- A queued group decision still running.',
        'examples' => '@AiChangesetItemResource[]',
    ],
    'AiOperationReviewItems' => [
        'data' => '@AiChangesetItemResource[]',
        'meta' => ['total' => 'integer', 'page' => 'integer', 'per_page' => 'integer'],
    ],
    'AiOperationGroupAccepted' => [
        'accepted' => 'integer',
        'conflicts' => 'integer',
        'failed' => 'integer',
        'blocked' => 'integer',
        'individual' => 'integer',
        'queued' => 'integer -- Above 0 with status 202: the group went to the queue.',
    ],
    'AiOperationGroupRejected' => ['rejected' => 'integer', 'queued' => 'integer'],
    'AiReviewFlag' => [
        'code' => 'string -- For example invalid.',
        'message' => 'string?',
    ],
    'AiProposalEvidence' => [
        '#' => 'What an asset proposal rests on. `image` means the provider answered a request holding the image, not that the model understood it.',
        'basis' => 'enum:image|metadata|instruction',
        'run_id' => 'string -- image',
        'message' => 'integer -- image',
        'sha256' => 'string -- image',
        'sources' => 'string[] -- metadata',
        'quote' => 'string -- instruction',
    ],
    'AiChangesetItemReview' => [
        '?' => true,
        'evidence' => '@AiProposalEvidence?',
    ],
    'AiChangesetTranslationUnit' => [
        'unit_id' => 'string',
        'reason' => 'string?',
        'source' => 'string',
        'previous_source' => 'string?',
    ],
    'AiChangesetAccepted' => [
        'data' => '@AiChangesetResource',
        'accepted' => 'integer',
        'conflicts' => 'integer',
        'failed' => 'integer',
    ],
    'AiChangesetRejected' => ['data' => '@AiChangesetResource', 'rejected' => 'integer'],
    'AiChangesetRunSummary' => [
        '?' => true,
        'id' => 'string',
        'state' => 'string',
        'partial' => 'boolean',
        'stopped_by' => 'string?',
        'cost_usd' => 'number',
    ],
    'AiActor' => ['?' => true, 'id' => 'string', 'name' => 'string'],

    // Runs
    'AiProviderRetry' => [
        '#' => 'A provider wait. Spend for unconfirmed calls is estimated until `usage.cost_confirmed_at` is set.',
        'attempts' => 'integer',
        'total' => 'integer',
        'unconfirmed_calls' => 'integer',
        'waited_seconds' => 'integer',
        'not_before' => 'date-time? -- The run waits until this time.',
        'failure' => 'any -- The last provider failure, or null.',
    ],
    'AiRunPolicy' => [
        'id' => 'string -- Immutable policy snapshot.',
        'fingerprint' => 'string',
        'version' => 'integer',
    ],
    'AiRunOperation' => [
        'id' => 'string',
        'phase' => 'string?',
        'batch' => 'integer?',
    ],
    'AiRunUsage' => [
        'steps' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'cost_usd' => 'number',
        'cost_confirmed_at' => 'date-time? -- Null while `cost_usd` is an estimate.',
    ],
    'AiRunResult' => [
        '?' => true,
        '#' => 'Depends on the skill. Lists the keys of this lifecycle.',
        'classification' => '@AiAssetClassificationReport?',
    ],

    'TranslationUnitsPage' => [
        'data' => '@TranslationUnitResource[]',
        'links' => 'object',
        'meta' => 'object',
    ],
    'TranslationUnitContent' => [
        'id' => 'string',
        'name' => 'string',
        'full_slug' => 'string',
        'source_id' => 'string? -- The source entry this translation belongs to.',
        'trashed' => 'boolean',
    ],
    'TranslationUnitsBulkResult' => ['deleted' => 'integer'],

    // Automatic asset classification
    'AiAssetClassifyRequest' => [
        'scope' => 'enum:selection|all',
        'asset_ids' => 'string[] -- Required with scope selection, up to 2000.',
        'languages' => 'string[]',
        'config_id' => 'string?',
        'overwrite' => 'boolean',
        'alt_context' => 'string? -- One selected asset only.',
        'decorative' => 'boolean -- One selected asset only.',
        'high_detail' => 'boolean -- One selected asset only.',
    ],
    'AiAssetClassifyQueued' => [
        'run_id' => 'string',
        'queued' => 'integer',
        'skipped' => 'integer',
    ],
    'AiAssetClassificationReport' => [
        'asset_id' => 'string',
        'status' => 'enum:complete|partial|none',
        'requested' => 'string[]',
        'written' => 'string[]',
        'kept' => 'string[]',
        'intentionally_empty' => 'string[]',
        'uninferable' => 'string[]',
        'rejected' => [['key' => 'string', 'reason' => 'string']],
        'missing' => 'string[]',
        'languages' => 'map:object -- By language: {status, open}.',
        'tags' => [
            'requested' => 'boolean',
            'written' => 'string[]',
            'unknown' => 'string[]',
            'malformed' => 'boolean',
            'taxonomy' => 'object',
        ],
    ],
    'AiAssetFieldReport' => [
        '?' => true,
        '#' => 'Null until the run is complete.',
        'complete' => 'integer',
        'partial' => 'integer',
        'none' => 'integer',
        'assets' => '@AiAssetClassificationReport[] -- Assets with open fields, up to 100.',
        'truncated' => 'boolean',
    ],
    'AiAssetClassificationProgress' => [
        'run_id' => 'string',
        'queued' => 'integer',
        'pending' => 'integer',
        'updated' => 'integer -- Includes partial output.',
        'skipped' => 'integer',
        'failed' => 'integer',
        'complete' => 'boolean',
        'field_report' => '@AiAssetFieldReport',
    ],
];
