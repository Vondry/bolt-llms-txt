<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\ServiceValueResolver;
use Symfony\Component\HttpKernel\DependencyInjection\RegisterControllerArgumentLocatorsPass;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Tomvondracek\LlmsTxt\Controller\LlmsTxtController;
use Tomvondracek\LlmsTxt\EventListener\ContentChangeListener;
use Tomvondracek\LlmsTxt\LlmsTxtCache;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;
use Tomvondracek\LlmsTxt\SiteUrl;
use Twig\Environment;

/**
 * Guards the registrations: what Bolt's generated config/services_bolt.yaml does
 * with the extension's classes, and config/services.yaml and config/routes.yaml,
 * which `extensions:configure` copies into the project.
 */
final class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-llms-txt';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llms-txt-services-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTwigNamespaceIsRegisteredForTheShippedTemplates(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');
        self::assertIsArray($config);

        self::assertSame([
            'paths' => [
                self::VENDOR_PATH . '/templates' => 'llms-txt',
            ],
        ], $config['twig'] ?? null);
        self::assertStringStartsWith('@llms-txt/', LlmsTxtController::SHIPPED_TEMPLATE);
        self::assertFileExists(dirname(__DIR__) . '/templates/' . mb_substr(LlmsTxtController::SHIPPED_TEMPLATE, mb_strlen('@llms-txt/')));
    }

    public function testRoutesLoadTheControllerDirectory(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__) . '/config/routes.yaml');
        self::assertIsArray($routes);

        self::assertSame(
            [
                'resource' => '../../vendor/tomvondracek/bolt-llms-txt/src/Controller/',
                'type' => 'attribute',
            ],
            $routes['llms_txt'] ?? null
        );
    }

    public function testRouteServesGetAndHeadOfLlmsTxt(): void
    {
        $attributes = (new ReflectionMethod(LlmsTxtController::class, '__invoke'))->getAttributes(Route::class);
        self::assertCount(1, $attributes);
        $route = $attributes[0]->newInstance();

        self::assertSame('/llms.txt', $route->path);
        self::assertSame(LlmsTxtController::ROUTE, $route->name);
        self::assertSame([Request::METHOD_GET, Request::METHOD_HEAD], $route->methods);
    }

    /**
     * Right after `composer require`, Bolt has added the extension to
     * config/services_bolt.yaml, but `extensions:configure` (which needs a working
     * container itself) has not copied config/services.yaml yet.
     */
    public function testContainerCompilesBeforeExtensionsConfigure(): void
    {
        $container = $this->compile(false);

        $this->assertControllerIsAutowired($container);
    }

    public function testContainerCompilesAfterExtensionsConfigure(): void
    {
        $container = $this->compile(true);

        $this->assertControllerIsAutowired($container);
    }

    public function testContentChangeListenerListensToDoctrineFlushes(): void
    {
        $definition = $this->compile(true)->getDefinition(ContentChangeListener::class);

        self::assertSame([[
            'event' => 'onFlush',
        ], [
            'event' => 'postFlush',
        ]], $definition->getTag('doctrine.event_listener'));
        $arguments = $definition->getArguments();
        self::assertCount(1, $arguments);
        self::assertInstanceOf(Reference::class, $arguments[0]);
        self::assertSame(LlmsTxtCache::class, (string) $arguments[0]);
    }

    private function compile(bool $configured): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', dirname(__DIR__));
        // Bolt's parameters, not bound for extension classes.
        $container->setParameter('locale', 'cs');
        $container->setParameter('locales_array', ['cs', 'en']);

        // Services of FrameworkBundle, SecurityBundle, TwigBundle and Bolt.
        foreach ([ContainerInterface::class, Config::class, Environment::class, Canonical::class, RequestStack::class, LocaleSwitcher::class, TokenStorageInterface::class, ContainerBagInterface::class, ClockInterface::class, TagAwareCacheInterface::class] as $service) {
            $container->register($service)
                ->setSynthetic(true);
        }
        $container->registerForAutoconfiguration(AbstractController::class)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber');
        $container->register('argument_resolver.service', ServiceValueResolver::class)
            ->addArgument(null);
        $container->addCompilerPass(new RegisterControllerArgumentLocatorsPass());

        // What Bolt's ExtensionCompilerPass writes to config/services_bolt.yaml.
        $src = dirname(__DIR__) . '/src';
        file_put_contents($this->dir . '/services_bolt.yaml', Yaml::dump([
            'services' => [
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                    'bind' => [],
                ],
                'Tomvondracek\LlmsTxt\\' => [
                    'resource' => $src . '/*',
                    'exclude' => $src . '/{Entity,Exception}',
                ],
            ],
        ], 4, 2));
        (new YamlFileLoader($container, new FileLocator($this->dir)))->load('services_bolt.yaml');

        if ($configured) {
            // config/services.yaml also configures Twig, which isn't part of this container.
            $container->registerExtension(new class() extends Extension {
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return 'twig';
                }
            });
            (new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . '/config')))->load('services.yaml');
        }

        // Controllers are public in a project, so errors in them surface when compiling;
        // DoctrineBundle makes its listeners available the same way.
        $container->getDefinition(LlmsTxtController::class)
            ->setPublic(true);
        $container->getDefinition(ContentChangeListener::class)
            ->setPublic(true);
        $container->compile(true);

        return $container;
    }

    private function assertControllerIsAutowired(ContainerBuilder $container): void
    {
        $arguments = $container->getDefinition(LlmsTxtController::class)->getArguments();

        self::assertEquals(
            [Config::class, Environment::class, LlmsTxtConfigLoader::class, LocaleSwitcher::class, TokenStorageInterface::class, ContainerBagInterface::class, SiteUrl::class, ClockInterface::class, LlmsTxtCache::class],
            // Services used once are inlined as their definition.
            array_map(static fn (mixed $argument): ?string => match (true) {
                $argument instanceof Reference => (string) $argument,
                $argument instanceof Definition => $argument->getClass(),
                default => get_debug_type($argument),
            }, $arguments)
        );
    }
}
