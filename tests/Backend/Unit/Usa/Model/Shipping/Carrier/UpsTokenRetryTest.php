<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Usa
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

class UpsTokenRetryProbe extends Mage_Usa_Model_Shipping_Carrier_Ups
{
    public int $tokenCalls = 0;
    public int $invalidations = 0;
    public array $sentTokens = [];

    /** @var int[] */
    public array $statuses = [];

    public function send(): array
    {
        return $this->_sendUpsRestRequest('https://ups.example/rate', '{}', true, ['Content-Type: application/json']);
    }

    protected function setAPIAccessRequest()
    {
        return 'token-' . ++$this->tokenCalls;
    }

    protected function invalidateAPIAccessRequest(): void
    {
        $this->invalidations++;
    }

    protected function _sendUpsHttpRequest(string $url, ?string $payload, bool $post, array $headers): array
    {
        $this->sentTokens[] = $headers[0];
        $status = array_shift($this->statuses);

        return [
            'body' => $status === 200 ? '{"ok":true}' : '{"error":"unauthorized"}',
            'status' => $status,
            'error' => null,
            'errno' => 0,
        ];
    }
}

it('refreshes a rejected UPS token and retries once', function () {
    $carrier = new UpsTokenRetryProbe();
    $carrier->statuses = [401, 200];

    $response = $carrier->send();

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toBe('{"ok":true}')
        ->and($carrier->sentTokens)->toBe([
            'Authorization: Bearer token-1',
            'Authorization: Bearer token-2',
        ])
        ->and($carrier->invalidations)->toBe(1);
});

it('does not loop after a second UPS authentication rejection', function () {
    $carrier = new UpsTokenRetryProbe();
    $carrier->statuses = [401, 401];

    $response = $carrier->send();

    expect($response['status'])->toBe(401)
        ->and($carrier->sentTokens)->toHaveCount(2)
        ->and($carrier->invalidations)->toBe(1);
});
