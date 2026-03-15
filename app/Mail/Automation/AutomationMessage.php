<?php

namespace App\Mail\Automation;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

class AutomationMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function build(): static
    {
        $mailMessage = (new MailMessage)
            ->subject($this->subjectLine);

        foreach (preg_split("/\r\n|\n|\r/", $this->body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $mailMessage->line($line);
        }

        return $this->markdown(
            $mailMessage->markdown,
            array_merge($mailMessage->data(), [
                'subject' => $this->subjectLine,
            ])
        );
    }
}
