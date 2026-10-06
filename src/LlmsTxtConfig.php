<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use InvalidArgumentException;

/**
 * Typed, validated view of `config/extensions/tomvondracek-llmstxt.yaml`.
 *
 * A missing or empty value means the default; a value of the wrong type or an
 * unknown key is a configuration error, so a typo can't go unnoticed.
 */
final readonly class LlmsTxtConfig
{
    public const DEFAULT_TEMPLATE = 'llms.txt.twig';
    public const DEFAULT_MAX_AGE = 3600;
    private const KEYS = ['enabled', 'template', 'locale', 'max_age'];

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
     *
     * @throws InvalidArgumentException for unknown keys and invalid values
     */
    public static function fromArray(array $config): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($config)), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown llms.txt config key(s) "%s"; the known ones are "%s". Check config/extensions/%s.yaml.',
                implode('", "', $unknown),
                implode('", "', self::KEYS),
                LlmsTxtConfigLoader::CONFIG_BASENAME
            ));
        }

        return new self(
            enabled: self::bool('enabled', $config['enabled'] ?? null) ?? true,
            template: self::string('template', $config['template'] ?? null) ?? self::DEFAULT_TEMPLATE,
            locale: self::string('locale', $config['locale'] ?? null),
            maxAge: self::maxAge($config['max_age'] ?? null) ?? self::DEFAULT_MAX_AGE,
        );
    }

    /**
     * A copy with the values a route sets in its `defaults` (`template`, `locale`,
     * `max_age`), so one project can serve more files, such as /llms-full.txt,
     * from the same controller. Other route parameters are ignored, and so is
     * `enabled`: the switch belongs to the config.
     *
     * @param array<array-key, mixed> $defaults
     *
     * @throws InvalidArgumentException for invalid values
     */
    public function withRouteDefaults(array $defaults): self
    {
        return new self(
            enabled: $this->enabled,
            template: self::string('template', $defaults['template'] ?? null) ?? $this->template,
            locale: self::string('locale', $defaults['locale'] ?? null) ?? $this->locale,
            maxAge: self::maxAge($defaults['max_age'] ?? null) ?? $this->maxAge,
        );
    }

    private static function bool(string $key, mixed $value): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        throw self::invalid($key, 'true or false', $value);
    }

    private static function string(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::invalid($key, 'a string', $value);
        }

        return mb_trim($value) === '' ? null : mb_trim($value);
    }

    /**
     * Whole seconds, also as a string (route defaults in XML or annotations are strings).
     */
    private static function maxAge(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $seconds = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if (! is_int($seconds) || $seconds < 0) {
            throw self::invalid('max_age', 'a whole number of seconds, 0 or more', $value);
        }

        return $seconds;
    }

    private static function invalid(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The llms.txt `%s` must be %s, %s given. Check config/extensions/%s.yaml and the route defaults.',
            $key,
            $expected,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            LlmsTxtConfigLoader::CONFIG_BASENAME
        ));
    }
}
