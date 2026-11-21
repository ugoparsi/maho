<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Api
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('excludes an active adapter when any required extension is unavailable', function () {
    $xml = simplexml_load_string(<<<'XML'
        <config>
            <adapters>
                <missing>
                    <active>1</active>
                    <model>missing/model</model>
                    <required><extensions><definitely_missing_extension/></extensions></required>
                </missing>
                <valid>
                    <active>1</active>
                    <model>valid/model</model>
                    <required><extensions><json/></extensions></required>
                </valid>
                <disabled>
                    <active>0</active>
                    <model>disabled/model</model>
                </disabled>
            </adapters>
        </config>
        XML, \Maho\Simplexml\Element::class);
    $config = new class ($xml) extends Mage_Api_Model_Config {
        #[\Override]
        protected function _construct() {}
    };

    expect(array_keys($config->getActiveAdapters()))->toBe(['valid']);
});
