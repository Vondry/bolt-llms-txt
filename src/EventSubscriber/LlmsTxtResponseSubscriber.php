<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Tomvondracek\LlmsTxt\Controller\LlmsTxtController;

/**
 * Safety net for the public caching of llms.txt responses.
 *
 * {@see \Tomvondracek\LlmsTxt\LlmsTxtResponse} opts out of Symfony's automatic
 * `private` for requests with a session. Should a cookie end up on the response
 * anyway (e.g. a listener of the project started a session), a shared cache must
 * not store it, so the response becomes private again.
 */
final class LlmsTxtResponseSubscriber implements EventSubscriberInterface
{
    /**
     * After AbstractSessionListener::onKernelResponse() (-1000), which adds the
     * session cookie.
     */
    public const PRIORITY = -1010;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->get(LlmsTxtController::REQUEST_ATTRIBUTE) !== true) {
            return;
        }

        $response = $event->getResponse();

        // The session listener removes this internal header from main requests
        // only, and only when sessions are enabled.
        $response->headers->remove(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER);

        if ($event->isMainRequest() && $response->headers->getCookies() !== []) {
            $response->setPrivate();
        }
    }
}
