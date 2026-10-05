<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\Controller;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use Bolt\TemplateChooser;
use Bolt\Twig\CommonExtension;
use Bolt\Utils\Sanitiser;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\Loader\ArrayLoader as TranslationArrayLoader;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;
use Tomvondracek\LlmsTxt\Controller\LlmsTxtController;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;

final class LlmsTxtControllerTest extends TestCase
{
    private string $dir;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llms-txt-controller-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->translator = new Translator('cs');
        $this->translator->addLoader('array', new TranslationArrayLoader());
        $this->translator->addResource('array', [
            'hello' => 'Ahoj',
        ], 'cs');
        $this->translator->addResource('array', [
            'hello' => 'Hello',
        ], 'en');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRendersTheThemeTemplate(): void
    {
        $response = $this->invoke([
            'llms.txt.twig' => "# {{ 'hello'|trans }}\n\n\n\n> {{ locale }} {{ today|length }} {{ baseUrl }}",
            '@llms-txt/llms.txt.twig' => '# Shipped',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame("# Ahoj\n\n> cs 10 https://example.com\n", $response->getContent(), 'normalized, in the default locale');
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertNotNull($response->getEtag());
    }

    public function testFallsBackToTheShippedTemplate(): void
    {
        $response = $this->invoke([
            '@llms-txt/llms.txt.twig' => '# Shipped',
        ]);

        self::assertSame("# Shipped\n", $response->getContent());
    }

    public function testExplicitTemplateMustExist(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "template: static-pages/llms.txt.twig\n");

        $this->expectException(LoaderError::class);

        $this->invoke([
            '@llms-txt/llms.txt.twig' => '# Shipped',
        ]);
    }

    public function testConfiguredLocaleAndMaxAge(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "locale: en\nmax_age: 60\n");
        $request = Request::create('https://example.com/llms.txt');

        $response = $this->invoke([
            'llms.txt.twig' => "# {{ 'hello'|trans }} ({{ locale }})",
        ], $request);

        self::assertSame("# Hello (en)\n", $response->getContent());
        self::assertSame('en', $request->getLocale(), 'records are loaded in the request locale');
        self::assertSame('cs', $this->translator->getLocale(), 'translator restored after rendering');
        self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testRouteDefaultsOverrideTheConfig(): void
    {
        $request = Request::create('https://example.com/llms-full.txt');
        $request->attributes->set('_route_params', [
            'template' => 'llms-full.txt.twig',
            'max_age' => 0,
        ]);

        $response = $this->invoke([
            'llms.txt.twig' => '# Short',
            'llms-full.txt.twig' => '# Full',
        ], $request);

        self::assertSame("# Full\n", $response->getContent());
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testDisabledIsNotFound(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "enabled: false\n");

        $this->expectException(NotFoundHttpException::class);

        $this->invoke([
            'llms.txt.twig' => '# Site',
        ]);
    }

    public function testMarksTheRequestForTheResponseSubscriber(): void
    {
        $request = Request::create('https://example.com/llms.txt');

        $this->invoke([
            'llms.txt.twig' => '# Site',
        ], $request);

        self::assertTrue($request->attributes->get(LlmsTxtController::REQUEST_ATTRIBUTE));
    }

    public function testLoggedInUserGetsAPrivateResponse(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('editor', null), 'main'));

        $response = $this->invoke([
            'llms.txt.twig' => '# Site',
        ], null, $tokenStorage);

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testUnknownLocaleIsAConfigurationError(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "locale: de\n");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The llms.txt locale "de" is not one of the site\'s locales (cs, en).');

        $this->invoke([
            'llms.txt.twig' => '# Site',
        ]);
    }

    /**
     * Compiles the controller the way Bolt's generated config/services_bolt.yaml
     * registers extension classes: autowired and autoconfigured, without the
     * project's binds (such as `$defaultLocale`).
     */
    public function testControllerCanBeAutowiredWithoutProjectBinds(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('locale', 'cs');
        // AbstractController::setContainer() gets FrameworkBundle's service locator in a project.
        foreach ([ContainerInterface::class, Config::class, Environment::class, Packages::class, Canonical::class, Sanitiser::class, TemplateChooser::class, CommonExtension::class] as $service) {
            $container->register($service)
                ->setSynthetic(true);
        }
        $container->register(LlmsTxtController::class, LlmsTxtController::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->compile();

        $arguments = [];
        foreach ($container->getDefinition(LlmsTxtController::class)->getMethodCalls() as [$method, $methodArguments]) {
            $arguments[$method] = $methodArguments;
        }

        self::assertSame('cs', $arguments['setAutowire'][6] ?? null, '$defaultLocale from the `locale` parameter');
    }

    /**
     * @param array<string, string> $templates
     */
    private function invoke(array $templates, ?Request $request = null, ?TokenStorage $tokenStorage = null): Response
    {
        $twig = new Environment(new ArrayLoader($templates));
        $twig->addExtension(new TranslationExtension($this->translator));

        $boltConfig = $this->createStub(Config::class);
        $boltConfig->method('getPath')
            ->willReturnCallback(fn (string $name): string => $this->dir);
        $boltConfig->method('get')
            ->willReturnCallback(static fn (string $path): ?string => [
                'general/timezone' => 'Europe/Prague',
                'general/theme' => 'test',
            ][$path] ?? null);

        $controller = new LlmsTxtController();
        $controller->setAutowire(
            $boltConfig,
            $twig,
            new Packages(new Package(new EmptyVersionStrategy())),
            $this->createStub(Canonical::class),
            $this->createStub(Sanitiser::class),
            $this->createStub(TemplateChooser::class),
            'cs',
            $this->createStub(CommonExtension::class),
        );

        return $controller(
            $request ?? Request::create('https://example.com/llms.txt'),
            new LlmsTxtConfigLoader($boltConfig),
            new LocaleSwitcher('cs', [$this->translator]),
            $tokenStorage ?? new TokenStorage(),
            ['cs', 'en'],
        );
    }
}
