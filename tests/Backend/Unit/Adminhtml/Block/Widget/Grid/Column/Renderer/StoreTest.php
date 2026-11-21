<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Adminhtml
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('escapes historical store labels in admin grids', function (): void {
    /** @var Mage_Adminhtml_Block_Widget_Grid_Column $column */
    $column = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column');
    $column->setData(['index' => 'store_ids']);

    /** @var Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Store $renderer */
    $renderer = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column_renderer_store');
    $renderer->setColumn($column);

    $row = new Maho\DataObject([
        'store_ids' => null,
        'store_name' => "Main <Store>\nSecond & Store",
    ]);

    $html = $renderer->render($row);

    expect($html)->toBe(
        'Main &lt;Store&gt;<br>&nbsp;&nbsp;&nbsp;Second &amp; Store [deleted]',
    );
});

it('keeps historical store labels literal in exports', function (): void {
    /** @var Mage_Adminhtml_Block_Widget_Grid_Column $column */
    $column = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column');
    $column->setData(['index' => 'store_ids']);

    /** @var Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Store $renderer */
    $renderer = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column_renderer_store');
    $renderer->setColumn($column);

    $row = new Maho\DataObject([
        'store_ids' => null,
        'store_name' => "Main <Store>\nSecond & Store",
    ]);

    expect($renderer->renderExport($row))->toBe(
        "Main <Store>\r\n   Second & Store [deleted]",
    );
});
