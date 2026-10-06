<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Controller;

use Bolt\Configuration\Config;
use Bolt\Extension\ExtensionController;
use DateTimeZone;
use Exception;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Tomvondracek\LlmsTxt\LlmsTxtCache;
use Tomvondracek\LlmsTxt\LlmsTxtConfig;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;
use Tomvondracek\LlmsTxt\LlmsTxtResponse;
use Tomvondracek\LlmsTxt\SiteUrl;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Serves /llms.txt (https://llmstxt.org), rendered from live CMS content by a
 * Twig template of the theme.
 *
 * The same controller can serve more files: a project route that points here can
 * set `template`, `locale` and `max_age` in its `defaults`, e.g. for /llms-full.txt.
 *
 * Every dependency is a service, so Bolt's generated config/services_bolt.yaml
 * (autowiring without the project's binds) registers the controller as it is.
 * Not in Bolt's frontend zone, so Bolt's widgets don't inject HTML into the text.
 */
final class LlmsTxtController extends ExtensionController
{
    public const ROUTE = 'llms_txt';

    /**
     * Generic template shipped with the extension, used while the theme has no
     * template of the default name.
     */
    public const SHIPPED_TEMPLATE = '@llms-txt/llms.txt.twig';

    /**
     * Request attribute that marks a request as served here, for
     * {@see \Tomvondracek\LlmsTxt\EventSubscriber\LlmsTxtResponseSubscriber}.
     */
    public const REQUEST_ATTRIBUTE = '_llms_txt';

    public function __construct(
        Config $config,
        private readonly Environment $twig,
        private readonly LlmsTxtConfigLoader $configLoader,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ContainerBagInterface $parameters,
        private readonly SiteUrl $siteUrl,
        private readonly ClockInterface $clock,
        private readonly LlmsTxtCache $cache,
    ) {
        parent::__construct($config);
    }

    #[Route('/llms.txt', name: self::ROUTE, methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function __invoke(Request $request): Response
    {
        $routeParams = $request->attributes->get('_route_params');
        $config = $this->configLoader->load()
            ->withRouteDefaults(is_array($routeParams) ? $routeParams : []);

        if (! $config->enabled) {
            throw $this->createNotFoundException('llms.txt is disabled.');
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        $locale = $this->locale($config);

        // Set the locale here rather than with a `_locale` route default: Bolt's
        // LocaleSubscriber stores a routed `_locale` in the session, and the
        // response would no longer be cacheable. Records pick up the request locale
        // when they are loaded, which has not happened yet at this point.
        $request->setLocale($locale);

        // "Today" in the site's timezone (config/bolt/config.yaml), not PHP's default.
        $now = $this->clock->now()
            ->setTimezone($this->timezone());

        $templates = $this->templates($config);
        $context = [
            'today' => $now->format('Y-m-d'),
            'now' => $now,
            'baseUrl' => $this->siteUrl->homepage() ?? $request->getSchemeAndHttpHost() . $request->getBasePath(),
            'locale' => $locale,
        ];

        // The translator (`__()`, `|trans`) and the Intl filters follow the
        // request's original locale; switch them for the rendering only.
        $render = fn (): string => $this->localeSwitcher->runWithLocale(
            $locale,
            fn (): string => $this->renderTemplate($templates, $context)
        );

        // Templates must not depend on who is viewing; should one do so anyway, a
        // logged-in user's copy must not end up in a shared cache.
        $shared = $this->tokenStorage->getToken()?->getUser() === null;

        // Logged-in users (editors checking their changes) and debug mode (template
        // changes) always get a fresh rendering.
        $body = $shared && $this->parameters->get('kernel.debug') !== true
            ? $this->cache->get([
                $templates,
                $locale,
                $context['today'],
                $context['baseUrl'],
                $request->getSchemeAndHttpHost() . $request->getBasePath(),
            ], $config->maxAge, $render)
            : $render();

        return LlmsTxtResponse::create($body, $config->maxAge, $request, $shared);
    }

    /**
     * The configured locale, or the site's default one; one of the site's locales.
     */
    private function locale(LlmsTxtConfig $config): string
    {
        $default = $this->parameters->get('locale');
        // Kernel::setBoltParameters() splits `app_locales` on `|` without trimming.
        $locales = [];
        foreach ((array) $this->parameters->get('locales_array') as $siteLocale) {
            if (is_string($siteLocale)) {
                $locales[] = mb_trim($siteLocale);
            }
        }
        $locale = $config->locale ?? (is_string($default) ? $default : '');

        if (! in_array($locale, $locales, true)) {
            throw new LogicException(sprintf(
                'The llms.txt locale "%s" is not one of the site\'s locales (%s). Check `locale` in config/extensions/%s.yaml and in the route defaults.',
                $locale,
                implode(', ', $locales),
                LlmsTxtConfigLoader::CONFIG_BASENAME
            ));
        }

        return $locale;
    }

    private function timezone(): DateTimeZone
    {
        $timezone = $this->boltConfig->get('general/timezone');
        if (! is_string($timezone) || $timezone === '') {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($timezone);
        } catch (Exception $exception) {
            throw new LogicException(sprintf('The timezone "%s" (`general/timezone` in config/bolt/config.yaml) is not valid.', $timezone), 0, $exception);
        }
    }

    /**
     * Renders the first template that exists, looked up in the theme first, the
     * way Bolt's TwigAwareController::renderTemplate() does.
     *
     * @param list<string> $templates
     * @param array<string, mixed> $context
     */
    private function renderTemplate(array $templates, array $context): string
    {
        $themePath = $this->boltConfig->getPath('theme');
        $templateDirectory = $this->boltConfig->get('theme/template_directory');
        if (is_string($templateDirectory) && $templateDirectory !== '') {
            $themePath .= DIRECTORY_SEPARATOR . $templateDirectory;
        }

        $loader = $this->twig->getLoader();
        foreach ($loader instanceof ChainLoader ? $loader->getLoaders() : [$loader] as $filesystemLoader) {
            // Once per process: long-running runtimes keep the loader between requests.
            if ($filesystemLoader instanceof FilesystemLoader && ! in_array($themePath, $filesystemLoader->getPaths(), true)) {
                $filesystemLoader->prependPath($themePath);
            }
        }

        return $this->twig->resolveTemplate($templates)
            ->render($context);
    }

    /**
     * The configured template; for the default name, falling back to the shipped
     * one. A template the project names explicitly must exist.
     *
     * @return list<string>
     */
    private function templates(LlmsTxtConfig $config): array
    {
        return $config->template === LlmsTxtConfig::DEFAULT_TEMPLATE
            ? [$config->template, self::SHIPPED_TEMPLATE]
            : [$config->template];
    }
}
