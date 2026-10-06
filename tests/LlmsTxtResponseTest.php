<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Tomvondracek\LlmsTxt\LlmsTxtResponse;

final class LlmsTxtResponseTest extends TestCase
{
    public function testPublicPlainTextWithEtag(): void
    {
        $response = LlmsTxtResponse::create("# Site\n", 3600, Request::create('/llms.txt'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("# Site\n", $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('"' . hash('xxh128', "# Site\n") . '"', $response->getEtag());
        self::assertTrue($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }

    public function testNotSharedResponseIsPrivateWithoutOptOut(): void
    {
        $response = LlmsTxtResponse::create("# Site\n", 3600, Request::create('/llms.txt'), false);

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
        self::assertNotNull($response->getEtag());
    }

    public function testMatchingEtagGivesNotModified(): void
    {
        $etag = LlmsTxtResponse::create("# Site\n", 3600, Request::create('/llms.txt'))->getEtag();

        $request = Request::create('/llms.txt');
        $request->headers->set('If-None-Match', (string) $etag);
        $response = LlmsTxtResponse::create("# Site\n", 3600, $request);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testChangedBodyGetsNewEtag(): void
    {
        $request = Request::create('/llms.txt');
        $request->headers->set('If-None-Match', '"' . hash('xxh128', "# Old\n") . '"');

        $response = LlmsTxtResponse::create("# New\n", 3600, $request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame("# New\n", $response->getContent());
        self::assertSame('"' . hash('xxh128', "# New\n") . '"', $response->getEtag());
    }

    public function testNormalize(): void
    {
        self::assertSame(
            "# Site\n\n> Summary\n\n- a\n- b\n",
            LlmsTxtResponse::normalize("\n\n# Site\r\n\n\n\n> Summary\r\n\n- a\n- b\n\n\n")
        );
    }

    public function testNormalizeCollapsesLinesHoldingOnlyIndentation(): void
    {
        self::assertSame(
            "# Site\n\n- a\n",
            LlmsTxtResponse::normalize("    \n# Site\n    \n\t\n  \n- a\n   \n")
        );
    }

    public function testNormalizeKeepsInnerTrailingSpacesAndSingleBlankLines(): void
    {
        self::assertSame("a  \nb\n  \nc\n", LlmsTxtResponse::normalize("a  \nb\n  \nc"));
    }
}
