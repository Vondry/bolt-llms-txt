<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * Builds the plain-text response for a rendered llms.txt.
 *
 * Caching is left to HTTP: the response carries an ETag so clients can
 * revalidate, and is public for `max_age` seconds unless it was rendered for a
 * logged-in user. Templates should order everything deterministically, so an
 * unchanged site gives an identical body and a 304.
 */
final class LlmsTxtResponse
{
    public const CONTENT_TYPE = 'text/plain; charset=UTF-8';

    /**
     * @param bool $shared whether the body is the same for every visitor, so shared
     *                     caches may store it; false for a logged-in user
     */
    public static function create(string $body, int $maxAge, Request $request, bool $shared = true): Response
    {
        $body = self::normalize($body);

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => self::CONTENT_TYPE,
            // The text can quote HTML from the content; browsers must not render it.
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setMaxAge($maxAge);
        $response->setEtag(hash('xxh128', $body));

        if ($shared) {
            // Symfony turns every response of a request that used the session into
            // `private, max-age=0`, and Bolt's main firewall uses it on every
            // frontend request, even without a session cookie. Opting out is the
            // documented way to cache such a response ("HTTP Caching and User
            // Sessions"); LlmsTxtResponseSubscriber makes it private again should a
            // cookie be set after all.
            $response->setPublic();
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        } else {
            $response->setPrivate();
        }

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Unix line endings, at most one blank line in a row (Twig tags and
     * conditionals easily leave more, also lines holding only the indentation of a
     * tag), no blank lines at the start and exactly one newline at the end.
     *
     * @internal
     */
    public static function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/\n(?:[ \t]*\n){2,}/', "\n\n", $body) ?? $body;
        $body = preg_replace('/^(?:[ \t]*\n)+/', '', $body) ?? $body;

        return mb_rtrim($body) . "\n";
    }
}
