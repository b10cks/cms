<?php

namespace Tests\Unit\Services\Automation;

use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function automationQueries(Space $space): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'automations')) {
                $queries[] = $query->sql;
            }
        });

        app(AutomationEngine::class)->processTrigger(TriggerType::ON_INSERT, ['space_id' => $space->id]);

        return $queries;
    }

    #[Test]
    public function inactive_trigger_types_skip_the_automation_lookup(): void
    {
        $space = Space::factory()->create();

        app(AutomationEngine::class)->activeTriggerTypes($space->id);

        $this->assertSame([], $this->automationQueries($space));
    }

    #[Test]
    public function saving_an_automation_invalidates_the_cached_trigger_types(): void
    {
        $space = Space::factory()->create();
        Http::fake();
        $engine = app(AutomationEngine::class);

        $this->assertSame([], $engine->activeTriggerTypes($space->id));

        $automation = Automation::factory()->forSpace($space)->create(['trigger_type' => 'on_insert', 'trigger_config' => []]);
        $this->assertSame(['on_insert'], $engine->activeTriggerTypes($space->id));
        $this->assertNotEmpty($this->automationQueries($space));

        $automation->delete();
        $this->assertSame([], $engine->activeTriggerTypes($space->id));
    }
}
