<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProviderNote extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'title',
        'icon',
        'url',
        'color',
        'content',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];
}
