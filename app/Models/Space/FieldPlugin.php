<?php

namespace App\Models\Space;

use App\Casts\Slug;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $external_id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $dev_mode
 * @property string|null $dev_url
 * @property string|null $code
 * @property string|null $code_hash
 * @property int|null $code_size
 * @property Carbon|null $published_at
 * @property array<array-key, mixed>|null $manifest
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class FieldPlugin extends SpaceModel
{
    use Auditable;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SpaceAuditable;

    protected $table = 'field_plugins';

    // Matches the DB default so freshly created models report is_active
    // correctly before a refresh (the resource gates sandbox_url on it).
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'external_id',
        'name',
        'handle',
        'description',
        'dev_mode',
        'dev_url',
        'manifest',
        'is_active',
    ];

    protected $casts = [
        'handle' => Slug::class,
        'dev_mode' => 'boolean',
        'manifest' => 'json',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Get the purified description attribute.
     */
    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function isPublished(): bool
    {
        return $this->code_hash !== null;
    }

    /**
     * Store a new bundle and mark it published.
     */
    public function publish(string $code): void
    {
        $this->code = $code;
        $this->code_hash = hash('sha256', $code);
        $this->code_size = strlen($code);
        $this->published_at = now();
    }
}
