<?php

namespace App\Models\Management;

use App\Enums\AiKnowledgeType;
use App\Enums\AiScope;
use App\Models\Traits\HasAiScope;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A style guide or reference document. Editors change the markdown draft;
 * publishing freezes it into a revision (chunked for references). Deleting one
 * only hides it: the revisions stay for the policy snapshots that reference them.
 *
 * @property string $id
 * @property AiScope $scope
 * @property string|null $team_id
 * @property string|null $space_id
 * @property string $slug
 * @property string $title
 * @property AiKnowledgeType $type
 * @property string|null $locale language it applies to; null applies to every language
 * @property bool $enabled
 * @property string $draft
 * @property string|null $published_revision_id
 * @property string|null $source_path
 * @property string|null $source_name
 * @property string|null $extraction_status
 * @property-read AiKnowledgeRevision|null $publishedRevision
 */
class AiKnowledgeDocument extends GlobalModel
{
    use HasAiScope;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'scope',
        'team_id',
        'space_id',
        'slug',
        'title',
        'type',
        'locale',
        'enabled',
        'draft',
        'published_revision_id',
        'source_path',
        'source_name',
        'extraction_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => AiKnowledgeType::class,
        'enabled' => 'boolean',
    ];

    public function setLocaleAttribute(?string $locale): void
    {
        $this->attributes['locale'] = $locale !== null && $locale !== '' ? GlossaryMatcher::normalizeLocale($locale) : null;
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiKnowledgeRevision::class, 'document_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeRevision::class, 'published_revision_id');
    }
}
