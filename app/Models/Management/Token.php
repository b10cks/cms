<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use App\Services\TokenAbility;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\BaseToken
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $name
 * @property string $token
 * @property string|null $abilities
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int|null $execution_limit
 * @property int $execution_count
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $make_purified_attribute
 * @property-read \App\Models\Management\Space $space
 * @method static \Database\Factories\Management\TokenFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereAbilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereExecutionCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereExecutionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Token whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Token extends GlobalModel
{
    use Auditable;
    use HasFactory;
    use Filterable;
    use HasPurifiedAttributes;
    use HasUlids;

    protected $table = 'tokens';

    protected $fillable = [
        'name',
        'expires_at',
        'execution_count',
        'execution_limit',
        'last_used_at',
        'abilities',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'date',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function abilities(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => TokenAbility::fromArray(json_decode($value, true) ?? []),
            set: fn ($value) => json_encode(is_array($value) ? $value : $value->toArray())
        );
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function getAuditRedactedFields(): array
    {
        return ['token'];
    }

    public function getAuditExcludedFields(): array
    {
        return ['execution_count', 'last_used_at'];
    }

    public function hasAbility(string $table, string $action): bool
    {
        return $this->abilities->hasAbility($table, $action);
    }

    public function getTableAbilities(string $table): array
    {
        return $this->abilities->getResourceAbilities($table);
    }

    public static function findValidToken(string $plainTextToken): ?Token
    {
        return Token::with(['space'])
            ->where('token', $plainTextToken)
            ->whereHas('space', function ($query) {
                $query->where('state', 'live');
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }
}
