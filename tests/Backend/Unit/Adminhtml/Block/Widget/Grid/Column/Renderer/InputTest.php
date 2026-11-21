<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Adminhtml
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('escapes editable grid values as HTML attributes', function (): void {
    /** @var Mage_Adminhtml_Block_Widget_Grid_Column $column */
    $column = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column');
    $column->setData([
        'index' => 'editable_value',
        'id' => 'editable-value',
        'inline_css' => 'custom-class',
    ]);

    /** @var Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Input $renderer */
    $renderer = Mage::app()->getLayout()->createBlock('adminhtml/widget_grid_column_renderer_input');
    $renderer->setColumn($column);

    $value = 'A "quoted" & <value>';
    $html = $renderer->render(new Maho\DataObject(['editable_value' => $value]));

    $document = new DOMDocument();
    $document->loadHTML($html);
    $input = $document->getElementsByTagName('input')->item(0);

    expect($input->getAttribute('name'))->toBe('editable-value')
        ->and($input->getAttribute('value'))->toBe($value)
        ->and($input->getAttribute('class'))->toBe('input-text custom-class');
});
