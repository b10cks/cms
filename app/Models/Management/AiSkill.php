<?php

namespace App\Models\Management;

use App\Enums\AiScope;
use App\Models\Traits\HasAiScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team or space skill. The editable draft holds `SKILL.md` and its supporting
 * files; runs only ever see the published revision.
 *
 * @property string $id
 * @property AiScope $scope
 * @property string|null $team_id
 * @property string|null $space_id
 * @property string $name
 * @property bool $enabled
 * @property array{files: array<string, string>} $draft
 * @property string|null $published_revision_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property-read AiSkillRevision|null $publishedRevision
 */
class AiSkill extends GlobalModel
{
    use HasAiScope;
    use HasUlids;

    protected $fillable = [
        'scope',
        'team_id',
        'space_id',
        'name',
        'enabled',
        'draft',
        'published_revision_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'draft' => 'array',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(AiSkillRevision::class, 'skill_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(AiSkillRevision::class, 'published_revision_id');
    }
}
