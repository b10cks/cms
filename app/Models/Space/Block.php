<?php

namespace App\Models\Space;

use App\Casts\Content\SchemaCast;
use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use App\Services\Content\ContentMenuCache;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $slug
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property string $type
 * @property string|null $description
 * @property string|null $preview_template
 * @property string|null $preview_file
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
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    protected string $spaceChannel = 'blocks';

    protected $table = 'blocks';

    protected $fillable = [
        'external_id',
        'slug',
        'name',
        'icon',
        'color',
        'description',
        'type',
        'preview_template',
        'preview_file',
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

    protected static function boot()
    {
        parent::boot();

        static::saved(fn () => self::scheduleContentMenuInvalidation());
        static::deleted(fn () => self::scheduleContentMenuInvalidation());
        static::restored(fn () => self::scheduleContentMenuInvalidation());
    }

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    protected function previewTemplate(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(BlockFolder::class, 'folder_id', 'id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(BlockTemplate::class, 'block_id', 'id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BlockVersion::class, 'block_id', 'id');
    }

    protected static function scheduleContentMenuInvalidation(): void
    {
        $spaceId = request('space')?->id
            ?? (app()->bound('currentSpace') ? app('currentSpace')->id : null);

        if (! $spaceId) {
            return;
        }

        DB::afterCommit(static function () use ($spaceId): void {
            app(ContentMenuCache::class)->invalidate($spaceId);
        });
    }
}
