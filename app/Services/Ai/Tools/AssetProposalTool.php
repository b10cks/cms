<?php

namespace App\Services\Ai\Tools;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\AssetFolder;
use App\Models\User;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Assets\AssetPlan;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Assets\AssetStructure;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;

/**
 * Base of the asset write tools: the run's approved {@see AssetPlan} and the
 * resolution of folder and tag references. A reference is an existing ID or
 * `new:<proposal ID>` of a folder or tag proposal that is not rejected and
 * either belongs to this run or was approved with the plan. Nothing here
 * writes to the library; proposals are created on accept.
 */
abstract class AssetProposalTool extends ProposalTool
{
    /** How deep proposed folders may nest in each other. */
    private const MAX_NEW_DEPTH = 10;

    public function __construct(
        AiRun $run,
        Space $space,
        User $actor,
        ChangeTargets $targets,
        ChangesetRecorder $recorder,
        AuthorizationService $auth,
        protected readonly AssetFieldRules $rules,
        protected readonly AssetStructure $structure,
    ) {
        parent::__construct($run, $space, $actor, $targets, $recorder, $auth);
    }

    protected function plan(): AssetPlan
    {
        return AssetPlan::fromSetup($this->run->setup ?? []);
    }

    /**
     * A folder reference: `root`/null, an existing folder ID or `new:<id>`.
     * `rules` is the folder whose field rules apply: a proposed folder has
     * none of its own and inherits those of its nearest existing ancestor.
     *
     * @return array{ref: string|null, rules: AssetFolder|string|null, label: string, depends_on: string|null}
     *
     * @throws ToolError
     */
    protected function folderRef(mixed $ref, int $depth = 0): array
    {
        if ($ref === null || $ref === '' || $ref === 'root') {
            return ['ref' => null, 'rules' => null, 'label' => '(library root)', 'depends_on' => null];
        }

        if (! \is_string($ref)) {
            throw new ToolError('A folder is "root", a folder ID or new:<proposal ID>.');
        }

        $itemId = AssetReview::refItemId($ref);
        if ($itemId === null) {
            $folder = $this->structure->folder($ref) ?? throw new ToolError("No asset folder with ID \"{$ref}\". Find folders with get_asset_library.");

            return ['ref' => $ref, 'rules' => $folder, 'label' => $this->structure->path($ref), 'depends_on' => null];
        }

        if ($depth >= self::MAX_NEW_DEPTH) {
            throw new ToolError('Proposed folders nest too deep.');
        }

        $proposal = $this->proposal($itemId, AiChangesetItem::TARGET_ASSET_FOLDER);
        $parent = $this->folderRef($proposal->after['parent'] ?? null, $depth + 1);

        return [
            'ref' => $ref,
            'rules' => $parent['rules'],
            'label' => ($parent['ref'] === null ? '' : "{$parent['label']} / ").($proposal->after['name'] ?? ''),
            'depends_on' => $itemId,
        ];
    }

    /**
     * A tag reference: an existing tag ID or `new:<id>`.
     *
     * @return array{ref: string, label: string, depends_on: string|null}
     *
     * @throws ToolError
     */
    protected function tagRef(mixed $ref): array
    {
        $itemId = AssetReview::refItemId($ref);

        if ($itemId !== null) {
            return ['ref' => (string) $ref, 'label' => (string) ($this->proposal($itemId, AiChangesetItem::TARGET_ASSET_TAG)->after['name'] ?? ''), 'depends_on' => $itemId];
        }

        $tag = \is_string($ref) ? $this->structure->tag($ref) : null;
        if (! $tag) {
            throw new ToolError('No tag with ID "'.(\is_string($ref) ? $ref : '').'". Use tag IDs from get_asset_library, or propose a new tag with propose_asset_structure and use its new:<ID>.');
        }

        return ['ref' => $tag->id, 'label' => (string) $tag->name, 'depends_on' => null];
    }

    /** @throws ToolError when the proposal is unknown, rejected or outside this run and the plan */
    private function proposal(string $itemId, string $type): AiChangesetItem
    {
        $item = AiChangesetItem::query()->find($itemId);

        $usable = $item
            && $item->target_type === $type
            && $item->operation === AiChangesetItem::OPERATION_CREATE
            && $item->status !== AiChangesetItemStatus::Rejected
            && (\in_array($itemId, $this->plan()->approvedStructure, true) || AiChangeset::query()->ofRun($this->run)->whereKey($item->changeset_id)->exists());

        if (! $usable) {
            throw new ToolError("new:{$itemId} is no usable ".($type === AiChangesetItem::TARGET_ASSET_TAG ? 'tag' : 'folder').' proposal. Use proposals of this run or of the approved plan that were not rejected.');
        }

        return $item;
    }
}
