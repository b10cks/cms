<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The allocation ledger behind `serial` fields.
 *
 * One live row is one reservation: it holds `number` inside its numbering scope
 * and the rendered `value` inside its uniqueness scope, both enforced by unique
 * indexes rather than by application checks. The value is mirrored into the
 * content payload — this table exists so uniqueness is a database guarantee and
 * so the next number can be found without scanning JSON.
 *
 * @property string $id
 * @property string $content_id
 * @property string $field_key
 * @property string $scope_key
 * @property string|null $unique_key
 * @property int $number
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Content|null $content
 *
 * @mixin \Eloquent
 */
class ContentSerial extends SpaceModel
{
    use HasUlids;

    protected $table = 'content_serials';

    protected $fillable = [
        'content_id',
        'field_key',
        'scope_key',
        'unique_key',
        'number',
        'value',
    ];

    protected $casts = [
        'number' => 'integer',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id', 'id');
    }
}
