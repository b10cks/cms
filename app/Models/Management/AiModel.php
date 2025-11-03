<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $model
 * @property array<array-key, mixed>|null $tags
 * @property numeric $token_multiplier
 * @property bool $is_free
 * @property bool $is_active
 * @property string|null $description
 * @property string|null $provider
 * @property array<array-key, mixed>|null $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereIsFree($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereModel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereTags($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereTokenMultiplier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiModel withoutTrashed()
 * @mixin \Eloquent
 */
class AiModel extends Model
{
    use HasFactory;
    use Filterable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'ai_models';

    protected $fillable = [
        'name',
        'model',
        'tags',
        'token_multiplier',
        'is_free',
        'is_active',
        'description',
        'provider',
        'settings',
    ];

    protected $casts = [
        'tags' => 'array',
        'token_multiplier' => 'decimal:3',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function calculateCost(int $tokenCount, float $baseRate = 0.01): float
    {
        return $tokenCount * $this->token_multiplier * $baseRate;
    }
}
