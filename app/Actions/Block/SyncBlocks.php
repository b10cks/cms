<?php

namespace App\Actions\Block;

use App\Models\Space\Block;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncBlocks
{
    public function __construct(
        protected CreateBlockVersion $createVersion,
    ) {}

    /**
     * Reconcile a full set of block definitions against the space, matching by
     * external_id (falling back to slug for adoption of pre-sync blocks).
     *
     * @param array<int, array<string, mixed>> $blocks validated block payloads, external_id required
     * @return array{dry_run: bool, results: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function execute(
        array $blocks,
        bool $prune = false,
        bool $dryRun = false,
        ?string $commitMessage = null,
        ?User $user = null,
    ): array {
        $connection = new Block()->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($blocks, $prune, $dryRun, $commitMessage, $user) {
            $existing = Block::all();
            $byExternalId = $existing->whereNotNull('external_id')->keyBy('external_id');
            $bySlug = $existing->keyBy('slug');

            $this->assertNoSlugConflicts($blocks, $byExternalId, $bySlug);

            $results = [];
            $matchedIds = [];

            foreach ($blocks as $input) {
                $block = $byExternalId->get($input['external_id']) ?? $bySlug->get($input['slug']);

                if ($block === null) {
                    $created = $dryRun ? null : Block::create($input);

                    $results[] = [
                        'action' => 'created',
                        'id' => $created?->id,
                        'external_id' => $input['external_id'],
                        'slug' => $input['slug'],
                        'changed' => [],
                    ];

                    continue;
                }

                $matchedIds[$block->id] = true;

                // Probe dirtiness on a copy so a dry run never mutates the tracked model
                // and the version snapshot below still sees the previous state.
                $probe = (clone $block)->fill($input);
                $changed = array_keys($probe->getDirty());

                if ($changed !== [] && !$dryRun) {
                    $this->createVersion->execute($block, $commitMessage, $user);
                    $block->fill($input)->save();
                }

                $results[] = [
                    'action' => $changed === [] ? 'unchanged' : 'updated',
                    'id' => $block->id,
                    'external_id' => $input['external_id'],
                    'slug' => $input['slug'],
                    'changed' => $changed,
                ];
            }

            if ($prune) {
                foreach ($existing as $block) {
                    if (isset($matchedIds[$block->id])) {
                        continue;
                    }

                    if (!$dryRun) {
                        $block->delete();
                    }

                    $results[] = [
                        'action' => 'deleted',
                        'id' => $block->id,
                        'external_id' => $block->external_id,
                        'slug' => $block->slug,
                        'changed' => [],
                    ];
                }
            }

            return [
                'dry_run' => $dryRun,
                'results' => $results,
                'summary' => $this->summarize($results),
            ];
        });
    }

    /**
     * A payload slug must not collide with an existing block that is matched to a
     * different external_id — that would silently hijack another block's identity.
     */
    protected function assertNoSlugConflicts(array $blocks, $byExternalId, $bySlug): void
    {
        $errors = [];

        foreach ($blocks as $index => $input) {
            $matched = $byExternalId->get($input['external_id']);
            $slugOwner = $bySlug->get($input['slug']);

            if ($slugOwner === null || $slugOwner->is($matched)) {
                continue;
            }

            if ($matched === null && $slugOwner->external_id === null) {
                continue; // slug-based adoption of a pre-sync block
            }

            $errors["blocks.{$index}.slug"] = [
                "Slug '{$input['slug']}' is already used by another block (external_id: " . ($slugOwner->external_id ?? 'none') . ').',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, int>
     */
    protected function summarize(array $results): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0];

        foreach ($results as $result) {
            $summary[$result['action']]++;
        }

        return $summary;
    }
}
