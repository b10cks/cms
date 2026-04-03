<?php

namespace App\Models\Space;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $referenced_type
 * @property string $referenced_id
 * @property string $name
 * @property string|null $owner_id
 * @property string $owner_type
 * @property string|null $owner_name
 * @property string $operation
 * @property array|null $meta
 * @property Carbon|null $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog query()
 *
 * @mixin \Eloquent
 */
class AuditLog extends SpaceModel
{
    use Filterable;
    use HasUlids;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'referenced_type',
        'referenced_id',
        'name',
        'owner_id',
        'owner_type',
        'owner_name',
        'operation',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booting(): void
    {
        parent::booting();

        static::creating(function (self $model) {
            $model->created_at ??= now();
        });
    }
}
