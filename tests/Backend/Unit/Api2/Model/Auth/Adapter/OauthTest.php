<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

uses(Tests\MahoBackendTestCase::class);

function legacyOauthRequest(string $authorization): Mage_Api2_Model_Request
{
    return new Mage_Api2_Model_Request(SymfonyRequest::create('/', 'GET', server: [
        'HTTP_AUTHORIZATION' => $authorization,
    ]));
}

it('recognizes only the exact legacy OAuth authorization scheme', function (): void {
    $adapter = new Mage_Api2_Model_Auth_Adapter_Oauth();

    expect($adapter->isApplicableToRequest(legacyOauthRequest('OAuth realm="api"')))->toBeTrue()
        ->and($adapter->isApplicableToRequest(legacyOauthRequest('oAuTh realm="api"')))->toBeTrue()
        ->and($adapter->isApplicableToRequest(legacyOauthRequest('OAuth2 realm="api"')))->toBeFalse()
        ->and($adapter->isApplicableToRequest(legacyOauthRequest('OAuthx realm="api"')))->toBeFalse();
});

it('parses parameters only after the exact legacy OAuth scheme', function (): void {
    $server = new class (legacyOauthRequest('OAuth oauth_consumer_key="consumer", oauth_signature="signature"')) extends Mage_Oauth_Model_Server {
        public function fetch(): void
        {
            $this->_fetchParams();
        }

        /** @return array<string, string> */
        public function parameters(): array
        {
            return $this->_protocolParams;
        }
    };
    $server->fetch();
    expect($server->parameters())->toMatchArray([
        'oauth_consumer_key' => 'consumer',
        'oauth_signature' => 'signature',
    ]);

    $other = new class (legacyOauthRequest('OAuth2 oauth_consumer_key="consumer"')) extends Mage_Oauth_Model_Server {
        public function fetch(): void
        {
            $this->_fetchParams();
        }

        /** @return array<string, string> */
        public function parameters(): array
        {
            return $this->_protocolParams;
        }
    };
    $other->fetch();
    expect($other->parameters())->toBe([]);
});
