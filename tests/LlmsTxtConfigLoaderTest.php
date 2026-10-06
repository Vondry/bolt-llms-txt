<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;

final class LlmsTxtConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llms-txt-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFallsBackToShippedDefaults(): void
    {
        self::assertSame(3600, $this->loader()->load()->maxAge);
    }

    public function testProjectConfigAndLocalOverride(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "max_age: 600\nlocale: en\n");
        file_put_contents($this->dir . '/tomvondracek-llmstxt_local.yaml', "max_age: 0\n");

        $config = $this->loader()->load();

        self::assertSame(0, $config->maxAge);
        self::assertSame('en', $config->locale);
    }

    public function testReadsChangesOnTheNextLoad(): void
    {
        $loader = $this->loader();
        self::assertSame(3600, $loader->load()->maxAge);

        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "max_age: 60\n");

        self::assertSame(60, $loader->load()->maxAge);
    }

    public function testLocalOverrideAppliesOnTopOfDefaults(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt_local.yaml', "enabled: false\n");

        self::assertFalse($this->loader()->load()->enabled);
    }

    public function testMalformedYamlIsAnError(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "max_age: [\n");

        $this->expectException(ParseException::class);

        $this->loader()->load();
    }

    public function testParseFilesSkipsMissingAndNonArrayFiles(): void
    {
        file_put_contents($this->dir . '/scalar.yaml', "just a string\n");
        file_put_contents($this->dir . '/a.yaml', "a: 1\nb: 1\n");
        file_put_contents($this->dir . '/b.yaml', "b: 2\n");

        self::assertSame(
            [
                'a' => 1,
                'b' => 2,
            ],
            LlmsTxtConfigLoader::parseFiles([$this->dir . '/missing.yaml', $this->dir . '/scalar.yaml', $this->dir . '/a.yaml', $this->dir . '/b.yaml'])
        );
    }

    private function loader(): LlmsTxtConfigLoader
    {
        $boltConfig = self::createStub(Config::class);
        $boltConfig->method('getPath')
            ->willReturnCallback(fn (string $name): string => $name === 'extensions_config' ? $this->dir : '/nowhere');

        return new LlmsTxtConfigLoader($boltConfig);
    }
}
