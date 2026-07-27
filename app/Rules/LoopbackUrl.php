<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restricts a URL to the developer's own machine.
 *
 * Used for field-plugin dev servers: the iframe is loaded by the browser, so a
 * loopback host still reaches the developer's local bundler, while a remote
 * host would let anyone who can edit a plugin inject third-party script into
 * every editor session in the space.
 */
class LoopbackUrl implements ValidationRule
{
    private const HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a local development URL.');

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(strtolower($host), self::HOSTS, true)) {
            $fail('The :attribute must point at localhost or 127.0.0.1.');
        }
    }
}
