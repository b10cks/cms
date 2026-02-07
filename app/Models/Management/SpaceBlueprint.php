<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaceBlueprint extends GlobalModel
{
    use Auditable;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'space_blueprints';

    protected $fillable = [
        'name',
        'icon',
        'color',
        'description',
        'settings',
        'data',
        'team_id',
        'created_by_id',
    ];

    protected $casts = [
        'settings' => 'array',
        'data' => 'array',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }
}
