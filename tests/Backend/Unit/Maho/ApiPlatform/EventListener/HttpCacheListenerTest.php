<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_ApiPlatform
 */

declare(strict_types=1);

use Maho\ApiPlatform\EventListener\HttpCacheListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

uses(Tests\MahoBackendTestCase::class);

it('keeps the selected cache policy on a not-modified API response', function () {
    $content = '{"items":[]}';
    $etag = '"' . md5($content) . '"';
    $request = Request::create('/api/rest/v2/store-config');
    $request->headers->set('If-None-Match', $etag);
    $response = new Response($content);
    $event = new ResponseEvent(
        new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        },
        $request,
        HttpKernelInterface::MAIN_REQUEST,
        $response,
    );
    $security = new class extends Security {
        public function __construct() {}

        public function getUser(): ?UserInterface
        {
            return null;
        }
    };

    (new HttpCacheListener($security))($event);

    expect($event->getResponse()->getStatusCode())->toBe(Response::HTTP_NOT_MODIFIED)
        ->and($event->getResponse()->headers->get('ETag'))->toBe($etag)
        ->and($event->getResponse()->headers->getCacheControlDirective('public'))->not->toBeNull()
        ->and($event->getResponse()->headers->getCacheControlDirective('max-age'))->toBe('3600')
        ->and($event->getResponse()->headers->get('Vary'))->toContain('Authorization');
});
