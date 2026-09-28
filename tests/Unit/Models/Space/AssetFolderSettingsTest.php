<?php

namespace Tests\Unit\Models\Space;

use App\Models\Space\AssetFolderSettings;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetFolderSettingsTest extends TestCase
{
    #[Test]
    public function it_serializes_classification_allowed_field_overrides(): void
    {
        $settings = AssetFolderSettings::make([
            'classification_allowed_fields' => ['alt'],
        ]);

        $this->assertSame(['alt'], $settings->toArray()['classification_allowed_fields']);
    }

    #[Test]
    public function it_validates_classification_allowed_fields_shape(): void
    {
        $validator = Validator::make([
            'classification_allowed_fields' => ['alt', 'description'],
        ], AssetFolderSettings::validationRules());

        $this->assertTrue($validator->passes());

        $invalidValidator = Validator::make([
            'classification_allowed_fields' => 'alt',
        ], AssetFolderSettings::validationRules());

        $this->assertTrue($invalidValidator->fails());
        $this->assertArrayHasKey('classification_allowed_fields', $invalidValidator->errors()->toArray());
    }
}
