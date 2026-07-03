<?php

namespace Tests\Unit\Services\Asset;

use App\Services\Asset\DominantColorExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DominantColorExtractor::class)]
class DominantColorExtractorTest extends TestCase
{
    private DominantColorExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new DominantColorExtractor;
    }

    #[Test]
    public function it_extracts_the_dominant_color_from_a_solid_image(): void
    {
        $result = $this->extractor->extractFromString($this->makePng(function ($image) {
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 60));
        }));

        $this->assertNotNull($result);
        $this->assertColorNear([200, 40, 60], $result['dominant_color']);
        $this->assertSame($result['dominant_color'], $result['palette'][0]);
    }

    #[Test]
    public function it_picks_the_majority_color_and_includes_minority_colors_in_the_palette(): void
    {
        $result = $this->extractor->extractFromString($this->makePng(function ($image) {
            imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));
            imagefilledrectangle($image, 0, 0, 99, 24, imagecolorallocate($image, 240, 240, 10));
        }));

        $this->assertNotNull($result);
        $this->assertColorNear([10, 200, 30], $result['dominant_color']);
        $this->assertGreaterThanOrEqual(2, count($result['palette']));
    }

    #[Test]
    public function it_ignores_transparent_pixels(): void
    {
        $result = $this->extractor->extractFromString($this->makePng(function ($image) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledrectangle($image, 40, 40, 60, 60, imagecolorallocatealpha($image, 20, 40, 200, 0));
        }));

        $this->assertNotNull($result);
        $this->assertColorNear([20, 40, 200], $result['dominant_color']);
    }

    #[Test]
    public function it_returns_null_for_a_fully_transparent_image(): void
    {
        $result = $this->extractor->extractFromString($this->makePng(function ($image) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }));

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_undecodable_content(): void
    {
        $this->assertNull($this->extractor->extractFromString('definitely not an image'));
    }

    #[Test]
    public function it_returns_null_for_unsupported_mime_types(): void
    {
        $this->assertNull($this->extractor->extract(__FILE__, 'image/svg+xml'));
    }

    #[Test]
    public function it_computes_wcag_a11y_stats_for_light_and_dark_colors(): void
    {
        $white = DominantColorExtractor::a11yStats('#ffffff');

        $this->assertSame('light', $white['scheme']);
        $this->assertEqualsWithDelta(1.0, $white['luminance'], 0.001);
        $this->assertEqualsWithDelta(21.0, $white['contrast_black'], 0.01);
        $this->assertEqualsWithDelta(1.0, $white['contrast_white'], 0.01);

        $black = DominantColorExtractor::a11yStats('#000000');

        $this->assertSame('dark', $black['scheme']);
        $this->assertEqualsWithDelta(0.0, $black['luminance'], 0.001);
        $this->assertEqualsWithDelta(21.0, $black['contrast_white'], 0.01);

        // Mid-tone sanity check: #808080 has luminance ~0.216, so white text
        // (ratio ~3.95) beats black text (ratio ~5.32) → 'light'.
        $grey = DominantColorExtractor::a11yStats('#808080');

        $this->assertSame('light', $grey['scheme']);
        $this->assertEqualsWithDelta(3.95, $grey['contrast_white'], 0.05);
        $this->assertEqualsWithDelta(5.32, $grey['contrast_black'], 0.05);
    }

    #[Test]
    public function extraction_results_include_a11y_stats_for_the_dominant_color(): void
    {
        $result = $this->extractor->extractFromString($this->makePng(function ($image) {
            imagefill($image, 0, 0, imagecolorallocate($image, 10, 10, 10));
        }));

        $this->assertNotNull($result);
        $this->assertSame('dark', $result['a11y']['scheme']);
        $this->assertEquals(
            DominantColorExtractor::a11yStats($result['dominant_color']),
            $result['a11y']
        );
    }

    #[Test]
    public function it_reports_format_support(): void
    {
        $this->assertTrue(DominantColorExtractor::supports('image/jpeg'));
        $this->assertTrue(DominantColorExtractor::supports('image/png'));
        $this->assertFalse(DominantColorExtractor::supports('image/svg+xml'));
        $this->assertFalse(DominantColorExtractor::supports('application/pdf'));
        $this->assertFalse(DominantColorExtractor::supports(null));
    }

    private function makePng(callable $draw): string
    {
        $image = imagecreatetruecolor(100, 100);
        $draw($image);

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function assertColorNear(array $expectedRgb, string $hex): void
    {
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $distance = sqrt(
            ($expectedRgb[0] - $r) ** 2
            + ($expectedRgb[1] - $g) ** 2
            + ($expectedRgb[2] - $b) ** 2
        );

        $this->assertLessThan(24, $distance, 'Expected color near rgb('.implode(',', $expectedRgb).") but got {$hex}");
    }
}
