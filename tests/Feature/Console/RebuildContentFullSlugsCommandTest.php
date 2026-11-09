<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RebuildContentFullSlugsCommand;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(RebuildContentFullSlugsCommand::class)]
class RebuildContentFullSlugsCommandTest extends TestCase
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
    public function it_repairs_stale_translated_children_with_a_redirect_and_bumps_the_delivery_revision(): void
    {
        $enParent = Content::factory()->create(['slug' => 'about', 'language_iso' => 'en']);
        Content::factory()->create(['slug' => 'ueber-uns', 'language_iso' => 'de', 'i18n_parent_id' => $enParent->id]);
        $deChild = Content::factory()->create(['slug' => 'team', 'language_iso' => 'de', 'parent_id' => $enParent->id]);
        // The state a translated parent rename left behind before the fix.
        Content::query()->whereKey($deChild->id)->update(['full_slug' => '/ueber/team']);
        Redirect::query()->delete();

        $this->artisan('content:rebuild-full-slugs', ['--space' => $this->space->id])
            ->expectsOutputToContain('1 slug(s) updated, 1 redirect(s)')
            ->assertSuccessful();

        $this->assertSame('/ueber-uns/team', $deChild->fresh()->full_slug);
        $this->assertSame(1, Redirect::query()->count());
        $this->assertNotNull($this->space->fresh()->content_updated_at);
    }

    #[Test]
    public function a_dry_run_reports_the_repair_without_writing_it(): void
    {
        $enParent = Content::factory()->create(['slug' => 'about', 'language_iso' => 'en']);
        Content::factory()->create(['slug' => 'ueber-uns', 'language_iso' => 'de', 'i18n_parent_id' => $enParent->id]);
        $deChild = Content::factory()->create(['slug' => 'team', 'language_iso' => 'de', 'parent_id' => $enParent->id]);
        Content::query()->whereKey($deChild->id)->update(['full_slug' => '/ueber/team']);
        Redirect::query()->delete();

        $this->artisan('content:rebuild-full-slugs', ['--space' => $this->space->id, '--dry-run' => true])
            ->expectsOutputToContain('1 slug(s) updated, 1 redirect(s)')
            ->assertSuccessful();

        $this->assertSame('/ueber/team', $deChild->fresh()->full_slug);
        $this->assertSame(0, Redirect::query()->count());
        $this->assertNull($this->space->fresh()->content_updated_at);
    }

    #[Test]
    public function it_leaves_correct_slugs_and_the_delivery_revision_alone(): void
    {
        $parent = Content::factory()->create(['slug' => 'about', 'language_iso' => 'en']);
        Content::factory()->create(['slug' => 'team', 'language_iso' => 'en', 'parent_id' => $parent->id]);
        Redirect::query()->delete();

        $this->artisan('content:rebuild-full-slugs', ['--space' => $this->space->id])
            ->expectsOutputToContain('0 slug(s) updated')
            ->assertSuccessful();

        $this->assertSame(0, Redirect::query()->count());
        $this->assertNull($this->space->fresh()->content_updated_at);
    }
}
