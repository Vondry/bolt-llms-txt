<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Tomvondracek\LlmsTxt\Extension;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExtensionTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame('llms.txt', (new Extension())->getName());
        self::assertSame('llms-txt', (new Extension())->getSlug());
    }

    /**
     * Bolt's ConfigTrait (used by `extensions:configure --with-config`) and
     * LlmsTxtConfigLoader must agree on the config filename, or the project's
     * config file is never read.
     */
    public function testConfigFilenameMatchesTheLoader(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPath')
            ->willReturn('/project/config/extensions');

        $container = new Container();
        $container->set(Config::class, $config);

        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);

        $filenames = $extension->getConfigFilenames();

        self::assertSame(LlmsTxtConfigLoader::CONFIG_BASENAME . '.yaml', basename($filenames['main']));
        self::assertSame(LlmsTxtConfigLoader::CONFIG_BASENAME . '_local.yaml', basename($filenames['local']));
    }

    public function testInitializeRegistersTwigNamespace(): void
    {
        $loader = new FilesystemLoader();
        $container = new Container();
        $container->set('twig', new Environment($loader));

        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);
        $extension->initialize();

        self::assertContains(dirname(__DIR__) . '/templates', $loader->getPaths('llms-txt'));
    }
}
