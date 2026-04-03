<?php

namespace App\Models\Space;

use App\Models\Traits\SpaceAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $comment_id
 * @property string $author_id
 * @property string $emoji
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Space\Comment $comment
 * @property-read \App\Models\User $author
 * @mixin \Eloquent
 */
class CommentReaction extends SpaceModel
{
    use HasFactory;
    use HasUlids;
    use SpaceAuditable;

    public $timestamps = false;

    protected $table = 'comment_reactions';

    protected $fillable = [
        'comment_id',
        'author_id',
        'emoji',
    ];
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'comment_id', 'id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }
}
