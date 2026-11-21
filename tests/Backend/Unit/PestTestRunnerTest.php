<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

it('keeps the configured database password out of test runner output', function () {
    $secret = 'test-db-secret-' . bin2hex(random_bytes(6));
    $runner = dirname(__DIR__, 2) . '/pest-with-test-db.php';
    $command = sprintf(
        'MAHO_DB_TYPE=mysql MAHO_MYSQL_HOST=%s MAHO_MYSQL_USER=%s MAHO_MYSQL_PASS=%s %s %s 2>&1',
        escapeshellarg('invalid.invalid'),
        escapeshellarg('maho-test'),
        escapeshellarg($secret),
        escapeshellarg(PHP_BINARY),
        escapeshellarg($runner),
    );

    $output = (string) shell_exec($command);

    expect($output)->toContain('Running: mysql')
        ->toContain('-p***')
        ->not->toContain($secret);
});
