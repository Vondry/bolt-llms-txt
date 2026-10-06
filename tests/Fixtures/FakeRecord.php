<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\Fixtures;

/**
 * Stands in for Bolt's Content in templates: the definition's fields in order, and
 * their values.
 */
final readonly class FakeRecord
{
    /**
     * @param array<string, array{string, string}> $fields name => [type, value], in definition order
     */
    public function __construct(
        public string $title,
        public string $link,
        private array $fields,
    ) {
    }

    /**
     * @return array{fields: array<string, array{type: string}>}
     */
    public function getDefinition(): array
    {
        return [
            'fields' => array_map(static fn (array $field): array => [
                'type' => $field[0],
            ], $this->fields),
        ];
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function getField(string $name): string
    {
        return $this->fields[$name][1] ?? '';
    }
}
