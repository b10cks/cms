<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
