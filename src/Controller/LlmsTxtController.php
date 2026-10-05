<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Controller;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use Bolt\Controller\Frontend\FrontendZoneInterface;
use Bolt\Controller\TwigAwareController;
use Bolt\TemplateChooser;
use Bolt\Twig\CommonExtension;
use Bolt\Utils\Sanitiser;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\Attribute\Required;
use Tomvondracek\LlmsTxt\LlmsTxtConfig;
use Tomvondracek\LlmsTxt\LlmsTxtConfigLoader;
use Tomvondracek\LlmsTxt\LlmsTxtResponse;
use Twig\Environment;

/**
 * Serves /llms.txt (https://llmstxt.org), rendered from live CMS content by a
 * Twig template of the theme.
 *
 * The same controller can serve more files: a project route that points here can
 * set `template`, `locale` and `max_age` in its `defaults`, e.g. for /llms-full.txt.
 */
class LlmsTxtController extends TwigAwareController implements FrontendZoneInterface
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

    /**
     * Only here to add #[Autowire] to `$defaultLocale`, a parameter that comes with
     * extending TwigAwareController, whose #[Required] setter must be fully
     * autowirable for every subclass. Bolt binds `$defaultLocale`
     * for the project's own services only, not in the generated
     * config/services_bolt.yaml that registers extension classes, so without this
     * override the container fails to compile.
     *
     * TODO: remove this method once https://github.com/bolt/core/pull/3815 (the
     * same attribute in TwigAwareController) is released, and raise the
     * `bolt/core` conflict in composer.json to exclude the Bolt versions without it.
     * LlmsTxtControllerTest::testControllerCanBeAutowiredWithoutProjectBinds keeps
     * guarding the autowiring after the refactor.
     */
    #[Required]
    public function setAutowire(
        Config $config,
        Environment $twig,
        Packages $packages,
        Canonical $canonical,
        Sanitiser $sanitiser,
        TemplateChooser $templateChooser,
        #[Autowire(param: 'locale')]
        string $defaultLocale,
        CommonExtension $commonExtension
    ): void {
        parent::setAutowire($config, $twig, $packages, $canonical, $sanitiser, $templateChooser, $defaultLocale, $commonExtension);
    }

    /**
     * @param list<string> $locales the site's locales (`app_locales`)
     */
    #[Route('/llms.txt', name: self::ROUTE, methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function __invoke(
        Request $request,
        LlmsTxtConfigLoader $configLoader,
        LocaleSwitcher $localeSwitcher,
        TokenStorageInterface $tokenStorage,
        #[Autowire(param: 'locales_array')]
        array $locales,
    ): Response {
        $routeParams = $request->attributes->get('_route_params');
        $config = $configLoader->load()
            ->withRouteDefaults(is_array($routeParams) ? $routeParams : []);

        if (! $config->enabled) {
            throw $this->createNotFoundException('llms.txt is disabled.');
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        $locale = $config->locale ?? $this->defaultLocale;
        if (! in_array($locale, $locales, true)) {
            throw new LogicException(sprintf(
                'The llms.txt locale "%s" is not one of the site\'s locales (%s). Check `locale` in config/extensions/%s.yaml and in the route defaults.',
                $locale,
                implode(', ', $locales),
                LlmsTxtConfigLoader::CONFIG_BASENAME
            ));
        }

        // Set the locale here rather than with a `_locale` route default: Bolt's
        // LocaleSubscriber stores a routed `_locale` in the session, and the
        // response would no longer be cacheable. Records pick up the request locale
        // when they are loaded, which has not happened yet at this point.
        $request->setLocale($locale);

        // "Today" in the site's timezone (config/bolt/config.yaml), not PHP's default (UTC).
        $timezone = $this->config->get('general/timezone');
        $now = new DateTimeImmutable('now', new DateTimeZone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC'));

        $context = [
            'today' => $now->format('Y-m-d'),
            'now' => $now,
            'baseUrl' => $this->baseUrl($request),
            'locale' => $locale,
        ];

        // The translator (`__()`, `|trans`) and the Intl filters follow the
        // request's original locale; switch them for the rendering only.
        $body = $localeSwitcher->runWithLocale(
            $locale,
            fn (): string => $this->renderTemplate($this->templates($config), $context)
        );

        // Templates must not depend on who is viewing; should one do so anyway, a
        // logged-in user's copy must not end up in a shared cache.
        $shared = $tokenStorage->getToken()?->getUser() === null;

        return LlmsTxtResponse::create($body, $config->maxAge, $request, $shared);
    }

    /**
     * The site's address as `|link(true)` writes it: the host from
     * `general/canonical` when set, otherwise the request's.
     */
    private function baseUrl(Request $request): string
    {
        $homepage = $this->canonical->get('homepage') ?? $request->getSchemeAndHttpHost() . $request->getBasePath();

        return mb_rtrim($homepage, '/');
    }

    /**
     * The configured template; for the default name, falling back to the shipped
     * one. A template the project names explicitly must exist.
     *
     * @return string|list<string>
     */
    private function templates(LlmsTxtConfig $config): string|array
    {
        return $config->template === LlmsTxtConfig::DEFAULT_TEMPLATE
            ? [$config->template, self::SHIPPED_TEMPLATE]
            : $config->template;
    }
}
