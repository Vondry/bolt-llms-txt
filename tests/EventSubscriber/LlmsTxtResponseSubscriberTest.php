<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Tomvondracek\LlmsTxt\Controller\LlmsTxtController;
use Tomvondracek\LlmsTxt\EventSubscriber\LlmsTxtResponseSubscriber;
use Tomvondracek\LlmsTxt\LlmsTxtResponse;

final class LlmsTxtResponseSubscriberTest extends TestCase
{
    public function testRunsAfterTheSessionListener(): void
    {
        $subscribed = LlmsTxtResponseSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE];
        $sessionListener = AbstractSessionListener::getSubscribedEvents()[KernelEvents::RESPONSE];

        self::assertIsArray($subscribed);
        self::assertIsArray($sessionListener);
        self::assertLessThan($sessionListener[1], $subscribed[1]);
    }

    public function testRemovesTheInternalHeaderAndStaysPublic(): void
    {
        $response = $this->dispatch($this->llmsRequest());

        self::assertFalse($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testBecomesPrivateWhenACookieIsSet(): void
    {
        $response = $this->dispatch($this->llmsRequest(), Cookie::create('PHPSESSID', 'abc'));

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testSubRequestLosesTheInternalHeaderOnly(): void
    {
        $response = $this->dispatch($this->llmsRequest(), Cookie::create('PHPSESSID', 'abc'), HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
        self::assertTrue($response->headers->hasCacheControlDirective('public'), 'cookies are a matter of the main request');
    }

    public function testIgnoresOtherRequests(): void
    {
        $response = $this->dispatch(Request::create('/'), Cookie::create('PHPSESSID', 'abc'));

        self::assertTrue($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    private function llmsRequest(): Request
    {
        $request = Request::create('/llms.txt');
        $request->attributes->set(LlmsTxtController::REQUEST_ATTRIBUTE, true);

        return $request;
    }

    private function dispatch(Request $request, ?Cookie $cookie = null, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = LlmsTxtResponse::create("# Site\n", 3600, $request);
        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, $response);
        (new LlmsTxtResponseSubscriber())->onKernelResponse($event);

        return $event->getResponse();
    }
}
