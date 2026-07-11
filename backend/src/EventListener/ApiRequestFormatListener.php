<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Forces the request format to JSON for every /api route, regardless of the
 * client's Accept header. Without this, Symfony's error renderer falls back
 * to HTML for clients that omit Accept: application/json (or send text/html),
 * so API errors — including RFC-7807 validation errors — leak an HTML page
 * instead of JSON.
 */
#[AsEventListener(event: RequestEvent::class, priority: 100)]
final class ApiRequestFormatListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api')) {
            $request->setRequestFormat('json');
        }
    }
}
