<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiSkill;
use App\Models\Management\AiRun;
use App\Models\Management\AiSchedule;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiScheduleTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function a_schedule_whose_creator_lost_space_access_pauses_and_does_not_call_the_provider(): void
    {
        $this->user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $this->user, 'owner');
        $this->setUpSpaceTesting($space);
        $this->giveSpaceAiConfig($space);
        $driver = $this->fakeAi();
        $this->actingAs($this->user);

        $skill = AiSkill::create([
            ...AiSkill::ownerAttributes($space),
            'name' => 'refresh-summaries',
            'draft' => ['files' => ['SKILL.md' => "---\nname: refresh-summaries\ndescription: Refreshes summaries.\nb10cks:\n  surfaces: [bulk:content]\n---\n\nRefresh the summary."]],
        ]);
        app(PublishAiSkill::class)->handle($skill, $this->user);

        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/schedules", [
            'name' => 'Weekly refresh',
            'skill' => 'refresh-summaries',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
            'cron' => '* * * * *',
            'max_usd' => 0.5,
        ])->assertCreated();

        app(MembershipService::class)->removeSpaceMembership($space, $this->user);
        $this->artisan('ai:run-schedules')->assertSuccessful();

        $schedule = AiSchedule::query()->sole();
        $this->assertFalse($schedule->enabled);
        $this->assertSame(AiSchedule::PAUSED_ACCESS, $schedule->paused_reason);
        $this->assertSame(0, $driver->calls);
        $this->assertSame(0, AiRun::query()->count());
    }
}
