<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class SpaceAiKey extends GlobalModel
{
    use HasUlids;

    protected $table = 'space_ai_keys';

    protected $fillable = [
        'space_id',
        'driver',
        'key_hash',
        'encrypted_key',
        'name',
        'external_key_hash',
        'limit',
        'limit_reset',
        'final_usage_usd',
        'usage_captured_at',
        'expires_at',
        'disabled_at',
    ];

    protected $casts = [
        'limit' => 'decimal:2',
        'final_usage_usd' => 'decimal:6',
        'usage_captured_at' => 'datetime',
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    protected $hidden = [
        'encrypted_key',
        'key_hash',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function getDecryptedKey(): string
    {
        return Crypt::decryptString($this->encrypted_key);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('disabled_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForDriver(Builder $query, string $driver): Builder
    {
        return $query->where('driver', $driver);
    }

    public function scopeForSpace(Builder $query, string $spaceId): Builder
    {
        return $query->where('space_id', $spaceId);
    }
}
