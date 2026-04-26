<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Management\SpaceAiConfig;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemPromptBuilderTest extends TestCase
{
    #[Test]
    public function it_includes_configured_prompt_for_translation(): void
    {
        $builder = new SystemPromptBuilder(new SpaceAiConfig([
            'system_prompt' => 'Use the brand voice for every answer.',
        ]));

        $prompt = $builder->forTranslation();

        $this->assertStringContainsString('You are an expert translator.', $prompt);
        $this->assertStringContainsString('## Space-Specific Behavior & Guidelines', $prompt);
        $this->assertStringContainsString('Use the brand voice for every answer.', $prompt);
    }

    #[Test]
    public function it_appends_configured_prompt_only_once(): void
    {
        $builder = new SystemPromptBuilder(new SpaceAiConfig([
            'system_prompt' => 'Always keep responses concise.',
        ]));

        $prompt = $builder->withConfiguredPrompt('Base instructions.');
        $prompt = $builder->withConfiguredPrompt($prompt);

        $this->assertSame(1, substr_count($prompt, '## Space-Specific Behavior & Guidelines'));
        $this->assertStringContainsString('Base instructions.', $prompt);
        $this->assertStringContainsString('Always keep responses concise.', $prompt);
    }
}
