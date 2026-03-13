<?php

namespace App\Models\Management;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string|null $team_id
 * @property RoleScope $scope
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $level
 * @property bool $is_system
 * @property array<int, string> $abilities
 */
class Role extends GlobalModel
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'roles';

    protected $fillable = [
        'team_id',
        'scope',
        'key',
        'name',
        'description',
        'level',
        'is_system',
        'abilities',
    ];

    protected $casts = [
        'scope' => RoleScope::class,
        'level' => 'integer',
        'is_system' => 'boolean',
        'abilities' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }
}
