<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('serializes request selections as JavaScript string literals', function (): void {
    $selection = "1'\\,2";
    Mage::app()->getFrontController()->getRequest()->setParam('internal_massaction', $selection);

    $grid = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid')
        ->setHtmlId('massaction_test');
    $massaction = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_massaction')
        ->setParentBlock($grid)
        ->setFormFieldName('massaction')
        ->setUseSelectAll(false);

    expect($massaction->getSelectedJson())->toBe($selection)
        ->and($massaction->getJavaScript())->toContain(
            ', ' . Mage::helper('core')->jsEscape($selection) . ", 'internal_massaction'",
        );
});
