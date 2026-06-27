<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Dto\AiUsageDto;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageDtoTest extends TestCase
{
    #[Test]
    public function it_computes_percentage_from_used_and_limit(): void
    {
        $dto = new AiUsageDto(
            provider: 'openrouter',
            unit: 'usd',
            available: true,
            used: 2.5,
            limit: 5.0,
        );

        $this->assertSame(50, $dto->percentage());
    }

    #[Test]
    public function it_clamps_percentage_to_one_hundred_when_over_limit(): void
    {
        $dto = new AiUsageDto(
            provider: 'openrouter',
            unit: 'usd',
            available: true,
            used: 12.0,
            limit: 5.0,
        );

        $this->assertSame(100, $dto->percentage());
    }

    #[Test]
    public function unlimited_and_missing_limits_report_zero_percent(): void
    {
        $unlimited = AiUsageDto::unlimited('openrouter', 'usd', 'monthly', used: 9.0);
        $this->assertSame(0, $unlimited->percentage());

        $noLimit = new AiUsageDto(provider: 'openrouter', unit: 'usd', available: true, used: 1.0, limit: null);
        $this->assertSame(0, $noLimit->percentage());
    }

    #[Test]
    public function unavailable_factory_marks_the_snapshot_unavailable(): void
    {
        $dto = AiUsageDto::unavailable('openai', 'No metering here.');

        $array = $dto->toArray();

        $this->assertFalse($array['available']);
        $this->assertSame('openai', $array['provider']);
        $this->assertSame('No metering here.', $array['message']);
    }

    #[Test]
    public function to_array_rounds_amounts_and_serialises_the_reset_date(): void
    {
        $dto = new AiUsageDto(
            provider: 'openrouter',
            unit: 'usd',
            available: true,
            live: true,
            used: 2.3456789,
            limit: 5.0,
            remaining: 2.6543211,
            reset: 'monthly',
            resetsAt: Carbon::parse('2026-07-01T00:00:00Z'),
            breakdown: ['daily' => 0.1, 'weekly' => 0.5, 'monthly' => 2.3],
        );

        $array = $dto->toArray();

        $this->assertSame('usd', $array['unit']);
        $this->assertTrue($array['live']);
        $this->assertSame(2.345679, $array['used']);
        $this->assertSame(5.0, $array['limit']);
        $this->assertSame(2.654321, $array['remaining']);
        $this->assertSame(47, $array['percentage']);
        $this->assertSame('monthly', $array['reset']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $array['resets_at']);
        $this->assertSame(['daily' => 0.1, 'weekly' => 0.5, 'monthly' => 2.3], $array['breakdown']);
    }
}
