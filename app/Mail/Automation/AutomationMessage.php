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
        public ?string $spaceName = null,
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

        // The mail leaves from the platform address with text a space wrote.
        // Naming the space keeps it from passing as a message of the platform.
        if ($this->spaceName !== null) {
            $mailMessage->line(__('This message was sent by an automation of the space ":space".', [
                'space' => strtr($this->spaceName, ['\\' => '\\\\', '[' => '\[']),
            ]));
        }

        return $this->markdown(
            $mailMessage->markdown,
            array_merge($mailMessage->data(), [
                'subject' => $this->subjectLine,
            ])
        );
    }
}
