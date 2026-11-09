<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RepairContentTreeCommand;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(RepairContentTreeCommand::class)]
class RepairContentTreeCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_rehomes_translated_children_repairs_their_slugs_and_bumps_the_delivery_revision(): void
    {
        [$deParent, $deChild] = $this->createLegacyTranslatedChild();

        $this->artisan('content:repair-tree', ['--space' => $this->space->id])
            ->expectsOutputToContain('1 re-homed, 1 slug(s) updated, 1 redirect(s)')
            ->assertSuccessful();

        $deChild->refresh();
        $this->assertSame($deParent->id, $deChild->parent_id);
        $this->assertSame('/ueber-uns/team', $deChild->full_slug);
        $this->assertSame(1, Redirect::query()->count());
        $this->assertNotNull($this->space->fresh()->content_updated_at);
    }

    #[Test]
    public function a_dry_run_reports_the_repair_without_writing_it(): void
    {
        [, $deChild] = $this->createLegacyTranslatedChild();
        $before = $deChild->fresh()->only(['parent_id', 'full_slug']);

        $this->artisan('content:repair-tree', ['--space' => $this->space->id, '--dry-run' => true])
            ->expectsOutputToContain('1 re-homed, 1 slug(s) updated, 1 redirect(s)')
            ->assertSuccessful();

        $this->assertSame($before, $deChild->fresh()->only(['parent_id', 'full_slug']));
        $this->assertSame(0, Redirect::query()->count());
        $this->assertNull($this->space->fresh()->content_updated_at);
    }

    #[Test]
    public function it_leaves_a_correct_tree_and_the_delivery_revision_alone(): void
    {
        $parent = Content::factory()->create(['slug' => 'about', 'language_iso' => 'en']);
        Content::factory()->create(['slug' => 'team', 'language_iso' => 'en', 'parent_id' => $parent->id]);
        Redirect::query()->delete();

        $this->artisan('content:repair-tree', ['--space' => $this->space->id])
            ->expectsOutputToContain('0 re-homed, 0 slug(s) updated')
            ->assertSuccessful();

        $this->assertSame(0, Redirect::query()->count());
        $this->assertNull($this->space->fresh()->content_updated_at);
    }

    /**
     * A translated child filed under the canonical parent, with the path it
     * kept after its translated parent was renamed.
     *
     * @return array{Content, Content}
     */
    private function createLegacyTranslatedChild(): array
    {
        $enParent = Content::factory()->create(['slug' => 'about', 'language_iso' => 'en']);
        $deParent = Content::factory()->create(['slug' => 'ueber-uns', 'language_iso' => 'de', 'i18n_parent_id' => $enParent->id]);
        $enChild = Content::factory()->create(['slug' => 'team', 'language_iso' => 'en', 'parent_id' => $enParent->id]);
        $deChild = Content::factory()->create([
            'slug' => 'team',
            'language_iso' => 'de',
            'parent_id' => $enParent->id,
            'i18n_parent_id' => $enChild->id,
        ]);
        Content::query()->whereKey($deChild->id)->update(['full_slug' => '/ueber/team']);
        // The factory's random full_slug leaves redirects behind on create.
        Redirect::query()->delete();

        return [$deParent, $deChild];
    }
}
