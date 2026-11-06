<?php

namespace App\Notifications\Concerns;

/**
 * Translation placeholders are substituted raw, and a line wrapped in
 * HtmlString skips Blade escaping before the mail template parses it as
 * Markdown. User-controlled values (names, user agents) would otherwise inject
 * markup or links into mail sent from the platform's own address.
 */
trait EscapesMailPlaceholders
{
    /**
     * @param  array<string, string|int|float|null>  $replace
     * @return array<string, string>
     */
    protected function escapeForMail(array $replace): array
    {
        return array_map(fn ($value): string => $this->escapeMarkdown(e((string) $value)), $replace);
    }

    /**
     * For values Blade already escapes ({{ }}), such as greetings: only the
     * Markdown link syntax is left to neutralise.
     */
    protected function escapeMarkdown(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '[' => '\[']);
    }
}
