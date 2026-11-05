<?php

namespace App\Services\Ai\Operations\Tools;

use App\Enums\AiOperationState;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Metadata\MetadataOutputs;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Operations\OperationPlan;
use App\Services\Ai\Run\ToolError;

/** Saves the draft plan of the operation a planning run belongs to. The editor approves it. */
final class ProposePlanTool implements AiToolInterface
{
    public const NAME = 'propose_plan';

    public function __construct(private readonly OperationContext $context) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Saves the plan for this operation for the editor to approve. Nothing changes before approval. '
            .'Every plan has a `summary` the editor reads and optional `rules`. Translation plans add `locales` and `include`, the kinds of work '
            .'(missing, empty, stale, same_as_source). Asset plans add `permissions` (overwrite, remove_tags, move; leave off what the outcome does not need) '
            .'and `structure`: new `folders` (key, name, parent_id of a scope folder or parent_key of another planned folder, reason, example asset IDs) '
            .'and new `tags` (name, reason, example asset IDs). Extend the existing folders and tags before inventing new ones. '
            .'Name what the outcome asks for in `outputs` (assets: `fields`, `tags`) or `metadata` (page metadata: `keys`, optional `overwrite_keys`, `fields`, `locales`): each entry then owes exactly those, and existing values stay unless approved for overwriting. '
            .'On a translation plan `metadata` means each target locale also gets those values, written from its translated draft. Say plainly what the batches will change: after approval content is written to drafts straight away. Calling it again replaces the plan.';
    }

    public function inputSchema(): array
    {
        $examples = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to 5 asset IDs from the scope that justify it.'];

        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'What will change and why, for the editor.'],
                'rules' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Concrete rules the batches follow.'],
                'locales' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Translation: target locale codes.'],
                'include' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => OperationPlan::TRANSLATION_INCLUDE], 'description' => 'Translation: kinds of work to propose.'],
                'permissions' => [
                    'type' => 'object',
                    'properties' => array_fill_keys(OperationPlan::ASSET_PERMISSIONS, ['type' => 'boolean']),
                    'description' => 'Assets: off unless the outcome needs it.',
                ],
                'overwrite_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Assets, with overwrite: the fields it may replace.'],
                'languages' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Assets: metadata language keys to write; empty writes all.'],
                'outputs' => [
                    'type' => 'object',
                    'description' => 'Assets: what each asset owes.',
                    'properties' => [
                        'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Metadata field keys, owed per language.'],
                        'tags' => ['type' => 'boolean'],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Content or translation: generate page metadata. Empty values are filled; others stay unless named in overwrite_keys.',
                    'properties' => [
                        'keys' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MetadataOutputs::KEYS]],
                        'overwrite_keys' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MetadataOutputs::KEYS]],
                        'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Meta field keys, a nested one as <block slug>.<key>; all by default.'],
                        'locales' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Entry languages, or target locales of a translation; all by default.'],
                    ],
                    'required' => ['keys'],
                ],
                'structure' => [
                    'type' => 'object',
                    'properties' => [
                        'folders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'parent_id' => ['type' => 'string'],
                            'parent_key' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'examples' => $examples,
                        ], 'required' => ['key', 'name', 'reason', 'examples']]],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'examples' => $examples,
                        ], 'required' => ['name', 'reason', 'examples']]],
                    ],
                ],
            ],
            'required' => ['summary'],
        ];
    }

    public function execute(array $input): mixed
    {
        $operation = $this->context->operation->refresh();
        if ($operation->state !== AiOperationState::Draft) {
            throw new ToolError('The plan is already approved and cannot change.');
        }

        ['plan' => $plan, 'errors' => $errors] = OperationPlan::validate($operation, $input);
        if ($errors !== []) {
            throw new ToolError("The plan is invalid:\n- ".implode("\n- ", array_map(fn ($key, $message) => "{$key}: {$message}", array_keys($errors), $errors)));
        }

        $operation->forceFill(['plan' => $plan, 'plan_run_id' => $this->context->run->id])->save();

        return ['saved' => true, 'plan_hash' => OperationPlan::hash($plan)];
    }
}
