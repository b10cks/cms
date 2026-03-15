<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Contracts\ActionHandler;
use App\Services\Automation\Enums\ActionType;

abstract class BaseActionHandler implements ActionHandler
{
    protected const string TEMPLATE_PATTERN = '/\{\{\s*([^{}]+?)\s*\}\}/';

    protected ActionType $type;

    public function canHandle(ActionType $actionType): bool
    {
        return $this->type->value === $actionType->value;
    }

    protected function replaceVariables(string $template, array $context): string
    {
        return preg_replace_callback(self::TEMPLATE_PATTERN, function (array $matches) use ($context) {
            $path = trim((string) ($matches[1] ?? ''));

            if ($path === '') {
                return $matches[0];
            }

            $missing = new \stdClass;
            $value = data_get($context, $path, $missing);

            if ($value === $missing) {
                return $matches[0];
            }

            return $this->stringifyTemplateValue($value);
        }, $template) ?? $template;
    }

    protected function replaceVariablesInArray(array $array, array $context): array
    {
        array_walk_recursive($array, function (&$value) use ($context) {
            if (is_string($value)) {
                $value = $this->replaceVariables($value, $context);
            }
        });

        return $array;
    }

    protected function containsPlaceholders(string $value): bool
    {
        return (bool) preg_match(self::TEMPLATE_PATTERN, $value);
    }

    protected function stringifyTemplateValue(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }
}
