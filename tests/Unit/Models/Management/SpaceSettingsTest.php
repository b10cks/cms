<?php

namespace Tests\Unit\Models\Management;

use App\Models\Management\SpaceSettings;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceSettingsTest extends TestCase
{
    #[Test]
    public function it_falls_back_to_asset_classification_defaults_for_paths_missing_in_ai_settings(): void
    {
        // Settings merge shallowly, so a space with custom `ai` settings but no
        // asset_classification block must still resolve the defaults per path.
        $settings = SpaceSettings::make([
            'ai' => [
                'enabled' => true,
            ],
        ]);

        $this->assertTrue($settings->{'ai.enabled'});
        $this->assertFalse($settings->{'ai.asset_classification.auto_on_upload'});
        $this->assertSame(
            ['title', 'alt', 'description'],
            $settings->{'ai.asset_classification.allowed_fields'}
        );
    }

    #[Test]
    public function it_keeps_customized_asset_classification_settings(): void
    {
        $settings = SpaceSettings::make([
            'ai' => [
                'asset_classification' => [
                    'auto_on_upload' => true,
                    'allowed_fields' => ['alt'],
                ],
            ],
        ]);

        $this->assertTrue($settings->{'ai.asset_classification.auto_on_upload'});
        $this->assertSame(['alt'], $settings->{'ai.asset_classification.allowed_fields'});
    }

    #[Test]
    public function it_validates_asset_classification_settings_shape(): void
    {
        $validator = Validator::make([
            'ai' => [
                'asset_classification' => [
                    'auto_on_upload' => true,
                    'allowed_fields' => ['title', 'alt'],
                ],
            ],
        ], SpaceSettings::validationRules());

        $this->assertTrue($validator->passes());

        $invalidValidator = Validator::make([
            'ai' => [
                'asset_classification' => [
                    'allowed_fields' => 'title',
                ],
            ],
        ], SpaceSettings::validationRules());

        $this->assertTrue($invalidValidator->fails());
        $this->assertArrayHasKey('ai.asset_classification.allowed_fields', $invalidValidator->errors()->toArray());
    }
}
