<?php

namespace Tests\Unit\Services\Space;

use App\Services\Space\Dto\UsageMetricDto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageMetricDtoTest extends TestCase
{
    #[Test]
    public function it_computes_percentage_from_used_and_limit(): void
    {
        $metric = new UsageMetricDto('storage', 'bytes', 750, 1000);

        $this->assertSame(75, $metric->percentage());
        $this->assertFalse($metric->unlimited());
    }

    #[Test]
    public function it_clamps_percentage_to_one_hundred(): void
    {
        $metric = new UsageMetricDto('traffic', 'bytes', 5000, 1000);

        $this->assertSame(100, $metric->percentage());
    }

    #[Test]
    public function a_null_limit_is_unlimited_and_zero_percent(): void
    {
        $metric = new UsageMetricDto('requests', 'count', 1234, null);

        $this->assertTrue($metric->unlimited());
        $this->assertSame(0, $metric->percentage());
    }

    #[Test]
    public function byte_and_count_metrics_serialise_as_integers(): void
    {
        $metric = new UsageMetricDto('storage', 'bytes', 1500.9, 2000.4);

        $array = $metric->toArray();

        $this->assertSame('storage', $array['key']);
        $this->assertSame('bytes', $array['unit']);
        $this->assertSame(1501, $array['used']);
        $this->assertSame(2000, $array['limit']);
        $this->assertFalse($array['unlimited']);
        $this->assertTrue($array['available']);
    }

    #[Test]
    public function usd_metrics_keep_fractional_precision(): void
    {
        $metric = new UsageMetricDto('ai', 'usd', 2.3456789, 5.0);

        $array = $metric->toArray();

        $this->assertSame(2.345679, $array['used']);
        $this->assertSame(5.0, $array['limit']);
        $this->assertSame(47, $array['percentage']);
    }

    #[Test]
    public function an_unlimited_metric_serialises_a_null_limit(): void
    {
        $metric = new UsageMetricDto('ai', 'usd', 9.0, null);

        $array = $metric->toArray();

        $this->assertNull($array['limit']);
        $this->assertTrue($array['unlimited']);
        $this->assertSame(0, $array['percentage']);
    }
}
