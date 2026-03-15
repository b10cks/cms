<?php

namespace App\Services\Automation\Actions;

use App\Mail\Automation\AutomationMessage;
use App\Services\Automation\Enums\ActionType;
use Illuminate\Support\Facades\Mail;

class EmailActionHandler extends BaseActionHandler
{
    public function __construct()
    {
        $this->type = ActionType::EMAIL;
    }

    public function execute(array $config, array $context = []): mixed
    {
        $to = $this->resolveRecipients((array) ($config['to'] ?? []), $context, 'to');
        $cc = $this->resolveRecipients((array) ($config['cc'] ?? []), $context, 'cc');
        $bcc = $this->resolveRecipients((array) ($config['bcc'] ?? []), $context, 'bcc');
        $replyTo = $this->resolveRecipients((array) ($config['reply_to'] ?? []), $context, 'reply_to');

        $subject = $this->replaceVariables((string) ($config['subject'] ?? ''), $context);
        $body = $this->replaceVariables((string) ($config['body'] ?? ''), $context);

        $this->assertResolvedTemplate($subject, 'subject');
        $this->assertResolvedTemplate($body, 'body');

        try {
            $message = Mail::to($to);

            if ($cc !== []) {
                $message->cc($cc);
            }

            if ($bcc !== []) {
                $message->bcc($bcc);
            }

            foreach ($replyTo as $email) {
                $message->replyTo($email);
            }

            $message->send(new AutomationMessage($subject, $body));

            return true;

        } catch (\Exception $e) {
            \Log::error('Email action failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<int, mixed>  $recipients
     * @return array<int, string>
     */
    protected function resolveRecipients(array $recipients, array $context, string $field): array
    {
        $resolved = [];

        foreach ($recipients as $recipient) {
            $email = trim($this->replaceVariables((string) $recipient, $context));

            if ($email === '') {
                continue;
            }

            $this->assertResolvedTemplate($email, $field);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("Unable to send automation email: the {$field} recipient [{$email}] is invalid.");
            }

            $resolved[] = $email;
        }

        return array_values(array_unique($resolved));
    }

    protected function assertResolvedTemplate(string $value, string $field): void
    {
        if ($this->containsPlaceholders($value)) {
            throw new \RuntimeException("Unable to send automation email: unresolved placeholder detected in {$field}.");
        }
    }
}
