<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Slugs and keys live in ASCII columns, and MySQL answers a non-ASCII parameter
 * with error 3988 rather than an empty result. sqlite never raises it, so these
 * tests assert what the guard promises: a 404 and no ASCII-column query binding
 * the value.
 */
class DeliveryNonAsciiLookupTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create();
        Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'non-ascii-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    /** @return array<string, array{string, string}> */
    public static function paths(): array
    {
        return [
            'content umlaut' => ['/api/v1/contents/über-uns', 'über-uns'],
            'content word joiner' => ["/api/v1/contents/moebel\u{2060}", "moebel\u{2060}"],
            'content ellipsis' => ['/api/v1/contents/news…', 'news…'],
            'breadcrumb' => ['/api/v1/breadcrumbs/Künster.jpg', 'Künster.jpg'],
            'data source' => ['/api/v1/datasources/farbé/entries', 'farbé'],
            'icon' => ['/api/v1/iconify/b10cks/pfeil-ü.svg', 'pfeil-ü'],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_non_ascii_lookup_is_not_found_without_querying_it(string $path, string $value): void
    {
        $bound = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$bound, $value): void {
            foreach ($query->bindings as $binding) {
                // Redirect sources are utf8mb4 and may legitimately hold non-ASCII paths.
                if (\is_string($binding) && str_contains($binding, $value) && ! str_contains($query->sql, 'redirects')) {
                    $bound[] = $query->sql;
                }
            }
        });

        // Real clients percent-encode the path.
        $url = str_replace($value, rawurlencode($value), $path);

        $this->getJson($url.'?token=non-ascii-token&rv='.$this->space->updated_at->timestamp)->assertNotFound();

        $this->assertSame([], $bound);
    }
}
