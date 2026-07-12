<?php

namespace Tests\Unit\Services\Automation;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\Enums\TriggerType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomationContextFactoryTest extends TestCase
{
    #[Test]
    public function content_model_events_expose_cache_tags(): void
    {
        $space = new Space;
        $space->id = 'space-id';
        $space->name = 'Space';

        $content = new Content;
        $content->forceFill([
            'settings' => [
                'cache_ttl' => 300,
                'cache_tags' => ['news'],
            ],
        ]);

        $context = app(AutomationContextFactory::class)->forModelEvent(
            $content,
            TriggerType::ON_UPDATE,
            space: $space,
        );

        $this->assertSame(['news'], $context['cache_tags']);
        $this->assertSame(['ttl' => 300, 'tags' => ['news']], $context['cache']);
    }

    #[Test]
    public function non_content_model_events_have_no_cache_keys(): void
    {
        $space = new Space;
        $space->id = 'space-id';
        $space->name = 'Space';

        $context = app(AutomationContextFactory::class)->forModelEvent(
            new Redirect,
            TriggerType::ON_UPDATE,
            space: $space,
        );

        $this->assertArrayNotHasKey('cache_tags', $context);
        $this->assertArrayNotHasKey('cache', $context);
    }
}
