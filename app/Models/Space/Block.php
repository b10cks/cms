<?php

namespace App\Models\Space;

use App\Casts\Content\SchemaCast;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property string $type
 * @property string|null $description
 * @property string|null $preview_template
 * @property $schema
 * @property array<array-key, mixed>|null $editor
 * @property array<array-key, mixed>|null $tags
 * @property string|null $folder_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Space\BlockFolder|null $folder
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereEditor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereFolderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block wherePreviewTemplate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereSchema($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereTags($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Block withoutTrashed()
 * @mixin \Eloquent
 */
class Block extends SpaceModel
{
    use Filterable;
    use HasUlids;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'blocks';

    protected $fillable = [
        'slug',
        'name',
        'icon',
        'color',
        'description',
        'type',
        'preview_template',
        'schema',
        'editor',
        'tags',
        'folder_id'
    ];

    protected $casts = [
        'tags' => 'array',
        'editor' => 'array',
        'schema' => SchemaCast::class,
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(BlockFolder::class, 'folder_id', 'id');
    }

}
