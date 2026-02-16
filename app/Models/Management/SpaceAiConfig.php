<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceAiConfig extends GlobalModel
{
    use HasUlids;

    protected $table = 'space_ai_configs';

    protected $fillable = [
        'name',
        'driver',
        'model',
        'system_prompt',
        'temperature',
        'max_tokens',
        'is_default',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'max_tokens' => 'integer',
        'is_default' => 'boolean',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function scopeForSpace($query, string $spaceId)
    {
        return $query->where('space_id', $spaceId);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
