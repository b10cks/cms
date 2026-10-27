<?php

namespace App\Models\Traits;

use App\Enums\AiScope;
use App\Models\Management\Space;
use App\Models\Management\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared owner columns of skills, knowledge documents and glossaries: a row
 * belongs to one space or one team.
 */
trait HasAiScope
{
    public function initializeHasAiScope(): void
    {
        $this->mergeCasts(['scope' => AiScope::class]);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Rows owned by the space or any of the given teams.
     *
     * @param  list<string>  $teamIds
     */
    public function scopeInLayers(Builder $query, ?string $spaceId, array $teamIds): Builder
    {
        return $query->where(function (Builder $query) use ($spaceId, $teamIds) {
            if ($spaceId !== null) {
                $query->orWhere(fn (Builder $q) => $q->where('scope', AiScope::Space->value)->where('space_id', $spaceId));
            }

            if ($teamIds !== []) {
                $query->orWhere(fn (Builder $q) => $q->where('scope', AiScope::Team->value)->whereIn('team_id', $teamIds));
            }

            if ($spaceId === null && $teamIds === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    public function scopeOwnedBy(Builder $query, Space|Team $owner): Builder
    {
        return $owner instanceof Space
            ? $query->where('scope', AiScope::Space->value)->where('space_id', $owner->id)
            : $query->where('scope', AiScope::Team->value)->where('team_id', $owner->id);
    }

    /**
     * @return array{scope: AiScope, space_id: string|null, team_id: string|null}
     */
    public static function ownerAttributes(Space|Team $owner): array
    {
        return $owner instanceof Space
            ? ['scope' => AiScope::Space, 'space_id' => $owner->id, 'team_id' => null]
            : ['scope' => AiScope::Team, 'space_id' => null, 'team_id' => $owner->id];
    }
}
