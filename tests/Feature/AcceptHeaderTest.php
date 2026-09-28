<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcceptHeaderTest extends TestCase
{
    #[Test]
    #[DataProvider('translatedLocaleDataProvider')]
    public function itUsesConfiguredTranslations(string $locale, string $message): void
    {
        $this->getJson('mgmt/v1/health', ['accept-language' => $locale]);

        $this->assertSame($locale, app()->getLocale());
        $this->assertSame($message, __('auth.password'));
    }

    public static function translatedLocaleDataProvider(): array
    {
        return [
            'Spanish' => ['es', 'La contraseña facilitada es incorrecta.'],
            'French' => ['fr', 'Le mot de passe fourni est incorrect.'],
            'Russian' => ['ru', 'Введённый пароль неверен.'],
            'Turkish' => ['tr', 'Girilen şifre hatalı.'],
        ];
    }

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
