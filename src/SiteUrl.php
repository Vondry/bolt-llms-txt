<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Bolt\Canonical;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The site's address as `|link(true)` writes it: the host from `general/canonical`
 * when set, otherwise the request's. Shared by the controller (`baseUrl`) and the
 * Twig filters (site-relative links), so one file never mixes hosts.
 */
final readonly class SiteUrl
{
    public function __construct(
        private Canonical $canonical,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * The homepage URL without a trailing slash, or null outside a request.
     */
    public function homepage(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $homepage = $this->canonical->get('homepage') ?? $request->getSchemeAndHttpHost() . $request->getBasePath();

        return mb_rtrim($homepage, '/');
    }

    /**
     * `scheme://host[:port]` of the homepage, which site-relative links (`/about`)
     * are resolved against; they already contain the base path.
     */
    public function origin(): ?string
    {
        $parts = parse_url($this->homepage() ?? '');
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
