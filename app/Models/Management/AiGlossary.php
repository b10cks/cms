<?php

namespace App\Models\Management;

use App\Enums\AiScope;
use App\Models\Traits\HasAiScope;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property string $id
 * @property AiScope $scope
 * @property string|null $team_id
 * @property string|null $space_id
 * @property string $name
 * @property bool $enabled
 * @property int $write_version goes up with every committed row write, see {@see GlossaryConcepts}
 */
class AiGlossary extends GlobalModel
{
    use HasAiScope;
    use HasUlids;

    protected $fillable = ['scope', 'team_id', 'space_id', 'name', 'enabled', 'created_by'];

    protected $casts = ['enabled' => 'boolean'];

    public function terms(): HasManyThrough
    {
        return $this->hasManyThrough(AiGlossaryTerm::class, AiGlossaryConcept::class, 'glossary_id', 'concept_id');
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(AiGlossaryConcept::class, 'glossary_id');
    }
}
