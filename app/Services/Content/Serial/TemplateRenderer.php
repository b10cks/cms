<?php

namespace App\Services\Content\Serial;

/**
 * Renders `{token}` / `{token:argument}` templates.
 *
 * Shared by serial formats and block slug patterns so there is exactly one
 * templating concept in the product. Deliberately not an expression language:
 * a fixed token registry has no evaluation surface to secure.
 */
class TemplateRenderer
{
    public const string TOKEN_PATTERN = '/\{([a-z_]+)(?::([^}]*))?\}/';

    /**
     * @var array<string, TokenResolver>
     */
    protected array $resolvers = [];

    /**
     * @param  iterable<TokenResolver>  $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        foreach ($resolvers as $resolver) {
            $this->register($resolver);
        }
    }

    public function register(TokenResolver $resolver): self
    {
        $this->resolvers[$resolver->token()] = $resolver;

        return $this;
    }

    /**
     * @return array<string, TokenResolver>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    public function render(string $template, SerialContext $context): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $matches) use ($context): string {
                $resolver = $this->resolvers[$matches[1]] ?? null;

                // Unknown tokens are rejected when the block schema is saved, so
                // reaching this branch means a schema predates the token or the
                // token was unregistered. Rendering the literal keeps the value
                // readable instead of silently dropping a prefix.
                if ($resolver === null) {
                    return $matches[0];
                }

                return $resolver->resolve($matches[2] ?? null, $context);
            },
            $template,
        );
    }

    /**
     * Validate a template without rendering it.
     *
     * @return array<int, string> human-readable problems, empty when valid
     */
    public function validate(string $template, bool $allowNumberTokens = true): array
    {
        $errors = [];

        if (trim($template) === '') {
            return ['The pattern may not be empty.'];
        }

        preg_match_all(self::TOKEN_PATTERN, $template, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            $errors[] = 'The pattern must contain at least one token.';
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $argument = $match[2] ?? null;
            $resolver = $this->resolvers[$name] ?? null;

            if ($resolver === null) {
                $errors[] = sprintf('`{%s}` is not a known token.', $name);

                continue;
            }

            if (! $allowNumberTokens && $resolver->requiresNumber()) {
                $errors[] = sprintf('`{%s}` can only be used in a serial format.', $name);
            }

            if ($resolver instanceof Tokens\DateToken
                && $argument !== null
                && $argument !== ''
                && ! preg_match(Tokens\DateToken::ALLOWED_FORMAT, $argument)
            ) {
                $errors[] = sprintf('`{date:%s}` is not a supported date format.', $argument);
            }
        }

        // A `{...}` that the token pattern did not match is a typo, not a literal.
        $stripped = preg_replace(self::TOKEN_PATTERN, '', $template);

        if (str_contains((string) $stripped, '{') || str_contains((string) $stripped, '}')) {
            $errors[] = 'The pattern contains a malformed token.';
        }

        return $errors;
    }

    /**
     * Whether the template draws a number, i.e. whether rendering it requires
     * an allocation.
     */
    public function requiresNumber(string $template): bool
    {
        preg_match_all(self::TOKEN_PATTERN, $template, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($this->resolvers[$match[1]] ?? null)?->requiresNumber()) {
                return true;
            }
        }

        return false;
    }
}
