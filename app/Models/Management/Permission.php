<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * App\Models\Management\Permission
 *
 * @property string $id
 * @property string $resource_type
 * @property string $resource_id
 * @property string $user_id
 * @property string $action
 * @property array<array-key, mixed>|null $conditions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model|\Eloquent $resource
 * @property-read User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereConditions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereResourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUserId($value)
 * @mixin \Eloquent
 */
class Permission extends GlobalModel
{
    use Auditable;
    use Filterable;
    use HasFactory;
    use HasUlids;

    protected $table = 'permissions';

    protected $casts = [
        'conditions' => 'array',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
