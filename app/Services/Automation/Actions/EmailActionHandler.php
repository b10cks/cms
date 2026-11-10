<?php

namespace App\Services\Automation\Actions;

use App\Mail\Automation\AutomationMessage;
use App\Services\Automation\Enums\ActionType;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends from the platform address to addresses the space chooses, so both
 * the size of one mail and the volume per space are bounded, and every mail
 * names the space it comes from.
 */
class EmailActionHandler extends BaseActionHandler
{
    /** To, cc and bcc of one mail together. */
    public const int MAX_RECIPIENTS = 20;

    /** Recipients one space may mail per hour across all its automations. */
    public const int HOURLY_RECIPIENT_LIMIT = 200;

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

        $recipients = count($to) + count($cc) + count($bcc);
        if ($recipients > self::MAX_RECIPIENTS) {
            throw new \RuntimeException('Unable to send automation email: more than '.self::MAX_RECIPIENTS.' recipients.');
        }

        $volume = 'automation-mail|'.data_get($context, 'space.id');
        if (RateLimiter::attempts($volume) + $recipients > self::HOURLY_RECIPIENT_LIMIT) {
            throw new \RuntimeException('Unable to send automation email: the hourly limit of '.self::HOURLY_RECIPIENT_LIMIT.' recipients for this space is reached.');
        }
        RateLimiter::increment($volume, 3600, $recipients);

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

            $message->send(new AutomationMessage($subject, $body, data_get($context, 'space.name')));

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
