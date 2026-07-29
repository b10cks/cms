<?php

namespace Tests\Feature\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailImprintTest extends TestCase
{
    #[Test]
    public function saas_mail_keeps_the_b10cks_imprint(): void
    {
        config(['edition.edition' => 'saas']);

        $html = (string) (new MailMessage)->line('Hello')->render();

        $this->assertStringContainsString('Cantina', $html);
        $this->assertStringContainsString('b10cks.com/en/legal/imprint', $html);
    }

    #[Test]
    public function self_hosted_mail_has_no_b10cks_imprint(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $html = (string) (new MailMessage)->line('Hello')->render();

        $this->assertStringNotContainsString('Cantina', $html);
        $this->assertStringNotContainsString('b10cks.com/en/legal', $html);
    }

    #[Test]
    public function self_hosted_mail_renders_the_configured_imprint(): void
    {
        config([
            'edition.edition' => 'self-hosted',
            'edition.imprint.company' => 'Example GmbH',
            'edition.imprint.address' => 'Musterstraße 1, 12345 Berlin',
            'edition.imprint.notice' => 'You receive this because you have an account on cms.example.com.',
        ]);

        $html = (string) (new MailMessage)->line('Hello')->render();

        $this->assertStringContainsString('Example GmbH', $html);
        $this->assertStringContainsString('Musterstra', $html);
        $this->assertStringContainsString('cms.example.com', $html);
    }
}
