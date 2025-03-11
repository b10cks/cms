<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcceptHeaderTest extends TestCase
{
    #[Test]
    #[DataProvider('localeDataProvider')]
    public function itSetsLocales($supported, $accept, $expected)
    {
        config()->set('app.locales', $supported);
        app()->setLocale('foo');
        $this->getJson('mgmt/v1/health', ['accept-language' => $accept]);
        $this->assertEquals($expected, app()->getLocale());
    }

    public static function localeDataProvider()
    {
        return [
            'en' => [
                ['en', 'de'],
                'en-US,en;q=0.9,de;q=0.8,de-AT;q=0.7',
                'en',
            ],
            'de-only' => [
                ['de'],
                'en-US,en;q=0.9,de;q=0.8,de-AT;q=0.7',
                'de',
            ],
            'de' => [
                ['en', 'de'],
                'de,de-AT;q=0.9,en-US;q=0.7,',
                'de',
            ],
            'non-supported' => [
                ['en', 'de'],
                'fr',
                'en',
            ],
        ];
    }
}
