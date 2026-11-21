<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Adminhtml
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

uses(Tests\MahoBackendTestCase::class);

it('serializes request-derived mass-action selection as a JavaScript string', function () {
    $request = new Mage_Core_Controller_Request_Http(
        SymfonyRequest::create('http://localhost/admin/grid'),
    );
    $request->setParam('internal_massaction', 'a\'b"c');
    Mage::app()->setRequest($request);

    $parent = new class extends Mage_Core_Block_Abstract {
        public function getHtmlId()
        {
            return 'test_grid';
        }

        public function getJsObjectName()
        {
            return 'testGridJsObject';
        }
    };
    $massaction = new Mage_Adminhtml_Block_Widget_Grid_Massaction();
    $massaction->setParentBlock($parent);
    $massaction->setData([
        'html_id' => 'test_grid',
        'form_field_name' => 'massaction',
        'form_field_name_internal' => 'internal_massaction',
        'error_text' => 'Admin\'s "error"',
        'use_ajax' => false,
        'use_select_all' => false,
    ]);

    $script = $massaction->getJavaScript();
    $path = tempnam(sys_get_temp_dir(), 'maho-massaction-');
    if ($path === false) {
        throw new RuntimeException('Could not create the JavaScript fixture.');
    }
    file_put_contents($path, 'class varienGridMassaction { constructor() {} setItems() {} setGridIds() {} setUseAjax() {} setUseSelectAll() {} } ' . $script);

    try {
        exec('node --check ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        expect($status)->toBe(0, implode("\n", $output))
            ->and($script)->toContain('"a\'b\"c"');
    } finally {
        unlink($path);
    }
});
