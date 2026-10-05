<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

/**
 * Typed, validated view of `config/extensions/tomvondracek-llmstxt.yaml`.
 */
final readonly class LlmsTxtConfig
{
    public const DEFAULT_TEMPLATE = 'llms.txt.twig';
    public const DEFAULT_MAX_AGE = 3600;

    private function __construct(
        public bool $enabled,
        public string $template,
        /** Null = the site's default locale. */
        public ?string $locale,
        public int $maxAge,
    ) {
    }

    /**
     * @param array<array-key, mixed> $config raw (possibly partial) YAML config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: self::bool($config['enabled'] ?? true),
            template: self::string($config['template'] ?? null) ?? self::DEFAULT_TEMPLATE,
            locale: self::string($config['locale'] ?? null),
            maxAge: self::maxAge($config['max_age'] ?? null) ?? self::DEFAULT_MAX_AGE,
        );
    }

    /**
     * A copy with the values a route sets in its `defaults` (`template`, `locale`,
     * `max_age`), so one project can serve more files, such as /llms-full.txt,
     * from the same controller.
     *
     * @param array<array-key, mixed> $defaults
     */
    public function withRouteDefaults(array $defaults): self
    {
        return new self(
            enabled: $this->enabled,
            template: self::string($defaults['template'] ?? null) ?? $this->template,
            locale: self::string($defaults['locale'] ?? null) ?? $this->locale,
            maxAge: self::maxAge($defaults['max_age'] ?? null) ?? $this->maxAge,
        );
    }

    private static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    /**
     * Seconds (negative values count as 0), or null when the value is missing or
     * not a whole number.
     */
    private static function maxAge(mixed $value): ?int
    {
        $seconds = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($seconds) ? max(0, $seconds) : null;
    }
}
