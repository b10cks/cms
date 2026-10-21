<?php

namespace Tests\Feature\Jobs;

use App\Events\Space\ContentsChanged;
use App\Events\Space\ContentUpdated;
use App\Events\Space\SpaceModelChanged;
use App\Events\Space\SpaceModelsChanged;
use App\Jobs\Content\UpdateContentFullSlugsJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(UpdateContentFullSlugsJob::class)]
class UpdateContentFullSlugsJobTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function a_folder_rename_sends_one_summary_instead_of_one_broadcast_per_descendant(): void
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $folder = Content::factory()->create(['slug' => 'old']);
        $children = Content::factory()->count(5)->create(['parent_id' => $folder->id]);

        // Rename without the model hooks, as if the dispatching save already ran.
        Content::query()->whereKey($folder->id)->update(['slug' => 'renamed', 'full_slug' => '/renamed']);
        $folder->refresh();

        Event::fake([ContentsChanged::class, ContentUpdated::class, SpaceModelChanged::class, SpaceModelsChanged::class]);

        (new UpdateContentFullSlugsJob($folder, $space))->handle();

        $this->assertSame('/renamed/'.$children->first()->slug, $children->first()->fresh()->full_slug);
        Event::assertNotDispatched(ContentUpdated::class);
        Event::assertNotDispatched(SpaceModelChanged::class);
        Event::assertDispatchedTimes(ContentsChanged::class, 1);
        Event::assertDispatchedTimes(SpaceModelsChanged::class, 1);
    }
}
