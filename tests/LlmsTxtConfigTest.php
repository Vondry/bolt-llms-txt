<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\LlmsTxt\LlmsTxtConfig;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;

final class LlmsTxtConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = LlmsTxtConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('llms.txt.twig', $config->template);
        self::assertNull($config->locale);
        self::assertSame(3600, $config->maxAge);
    }

    public function testShippedConfigFileMatchesTheDefaults(): void
    {
        $shipped = Yaml::parseFile(LlmsTxtConfigLoader::defaultConfigFile());
        self::assertIsArray($shipped);

        self::assertEquals(LlmsTxtConfig::fromArray([]), LlmsTxtConfig::fromArray($shipped));
    }

    public function testValues(): void
    {
        $config = LlmsTxtConfig::fromArray([
            'enabled' => false,
            'template' => ' static-pages/llms.txt.twig ',
            'locale' => 'en',
            'max_age' => 600,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('static-pages/llms.txt.twig', $config->template);
        self::assertSame('en', $config->locale);
        self::assertSame(600, $config->maxAge);
    }

    public function testEmptyValuesMeanTheDefault(): void
    {
        $config = LlmsTxtConfig::fromArray([
            'enabled' => null,
            'template' => '   ',
            'locale' => '',
            'max_age' => null,
        ]);

        self::assertEquals(LlmsTxtConfig::fromArray([]), $config);
    }

    public function testMaxAgeAcceptsZeroAndWholeNumberStrings(): void
    {
        self::assertSame(0, LlmsTxtConfig::fromArray([
            'max_age' => 0,
        ])->maxAge);
        self::assertSame(120, LlmsTxtConfig::fromArray([
            'max_age' => '120',
        ])->maxAge);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown key' => [[
            'max-age' => 60,
        ], 'Unknown llms.txt config key(s) "max-age"'];
        yield 'enabled as a string' => [[
            'enabled' => 'no',
        ], 'The llms.txt `enabled` must be true or false, \'no\' given.'];
        yield 'template as a number' => [[
            'template' => 123,
        ], 'The llms.txt `template` must be a string, 123 given.'];
        yield 'locale as a list' => [[
            'locale' => ['en'],
        ], 'The llms.txt `locale` must be a string, array given.'];
        yield 'max_age with a unit' => [[
            'max_age' => '1h',
        ], 'The llms.txt `max_age` must be a whole number of seconds, 0 or more, \'1h\' given.'];
        yield 'max_age negative' => [[
            'max_age' => -5,
        ], '-5 given'];
        yield 'max_age fractional' => [[
            'max_age' => 1.5,
        ], '1.5 given'];
        yield 'max_age as a boolean' => [[
            'max_age' => true,
        ], 'true given'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRejected(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        LlmsTxtConfig::fromArray($config);
    }

    public function testRouteDefaultsOverrideTheConfig(): void
    {
        $config = LlmsTxtConfig::fromArray([
            'locale' => 'cs',
            'max_age' => 600,
        ])->withRouteDefaults([
            '_controller' => 'ignored',
            'template' => 'llms-full.txt.twig',
            'max_age' => 60,
        ]);

        self::assertSame('llms-full.txt.twig', $config->template);
        self::assertSame('cs', $config->locale, 'kept when the route sets none');
        self::assertSame(60, $config->maxAge);
    }

    public function testRouteDefaultsSetTheLocaleButCannotDisable(): void
    {
        $config = LlmsTxtConfig::fromArray([])->withRouteDefaults([
            'locale' => 'en',
            'enabled' => false,
        ]);

        self::assertSame('en', $config->locale);
        self::assertTrue($config->enabled, 'the switch belongs to the config');
    }

    public function testInvalidRouteDefaultsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LlmsTxtConfig::fromArray([])->withRouteDefaults([
            'max_age' => 'soon',
        ]);
    }
}
