<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\ImageTransformationResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageTransformationResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_height_only_resizes_without_a_zero_width(): void
    {
        $transformation = app(ImageTransformationResolver::class)->resolve([
            'h' => 200,
        ]);

        $this->assertSame('resize', $transformation->operation);
        $this->assertNull($transformation->params['width']);
        $this->assertSame(200, $transformation->params['height']);
    }

    #[Test]
    public function it_resolves_focus_based_fill_operations(): void
    {
        $transformation = app(ImageTransformationResolver::class)->resolve([
            'c' => 'fill',
            'g' => '25.5p_40p',
            'w' => 800,
            'h' => 400,
        ]);

        $this->assertSame('fitfocus', $transformation->operation);
        $this->assertSame(25.5, $transformation->params['focusX']);
        $this->assertSame(40.0, $transformation->params['focusY']);
    }

    #[Test]
    public function it_resolves_crop_resize_transformations_when_target_dimensions_are_present(): void
    {
        $transformation = app(ImageTransformationResolver::class)->resolve([
            'c' => 'crop',
            'x' => 10,
            'y' => 20,
            'w' => 300,
            'h' => 200,
            'tw' => 120,
            'th' => 80,
        ]);

        $this->assertSame('cropresize', $transformation->operation);
        $this->assertSame(120, $transformation->params['targetWidth']);
        $this->assertSame(80, $transformation->params['targetHeight']);
    }
}
