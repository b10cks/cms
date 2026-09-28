<?php

namespace Tests\Feature;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentPositionServiceTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function a_wide_reorder_batches_writes_and_preserves_untouched_rows(): void
    {
        $this->setUpSpaceTesting(Space::factory()->create());
        $this->travelTo(now()->startOfSecond());
        $previousTimestamp = now()->subDay()->format('Y-m-d H:i:s');
        $ids = [];

        for ($position = 0; $position < 207; $position++) {
            $ids[] = $id = (string) Str::ulid();
            Content::query()->insert([
                'id' => $id,
                'block_id' => (string) Str::ulid(),
                'current_version_id' => (string) Str::ulid(),
                'name' => 'Item '.$position,
                'slug' => 'item-'.$position,
                'full_slug' => 'item-'.$position,
                'position' => $position,
                'language_iso' => $position === 205 ? 'de' : 'en',
                'deleted_at' => $position === 206 ? $previousTimestamp : null,
                'updated_at' => $previousTimestamp,
            ]);
        }

        $moving = Content::query()->findOrFail($ids[204]);
        $connection = $moving->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        app(ContentPositionService::class)->moveItemToPosition($moving, null, 1);

        $updates = collect($connection->getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
        $connection->disableQueryLog();

        $this->assertCount(2, $updates);
        $this->assertSame(1, $moving->position);
        $ordered = Content::query()->where('language_iso', 'en')->orderBy('position')->get();
        $this->assertSame([$ids[0], $ids[204], ...array_slice($ids, 1, 203)], $ordered->pluck('id')->all());
        $this->assertSame(range(0, 204), $ordered->pluck('position')->all());
        foreach ([$ids[0], $ids[205], $ids[206]] as $untouchedId) {
            $this->assertSame($previousTimestamp, Content::withTrashed()->findOrFail($untouchedId)->getRawOriginal('updated_at'));
        }
        $this->assertSame(now()->format('Y-m-d H:i:s'), $moving->fresh()->getRawOriginal('updated_at'));

        $connection->enableQueryLog();
        $connection->flushQueryLog();
        app(ContentPositionService::class)->moveItemToPosition($moving, null, 1);
        $updates = collect($connection->getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
        $connection->disableQueryLog();
        $this->assertCount(0, $updates);
    }
}
