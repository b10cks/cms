<?php

namespace App\Models\Management;

use App\Enums\AiScope;
use App\Models\Traits\HasAiScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property AiScope $scope
 * @property string|null $team_id
 * @property string|null $space_id
 * @property string $name
 * @property bool $enabled
 */
class AiGlossary extends GlobalModel
{
    use HasAiScope;
    use HasUlids;

    protected $fillable = ['scope', 'team_id', 'space_id', 'name', 'enabled', 'created_by'];

    protected $casts = ['enabled' => 'boolean'];

    public function terms(): HasMany
    {
        return $this->hasMany(AiGlossaryTerm::class, 'glossary_id');
    }
}
