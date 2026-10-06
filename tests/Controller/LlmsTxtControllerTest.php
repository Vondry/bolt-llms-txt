<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\Controller;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
use Tomvondracek\LlmsTxt\SiteUrl;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

final class LlmsTxtControllerTest extends TestCase
{
    private string $dir;
    private Translator $translator;

    /** @var array<string, mixed> what Bolt's Config::get() returns */
    private array $boltConfig = [
        'general/timezone' => 'Europe/Prague',
    ];

    /** @var list<string> */
    private array $locales = ['cs', 'en'];

    private ?string $canonicalHomepage = null;
    private MockClock $clock;

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

        $this->clock = new MockClock('2026-03-01 12:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRendersTheThemeTemplate(): void
    {
        $response = $this->invoke([
            'llms.txt.twig' => "# {{ 'hello'|trans }}\n\n\n\n> {{ locale }} {{ today }} {{ now.format('H:i e') }} {{ baseUrl }}",
            '@llms-txt/llms.txt.twig' => '# Shipped',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame("# Ahoj\n\n> cs 2026-03-01 13:00 Europe/Prague https://example.com\n", $response->getContent(), 'normalized, in the default locale and the site timezone');
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertNotNull($response->getEtag());
    }

    public function testTodayIsTheDateInTheSiteTimezone(): void
    {
        $this->clock = new MockClock('2026-01-01 23:30:00', 'UTC');

        $response = $this->invoke([
            'llms.txt.twig' => '{{ today }}',
        ]);

        self::assertSame("2026-01-02\n", $response->getContent());
    }

    public function testTimezoneDefaultsToUtc(): void
    {
        $this->boltConfig = [];

        $response = $this->invoke([
            'llms.txt.twig' => "{{ now.format('e') }}",
        ]);

        self::assertSame("UTC\n", $response->getContent());
    }

    public function testInvalidTimezoneIsAConfigurationError(): void
    {
        $this->boltConfig = [
            'general/timezone' => 'Europe/Kuřim',
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The timezone "Europe/Kuřim" (`general/timezone` in config/bolt/config.yaml) is not valid.');

        $this->invoke([
            'llms.txt.twig' => '# Site',
        ]);
    }

    public function testBaseUrlIsTheCanonicalHomepage(): void
    {
        $this->canonicalHomepage = 'https://canonical.example/';

        $response = $this->invoke([
            'llms.txt.twig' => '{{ baseUrl }}',
        ]);

        self::assertSame("https://canonical.example\n", $response->getContent());
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

    public function testFindsTheTemplateInTheTheme(): void
    {
        mkdir($this->dir . '/theme/templates', 0o777, true);
        file_put_contents($this->dir . '/theme/templates/llms.txt.twig', '# From the theme');
        $this->boltConfig['theme/template_directory'] = 'templates';
        $filesystemLoader = new FilesystemLoader();
        $loader = new ChainLoader([
            new ArrayLoader([
                '@llms-txt/llms.txt.twig' => '# Shipped',
            ]),
            $filesystemLoader,
        ]);

        self::assertSame("# From the theme\n", $this->invoke([], null, null, $loader)->getContent());
        self::assertSame("# From the theme\n", $this->invoke([], null, null, $loader)->getContent());
        self::assertSame([$this->dir . '/theme/templates'], $filesystemLoader->getPaths(), 'added once, not on every request');
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
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "locale: cs\n");
        $request = Request::create('https://example.com/llms-full.txt');
        $request->attributes->set('_route_params', [
            'template' => 'llms-full.txt.twig',
            'locale' => 'en',
            'max_age' => 0,
        ]);

        $response = $this->invoke([
            'llms.txt.twig' => '# Short',
            'llms-full.txt.twig' => '# Full ({{ locale }})',
        ], $request);

        self::assertSame("# Full (en)\n", $response->getContent());
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

    public function testInvalidConfigIsAConfigurationError(): void
    {
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "max_age: 1h\n");

        $this->expectException(InvalidArgumentException::class);

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

    public function testRevalidationWithTheSameEtagIsNotModified(): void
    {
        $templates = [
            'llms.txt.twig' => '# Site',
        ];
        $etag = (string) $this->invoke($templates)->getEtag();
        $request = Request::create('https://example.com/llms.txt');
        $request->headers->set('If-None-Match', $etag);

        $response = $this->invoke($templates, $request);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testHeadRequestHasHeadersButNoBody(): void
    {
        $request = Request::create('https://example.com/llms.txt', Request::METHOD_HEAD);

        $response = $this->invoke([
            'llms.txt.twig' => '# Site',
        ], $request)->prepare($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertNotNull($response->getEtag());
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

    public function testSiteLocalesAreTrimmed(): void
    {
        // `app_locales: "cs| en"` in the project's .env
        $this->locales = ['cs', ' en'];
        file_put_contents($this->dir . '/tomvondracek-llmstxt.yaml', "locale: en\n");

        $response = $this->invoke([
            'llms.txt.twig' => '{{ locale }}',
        ]);

        self::assertSame("en\n", $response->getContent());
    }

    /**
     * @param array<string, string> $templates
     */
    private function invoke(array $templates, ?Request $request = null, ?TokenStorage $tokenStorage = null, ?LoaderInterface $loader = null): Response
    {
        $request ??= Request::create('https://example.com/llms.txt');

        $twig = new Environment($loader ?? new ArrayLoader($templates));
        $twig->addExtension(new TranslationExtension($this->translator));

        $boltConfig = self::createStub(Config::class);
        $boltConfig->method('getPath')
            ->willReturnCallback(fn (string $name): string => $name === 'theme' ? $this->dir . '/theme' : $this->dir);
        $boltConfig->method('get')
            ->willReturnCallback(fn (string $path): mixed => $this->boltConfig[$path] ?? null);

        $canonical = self::createStub(Canonical::class);
        $canonical->method('get')
            ->willReturn($this->canonicalHomepage);
        $requestStack = new RequestStack([$request]);

        $parameters = new ContainerBag(new Container(new ParameterBag([
            'locale' => 'cs',
            'locales_array' => $this->locales,
        ])));

        $controller = new LlmsTxtController(
            $boltConfig,
            $twig,
            new LlmsTxtConfigLoader($boltConfig),
            new LocaleSwitcher('cs', [$this->translator]),
            $tokenStorage ?? new TokenStorage(),
            $parameters,
            new SiteUrl($canonical, $requestStack),
            $this->clock,
        );

        return $controller($request);
    }
}
