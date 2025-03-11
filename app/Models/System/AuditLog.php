<?php

namespace App\Models\System;

use App\Models\Management\GlobalModel;
use App\Models\User;
use Hash;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * App\Models\System\AuditLog
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $action
 * @property string $entity_type
 * @property string $entity_id
 * @property array<array-key, mixed>|null $old_values
 * @property array<array-key, mixed>|null $new_values
 * @property array<array-key, mixed>|null $metadata
 * @property string $hash
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Model|\Eloquent $entity
 * @property-read User|null $user
 * @method static \Database\Factories\System\AuditLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereNewValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereOldValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereUserId($value)
 * @mixin \Eloquent
 */
class AuditLog extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime'
    ];

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'metadata'
    ];

    /**
     * Attributes that should be excluded from the hash calculation
     *
     * @var array<string>
     */
    protected $excludeFromHash = [
        'hash',
        'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function (AuditLog $model) {
            $model->created_at = $model->freshTimestamp();

            // Ensure we have an ID before calculating the hash
            if (!$model->id) {
                $model->id = $model->newUniqueId();
            }

            $model->hash = $model->calculateHash();
        });
    }

    /**
     * Calculate hash for the model's attributes
     *
     * @return string
     */
    protected function calculateHash(): string
    {
        return Hash::make($this->getHashAttributes());
    }

    protected function getHashAttributes(): string
    {
        return sha1(json_encode(collect($this->attributesToArray())
            ->except($this->excludeFromHash)
            ->sortKeys()
            ->toArray()));
    }

    /**
     * Verify the integrity of the audit log entry
     *
     * @return bool
     */
    public function verifyIntegrity(): bool
    {
        if (empty($this->hash)) {
            return false;
        }

        return Hash::check($this->getHashAttributes(), $this->hash);
    }

    /**
     * Assert the integrity of the audit log entry
     *
     * @throws \RuntimeException
     */
    public function assertIntegrity(): void
    {
        if (!$this->verifyIntegrity()) {
            throw new \RuntimeException(
                sprintf('Audit log integrity check failed for ID: %s', $this->id)
            );
        }
    }

    /**
     * Check if the audit log has integrity issues
     *
     * @return bool
     */
    public function hasIntegrityIssues(): bool
    {
        return !$this->verifyIntegrity();
    }
}
