<?php

namespace App\Models\System;

use App\Models\Management\GlobalModel;
use App\Models\Traits\TranslatedAttribute;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * App\Models\System\Country
 *
 * @property int $iso2
 * @property string|null $name_local
 * @property array<array-key, mixed>|null $name_translation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read mixed $name_localized
 * @method static Builder<static>|Country newModelQuery()
 * @method static Builder<static>|Country newQuery()
 * @method static Builder<static>|Country onlyTrashed()
 * @method static Builder<static>|Country query()
 * @method static Builder<static>|Country whereCreatedAt($value)
 * @method static Builder<static>|Country whereDeletedAt($value)
 * @method static Builder<static>|Country whereIso2($value)
 * @method static Builder<static>|Country whereNameLocal($value)
 * @method static Builder<static>|Country whereNameTranslation($value)
 * @method static Builder<static>|Country whereUpdatedAt($value)
 * @method static Builder<static>|Country withTrashed()
 * @method static Builder<static>|Country withoutTrashed()
 * @mixin Eloquent
 */
class Country extends GlobalModel
{
    use HasFactory;
    use SoftDeletes;
    use TranslatedAttribute;

    protected $primaryKey = 'iso2';

    protected $table = 'countries';

    protected $casts = [
        'name_translation' => 'array',
    ];

    public function nameLocalized(): Attribute
    {
        return Attribute::make(
            fn() => $this->getJsonTranslatedValue('name_translation', $this->name_local)
        );
    }
}
