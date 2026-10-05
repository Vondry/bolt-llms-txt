<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\LlmsTxt\Controller\LlmsTxtController;

/**
 * Guards the registrations that only exist in config/services.yaml and
 * config/routes.yaml, which `extensions:configure` copies into the project.
 */
final class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-llms-txt';

    public function testTwigNamespaceIsRegisteredForTheShippedTemplates(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');

        self::assertSame([self::VENDOR_PATH . '/templates' => 'llms-txt'], $config['twig']['paths'] ?? null);
        self::assertSame('@llms-txt/llms.txt.twig', LlmsTxtController::SHIPPED_TEMPLATE);
        self::assertFileExists(dirname(__DIR__) . '/templates/llms.txt.twig');
    }

    public function testRoutesLoadTheControllerDirectory(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__) . '/config/routes.yaml');

        self::assertSame(
            [
                'resource' => '../../vendor/tomvondracek/bolt-llms-txt/src/Controller/',
                'type' => 'attribute',
            ],
            $routes['llms_txt'] ?? null
        );
    }
}
