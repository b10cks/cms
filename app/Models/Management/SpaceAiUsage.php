<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $space_id
 * @property int $max_tokens
 * @property int $used_tokens
 * @property \Illuminate\Support\Carbon $valid_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read int $remaining_tokens
 * @property-read \App\Models\Management\Space|null $space
 * @method static Builder<static>|SpaceAiUsage active()
 * @method static Builder<static>|SpaceAiUsage forSpace(string $spaceId)
 * @method static Builder<static>|SpaceAiUsage newModelQuery()
 * @method static Builder<static>|SpaceAiUsage newQuery()
 * @method static Builder<static>|SpaceAiUsage query()
 * @method static Builder<static>|SpaceAiUsage whereCreatedAt($value)
 * @method static Builder<static>|SpaceAiUsage whereId($value)
 * @method static Builder<static>|SpaceAiUsage whereMaxTokens($value)
 * @method static Builder<static>|SpaceAiUsage whereSpaceId($value)
 * @method static Builder<static>|SpaceAiUsage whereUpdatedAt($value)
 * @method static Builder<static>|SpaceAiUsage whereUsedTokens($value)
 * @method static Builder<static>|SpaceAiUsage whereValidTo($value)
 * @mixin \Eloquent
 */
class SpaceAiUsage extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'space_ai_usage';

    protected $fillable = [
        'space_id',
        'max_tokens',
        'used_tokens',
        'valid_to',
    ];

    protected $casts = [
        'max_tokens' => 'integer',
        'used_tokens' => 'integer',
        'valid_to' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('valid_to', '>', now());
    }

    public function scopeForSpace(Builder $query, string $spaceId): Builder
    {
        return $query->where('space_id', $spaceId);
    }

    public function getRemainingTokensAttribute(): int
    {
        return max(0, $this->max_tokens - $this->used_tokens);
    }

    public function isActive(): bool
    {
        return $this->valid_to > now();
    }

    public function isExpired(): bool
    {
        return $this->valid_to <= now();
    }

    public function hasRemainingTokens(): bool
    {
        return $this->remaining_tokens > 0;
    }

    public function canUse(int $tokens): bool
    {
        return $this->isActive() && $this->remaining_tokens >= $tokens;
    }

    public function useTokens(int $tokens): bool
    {
        if (!$this->canUse($tokens)) {
            return false;
        }

        $this->increment('used_tokens', $tokens);

        return true;
    }

    public static function getCurrentUsage(string $spaceId): ?self
    {
        return self::forSpace($spaceId)
            ->active()
            ->orderBy('valid_to', 'desc')
            ->first();
    }

    public static function setQuota(string $spaceId, int $maxTokens, \Carbon\Carbon $validTo): self
    {
        return self::updateOrCreate(
            [
                'space_id' => $spaceId,
                'valid_to' => $validTo,
            ],
            [
                'max_tokens' => $maxTokens,
            ]
        );
    }
}
