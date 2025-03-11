<?php

namespace App\Models\System;

use App\Models\Management\GlobalModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\System\Language
 *
 * @property int $iso
 * @property string|null $locale
 * @property string $name
 * @property string $name_local
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereIso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereNameLocal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Language withoutTrashed()
 * @mixin \Eloquent
 */
class Language extends GlobalModel
{
    use SoftDeletes;

    protected $primaryKey = 'iso';

    protected $table = 'languages';
}
