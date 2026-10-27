<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\TextDelta;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Closure;
use Generator;

/**
 * Scripted driver for run tests: each provider call takes the next scripted
 * turn and records the transcript it was given. No network.
 */
class FakeAiDriver implements AiDriverInterface
{
    /** @var list<TurnResult|Closure(Transcript): TurnResult> */
    public array $script = [];

    /** @var list<list<array<string, mixed>>> */
    public array $transcripts = [];

    /** @var list<list<string>> */
    public array $toolNames = [];

    public int $calls = 0;

    public bool $tools = true;

    /**
     * @param  list<TurnResult|Closure(Transcript): TurnResult>  $script
     */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public static function text(string $text, int $input = 10, int $output = 5): TurnResult
    {
        return new TurnResult($text, [], new TurnUsage($input, $output));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = [], ?string $id = null): TurnResult
    {
        return new TurnResult('', [new ToolCall($id ?? 'call_'.uniqid(), $name, $arguments)], new TurnUsage(10, 5));
    }

    public function push(TurnResult|Closure ...$turns): self
    {
        array_push($this->script, ...$turns);

        return $this;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getModels(): array
    {
        return [];
    }

    public function getDefaultModel(): ?AiModelDto
    {
        return null;
    }

    public function supportsToolCalls(string $modelId): bool
    {
        return $this->tools;
    }

    public function turn(Transcript $transcript, ToolSet $tools, RunOptions $options): Generator
    {
        $this->calls++;
        $this->transcripts[] = $transcript->toArray();
        $this->toolNames[] = $tools->names();

        $next = array_shift($this->script) ?? self::text('');
        $result = $next instanceof Closure ? $next($transcript) : $next;

        if ($result->text !== '') {
            yield new TextDelta($result->text);
        }

        return $result;
    }

    /** Text of the user message of the last call, for prompt assertions. */
    public function lastUserText(): string
    {
        $content = collect(end($this->transcripts) ?: [])->firstWhere('role', 'user')['content'] ?? '';

        return \is_array($content) ? (string) ($content[0]['text'] ?? '') : (string) $content;
    }

    public function lastSystemPrompt(): string
    {
        return (string) (collect(end($this->transcripts) ?: [])->firstWhere('role', 'system')['content'] ?? '');
    }
}
