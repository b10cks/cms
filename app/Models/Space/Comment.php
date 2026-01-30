<?php

namespace App\Models\Space;

use App\Database\HasManyFromArray;
use App\Database\HasManyFromArrayTrait;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use App\Services\MentionExtractor;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string|null $external_id
 * @property string $content_id
 * @property string|null $content_version_id
 * @property string|null $parent_id
 * @property string $author_id
 * @property string $body
 * @property bool $is_resolved
 * @property string|null $item_id
 * @property string|null $field
 * @property array<array-key, mixed>|null $position
 * @property array<array-key, mixed>|null $mentions
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Space\Content $content
 * @property-read \App\Models\Space\ContentVersion|null $version
 * @property-read \App\Models\User $author
 * @property-read \App\Models\Space\Comment|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\Comment> $replies
 * @property-read int|null $replies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\CommentReaction> $reactions
 * @property-read int|null $reactions_count
 * @mixin \Eloquent
 */
class Comment extends SpaceModel
{
    use HasManyFromArrayTrait;
    use HasFactory;
    use Filterable;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'comments';

    protected $fillable = [
        'external_id',
        'content_id',
        'content_version_id',
        'parent_id',
        'author_id',
        'body',
        'item_id',
        'field',
        'position',
        'resolved_at',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
        'position' => 'array',
        'mentions_ids' => 'array',
        'resolved_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $comment) {
            $comment->mentions_ids = MentionExtractor::extractMentions($comment->body);
        });
    }

    protected function body(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id', 'id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'content_version_id', 'id');
    }

    public function mentions(): HasManyFromArray
    {
        return $this->hasManyFromArray(User::class, 'mentions_ids');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id', 'id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id', 'id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class, 'comment_id', 'id');
    }
}
