<?php

namespace App\Models\Space;

use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $body
 * @property int $width
 * @property int $height
 * @property array<array-key, mixed>|null $tags
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Database\Factories\Space\IconFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Icon filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Icon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Icon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Icon query()
 * @mixin \Eloquent
 */
class Icon extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    protected string $spaceChannel = 'icons';

    protected $table = 'icons';

    protected $fillable = [
        'external_id',
        'key',
        'name',
        'description',
        'body',
        'width',
        'height',
        'tags',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'tags' => 'array',
    ];

    /**
     * The Iconify icon-data object for this icon.
     *
     * @return array{body: string, width: int, height: int}
     */
    public function toIconifyData(): array
    {
        return [
            'body' => $this->body,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * Render the icon as a standalone SVG document.
     */
    public function toSvg(?string $color = null, ?int $width = null, ?int $height = null): string
    {
        $attributes = [
            'xmlns' => 'http://www.w3.org/2000/svg',
            'width' => (string) ($width ?? $this->width),
            'height' => (string) ($height ?? $this->height),
            'viewBox' => "0 0 {$this->width} {$this->height}",
        ];

        if ($color !== null) {
            $attributes['color'] = $color;
        }

        $attributeString = collect($attributes)
            ->map(fn (string $value, string $name) => sprintf('%s="%s"', $name, htmlspecialchars($value, ENT_QUOTES)))
            ->implode(' ');

        return "<svg {$attributeString}>{$this->body}</svg>";
    }
}
