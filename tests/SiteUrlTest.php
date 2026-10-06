<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use Bolt\Canonical;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tomvondracek\LlmsTxt\SiteUrl;

final class SiteUrlTest extends TestCase
{
    public function testCanonicalHomepage(): void
    {
        $siteUrl = $this->siteUrl(Request::create('https://www.example.com/llms.txt'), 'https://example.com:8443/');

        self::assertSame('https://example.com:8443', $siteUrl->homepage());
        self::assertSame('https://example.com:8443', $siteUrl->origin());
    }

    public function testRequestWhenThereIsNoCanonicalUrl(): void
    {
        $request = Request::create('http://example.com/site/llms.txt', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/site/index.php',
            'SCRIPT_NAME' => '/site/index.php',
        ]);
        $siteUrl = $this->siteUrl($request, null);

        self::assertSame('http://example.com/site', $siteUrl->homepage(), 'with the base path');
        self::assertSame('http://example.com', $siteUrl->origin(), 'site-relative links contain the base path already');
    }

    public function testNothingOutsideARequest(): void
    {
        $siteUrl = $this->siteUrl(null, 'https://example.com/');

        self::assertNull($siteUrl->homepage());
        self::assertNull($siteUrl->origin());
    }

    private function siteUrl(?Request $request, ?string $canonicalHomepage): SiteUrl
    {
        $requestStack = new RequestStack();
        if ($request instanceof Request) {
            $requestStack->push($request);
        }

        $canonical = self::createStub(Canonical::class);
        $canonical->method('get')
            ->willReturn($canonicalHomepage);

        return new SiteUrl($canonical, $requestStack);
    }
}
