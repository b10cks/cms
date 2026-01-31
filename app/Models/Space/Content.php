<?php

namespace App\Models\Space;

use App\Casts\Slug;
use App\Database\HasManyFromArray;
use App\Database\HasManyFromArrayTrait;
use App\Events\Space\ContentDeleted;
use App\Events\Space\ContentUpdated;
use App\Jobs\Content\UpdateContentFullSlugsJob;
use App\Models\Traits\HasPurifiedAttributes;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\CustomStr;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $block_id
 * @property string|null $parent_id
 * @property string|null $name
 * @property string $slug
 * @property string $full_slug
 * @property string $language_iso
 * @property string|null $i18n_parent_id
 * @property string|null $content
 * @property \App\Models\Space\ContentSettings|null $settings
 * @property string $current_version_id
 * @property string|null $published_version_id
 * @property string|null $searchable_content
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $first_published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Space\Block|null $block
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Content> $children
 * @property-read int|null $children_count
 * @property-read \App\Models\Space\ContentVersion|null $current_version
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Content> $i18n_children
 * @property-read int|null $i18n_children_count
 * @property-read Content|null $i18n_parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Content> $i18n_siblings
 * @property-read int|null $i18n_siblings_count
 * @property-read Content|null $parent
 * @property-read \App\Models\Space\ContentVersion|null $published_version
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\ContentVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereCurrentVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereFirstPublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereFullSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereI18nParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereLanguageIso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content wherePublishedVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content withoutTrashed()
 * @mixin \Eloquent
 */
class Content extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasManyFromArrayTrait;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'contents';

    protected $fillable = [
        'external_id',
        'name',
        'slug',
        'language_iso',
        'description',
        'block_id',
        'parent_id',
        'i18n_parent_id',
        'settings',
        'published_at',
        'searchable_content',
    ];

    protected $casts = [
        'settings' => ContentSettings::class,
        'slug' => Slug::class,
        'published_at' => 'datetime',
        'first_published_at' => 'datetime',
    ];

    protected const array REDUCED_FIELDSET = ['id', 'name', 'slug', 'full_slug', 'language_iso', 'published_at', 'first_published_at', 'created_at', 'updated_at', 'i18n_parent_id'];

    protected static function boot()
    {
        parent::boot();
        static::saving(function (Content $content) {
            $slugService = app(LocalizedContentSlugService::class);
            $oldFullSlug = $slugService->updateFullSlug($content);
            if (!empty($oldFullSlug)) {
                $slugService->createRedirect($oldFullSlug, $content->full_slug);
            }
        });

        static::saved(function (Content $content) {
            if ($content->isDirty('slug') || $content->isDirty('parent_id')) {
                UpdateContentFullSlugsJob::dispatch($content, request('space') ?? app()->get('currentSpace'));
            }

            event(new ContentUpdated($content, request('space') ?? app('currentSpace')));
        });

        static::softDeleted(function (Content $content) {
            event(new ContentDeleted($content, request('space') ?? app('currentSpace')));
        });
    }

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    public function setSlugAttribute($value)
    {

        $this->attributes['slug'] = CustomStr::slug($value, '-');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Content::class, 'parent_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'parent_id', 'id');
    }

    public function i18n_parent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'i18n_parent_id', 'id')
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->select('contents.*', 'content_versions.content');
    }

    public function i18n_children(): HasMany
    {
        return $this->hasMany(Content::class, 'i18n_parent_id', 'id')
            ->select(self::REDUCED_FIELDSET);
    }

    public function i18n_siblings(): HasMany
    {
        return $this->hasMany(Content::class, 'i18n_parent_id', 'i18n_parent_id')
            ->where('i18n_parent_id', '!=', null)
            ->select(self::REDUCED_FIELDSET);
    }

    public function getContent(): array
    {
        if ($this->i18n_parent_id) {
            $this->loadMissing('i18n_parent');
            $result = $this->i18n_parent?->getContent() ?? [];
        } else {
            $result = [];
        }

        return array_replace_recursive($result, $this->published_version->content ?? []);
    }

    public function setPublishedAt($date): void
    {
        $this->published_at = $date;
        if (is_null($this->first_published_at)) {
            $this->first_published_at = $date;
        }
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class, 'content_id', 'id');
    }

    public function current_version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'current_version_id', 'id');
    }

    public function published_version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'published_version_id', 'id');
    }

    public function assets(): HasManyFromArray
    {
        return $this->hasManyFromArray(Asset::class, 'asset_ids');
    }

    public function links(): HasManyFromArray
    {
        return $this->hasManyFromArray(Content::class, 'link_ids');
    }

    public function relations(): HasManyFromArray
    {
        return $this->hasManyFromArray(Content::class, 'relation_ids');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'content_id', 'id');
    }
}
