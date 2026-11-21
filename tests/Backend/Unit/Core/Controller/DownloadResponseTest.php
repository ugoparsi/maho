<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

it('sends a download with the original filename and an ASCII fallback when needed', function (string $controllerClass, string $filename, string $disposition): void {
    Mage::register('_singleton/admin/session', new class {
        public function isFirstPageAfterLogin(): bool
        {
            return false;
        }
    });

    try {
        $response = new Mage_Core_Controller_Response_Http();
        $controller = $this->getMockBuilder($controllerClass)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResponse'])
            ->getMock();
        $controller->method('getResponse')->willReturn($response);

        new ReflectionMethod($controllerClass, '_prepareDownloadResponse')->invoke($controller, $filename, 'Example', 'text/plain');

        $headers = $response->getSymfonyResponse()->headers;
        expect($headers->get('Content-Disposition'))->toBe($disposition)
            ->and($headers->get('Content-Type'))->toBe('text/plain')
            ->and($headers->get('Content-Length'))->toBe('7')
            ->and($response->getBody())->toBe('Example');
    } finally {
        Mage::unregister('_singleton/admin/session');
    }
})->with([
    'admin' => Mage_Adminhtml_Controller_Action::class,
    'storefront' => Mage_Core_Controller_Front_Action::class,
])->with([
    'ASCII' => ['Order report.txt', 'attachment; filename="Order report.txt"'],
    'Unicode' => ['Résumé 2026.txt', 'attachment; filename="R_sum_ 2026.txt"; filename*=utf-8\'\'R%C3%A9sum%C3%A9%202026.txt'],
]);
