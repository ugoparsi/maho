<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Adminhtml
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

/**
 * @return array<string, array{class-string<Mage_Core_Model_Config_Data>, string, array<string, string>}>
 */
function cronBackendCases(): array
{
    return [
        'currency' => [
            Mage_Adminhtml_Model_System_Config_Backend_Currency_Cron::class,
            Mage_Adminhtml_Model_System_Config_Backend_Currency_Cron::CRON_STRING_PATH,
            [
                'groups/import/fields/time/value' => ['1', '2', '0'],
                'groups/import/fields/frequency/value' => 'D',
            ],
        ],
        'log' => [
            Mage_Adminhtml_Model_System_Config_Backend_Log_Cron::class,
            Mage_Adminhtml_Model_System_Config_Backend_Log_Cron::CRON_STRING_PATH,
            [
                'groups/log/fields/enabled/value' => '1',
                'groups/log/fields/time/value' => ['1', '2', '0'],
                'groups/log/fields/frequency/value' => 'D',
            ],
        ],
        'product alert' => [
            Mage_Adminhtml_Model_System_Config_Backend_Product_Alert_Cron::class,
            Mage_Adminhtml_Model_System_Config_Backend_Product_Alert_Cron::CRON_STRING_PATH,
            [
                'groups/productalert_cron/fields/time/value' => ['1', '2', '0'],
                'groups/productalert_cron/fields/frequency/value' => 'D',
            ],
        ],
        'sitemap' => [
            Mage_Adminhtml_Model_System_Config_Backend_Sitemap_Cron::class,
            Mage_Adminhtml_Model_System_Config_Backend_Sitemap_Cron::CRON_STRING_PATH,
            [
                'groups/generate/fields/time/value' => ['1', '2', '0'],
                'groups/generate/frequency/value' => 'D',
            ],
        ],
    ];
}

function setCronExpression(string $path, string $value): void
{
    Mage::getModel('core/config_data')
        ->load($path, 'path')
        ->setPath($path)
        ->setValue($value)
        ->save();
}

function cronExpression(string $path): string
{
    return (string) Mage::getSingleton('core/resource')->getConnection('core_read')->fetchOne(
        Mage::getSingleton('core/resource')->getConnection('core_read')->select()
            ->from('core_config_data', 'value')
            ->where('path = ?', $path),
    );
}

/**
 * @param class-string<Mage_Core_Model_Config_Data> $class
 * @param array<string, mixed> $data
 */
function saveCronBackend(string $class, array $data): void
{
    $backend = new $class();
    $backend->setData($data);
    $method = new ReflectionMethod($class, '_afterSave');
    $method->setAccessible(true);
    $method->invoke($backend);
}

afterEach(function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    foreach (cronBackendCases() as [, $path]) {
        $write->delete('core_config_data', ['path = ?' => $path]);
    }
});

it('rejects out-of-range hours and minutes without replacing the saved schedule', function (
    string $case,
    string $invalidField,
    string $invalidValue,
) {
    [$class, $path, $data] = cronBackendCases()[$case];
    setCronExpression($path, '5 4 * * *');
    $timePath = array_key_first(array_filter(
        $data,
        fn(string $key): bool => str_ends_with($key, '/time/value'),
        ARRAY_FILTER_USE_KEY,
    ));
    $time = $data[$timePath];
    $time[$invalidField === 'hour' ? 0 : 1] = $invalidValue;
    $data[$timePath] = $time;

    expect(fn() => saveCronBackend($class, $data))
        ->toThrow(Mage_Core_Exception::class)
        ->and(cronExpression($path))->toBe('5 4 * * *');
})->with([
    'currency invalid hour' => ['currency', 'hour', '24'],
    'currency invalid minute' => ['currency', 'minute', '60'],
    'log invalid hour' => ['log', 'hour', '-1'],
    'log invalid minute' => ['log', 'minute', '60'],
    'product alert invalid hour' => ['product alert', 'hour', '24'],
    'product alert invalid minute' => ['product alert', 'minute', '-1'],
    'sitemap invalid hour' => ['sitemap', 'hour', '24'],
    'sitemap invalid minute' => ['sitemap', 'minute', '60'],
]);

it('accepts the cron hour and minute boundaries', function (string $case) {
    [$class, $path, $data] = cronBackendCases()[$case];
    $timePath = array_key_first(array_filter(
        $data,
        fn(string $key): bool => str_ends_with($key, '/time/value'),
        ARRAY_FILTER_USE_KEY,
    ));
    $data[$timePath] = ['23', '59', '0'];

    saveCronBackend($class, $data);

    expect(cronExpression($path))->toBe('59 23 * * *');
})->with(array_keys(cronBackendCases()));
