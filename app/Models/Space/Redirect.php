<?php

namespace App\Models\Space;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 *
 *
 * @property string $id
 * @property string $source
 * @property string $target
 * @property int $status_code
 * @property int $hits
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereHits($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereStatusCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereTarget($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Redirect whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Redirect extends SpaceModel
{
    use HasUlids;
    use Filterable;

    protected $table = 'redirects';

    protected $fillable = [
        'source',
        'target',
        'status_code',
        'hits'
    ];

    protected $casts = [
        'source' => 'string',
        'target' => 'string',
        'status_code' => 'integer',
        'last_used_at' => 'datetime',
        'hits' => 'integer',
    ];

    public function trackUsage(): void
    {
        self::where('id', $this->id)->update([
            'hits' => \DB::raw('hits + 1'),
            'last_used_at' => now(),
        ]);
    }
}
