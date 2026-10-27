<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Run\ToolError;

/** A kind of entry the AI can propose changes to: content, data entries, assets. */
interface ChangeTarget
{
    /** Space ability needed to read the target. */
    public function viewAbility(): string;

    /** Space ability needed to accept changes to the target. */
    public function editAbility(): string;

    /** The target as it is now, or at an earlier version of it when the target is versioned. */
    public function find(string $id, ?string $versionId = null): ?TargetSnapshot;

    /**
     * Validate patches with the rules a normal save uses.
     *
     * @param  array<string, mixed>  $patches  path => value
     * @return array<string, string|null> path => field type
     *
     * @throws ToolError listing what is wrong, for the model to fix
     */
    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array;

    /**
     * Save the patched document through the existing write path. Versioned
     * targets get a draft; nothing is published.
     *
     * @param  array<string, mixed>  $document
     */
    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void;
}
