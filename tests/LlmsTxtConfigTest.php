<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

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

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $config = LlmsTxtConfig::fromArray([
            'enabled' => 'no',
            'template' => '   ',
            'locale' => '',
            'max_age' => 'an hour',
        ]);

        self::assertFalse($config->enabled, '"no" is a YAML-ish false');
        self::assertSame('llms.txt.twig', $config->template);
        self::assertNull($config->locale);
        self::assertSame(3600, $config->maxAge);
    }

    public function testMaxAgeIsNeverNegativeAndAcceptsZero(): void
    {
        self::assertSame(0, LlmsTxtConfig::fromArray([
            'max_age' => -5,
        ])->maxAge);
        self::assertSame(0, LlmsTxtConfig::fromArray([
            'max_age' => 0,
        ])->maxAge);
        self::assertSame(120, LlmsTxtConfig::fromArray([
            'max_age' => '120',
        ])->maxAge);
        self::assertSame(0, LlmsTxtConfig::fromArray([
            'max_age' => '-5',
        ])->maxAge, 'a string is read like a number');
        self::assertSame(3600, LlmsTxtConfig::fromArray([
            'max_age' => 1.5,
        ])->maxAge);
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
}
