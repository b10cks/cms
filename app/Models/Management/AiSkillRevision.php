<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable published skill manifest.
 *
 * @property string $id
 * @property string $skill_id
 * @property int $revision
 * @property array{files: array<string, string>} $manifest
 * @property string $hash
 * @property string|null $published_by
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AiSkillRevision extends GlobalModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['skill_id', 'revision', 'manifest', 'hash', 'published_by'];

    protected $casts = [
        'revision' => 'integer',
        'manifest' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(AiSkill::class, 'skill_id');
    }
}
