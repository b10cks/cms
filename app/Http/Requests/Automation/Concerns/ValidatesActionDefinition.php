<?php

namespace App\Http\Requests\Automation\Concerns;

use App\Services\Automation\Enums\ActionType;
use Illuminate\Validation\Validator;

trait ValidatesActionDefinition
{
    protected function validateActionDefinition(
        Validator $validator,
        string $typeValue,
        array $config,
        array $secrets = [],
    ): void {
        $type = ActionType::tryFrom($typeValue);
        if (! $type) {
            return;
        }

        $this->validateKeyValueMap($validator, 'secrets', $secrets);

        match ($type) {
            ActionType::WEBHOOK => $this->validateWebhookConfig($validator, $config),
            ActionType::EMAIL => $this->validateEmailConfig($validator, $config),
            ActionType::VOID => $this->validateVoidConfig($validator, $config),
        };
    }

    protected function validateWebhookConfig(Validator $validator, array $config): void
    {
        $url = trim((string) ($config['url'] ?? ''));
        if ($url === '') {
            $validator->errors()->add('config.url', 'A webhook URL is required.');
        } elseif (! $this->isTemplateAwareUrl($url)) {
            $validator->errors()->add('config.url', 'Webhook URLs must be valid http or https URLs.');
        }

        $method = strtoupper((string) ($config['method'] ?? ''));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $validator->errors()->add('config.method', 'Choose a valid webhook method.');
        }

        $timeoutSeconds = $config['timeout_seconds'] ?? null;
        if ($timeoutSeconds !== null && (! is_numeric($timeoutSeconds) || (int) $timeoutSeconds < 1 || (int) $timeoutSeconds > 120)) {
            $validator->errors()->add('config.timeout_seconds', 'Webhook timeouts must be between 1 and 120 seconds.');
        }

        $this->validateKeyValueMap($validator, 'config.headers', (array) ($config['headers'] ?? []));
        $this->validateKeyValueMap($validator, 'config.parameters', (array) ($config['parameters'] ?? []));
    }

    protected function validateEmailConfig(Validator $validator, array $config): void
    {
        $recipientFields = ['to', 'cc', 'bcc', 'reply_to'];
        foreach ($recipientFields as $field) {
            $values = $config[$field] ?? [];
            if ($values === null) {
                continue;
            }

            if (! is_array($values)) {
                $validator->errors()->add("config.$field", 'Recipients must be provided as a list.');
                continue;
            }

            foreach ($values as $index => $value) {
                $email = trim((string) $value);
                if ($email === '') {
                    $validator->errors()->add("config.$field.$index", 'Recipient values may not be empty.');
                    continue;
                }

                if (! $this->isTemplateAwareEmail($email)) {
                    $validator->errors()->add("config.$field.$index", 'Recipients must be valid email addresses or placeholders.');
                }
            }
        }

        if (empty($config['to']) || ! is_array($config['to'])) {
            $validator->errors()->add('config.to', 'At least one recipient is required.');
        }

        if (trim((string) ($config['subject'] ?? '')) === '') {
            $validator->errors()->add('config.subject', 'An email subject is required.');
        }

        if (trim((string) ($config['body'] ?? '')) === '') {
            $validator->errors()->add('config.body', 'An email body is required.');
        }
    }

    protected function validateVoidConfig(Validator $validator, array $config): void
    {
        $message = $config['message'] ?? null;
        if ($message !== null && ! is_string($message)) {
            $validator->errors()->add('config.message', 'Void action messages must be strings.');
        }
    }

    protected function validateKeyValueMap(Validator $validator, string $field, array $values): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                $validator->errors()->add($field, 'Configuration keys may not be empty.');
                continue;
            }

            if (! preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
                $validator->errors()->add($field, 'Configuration keys may only contain letters, numbers, dots, dashes, and underscores.');
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $validator->errors()->add($field, 'Configuration values must be scalar.');
            }
        }
    }

    protected function isTemplateAwareUrl(string $value): bool
    {
        return str_contains($value, '{{')
            || (bool) filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    protected function isTemplateAwareEmail(string $value): bool
    {
        return str_contains($value, '{{') || (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
