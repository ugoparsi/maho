<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

beforeEach(function (): void {
    $this->js = Mage::helper('core/js');
    $this->core = Mage::helper('core');
});

it('emits navigation URLs as complete JavaScript literals', function (): void {
    $url = "https://example.test/admin/item/edit/id/1/?q=it's\\path";
    $literal = $this->core->jsEscape($url);
    $message = $this->core->jsQuoteEscape('Remove?');

    expect($this->js->getDeleteConfirmJs($url, 'Remove?'))
        ->toBe("deleteConfirm('{$message}', {$literal})")
        ->and($this->js->getConfirmSetLocationJs($url, 'Remove?'))
        ->toBe("confirmSetLocation('{$message}', {$literal})")
        ->and($this->js->getSetLocationJs($url))
        ->toBe("setLocation({$literal})")
        ->and($this->js->getSaveAndContinueEditJs($url))
        ->toBe("saveAndContinueEdit({$literal})");
});

it('treats replacement route values literally', function (): void {
    $url = Mage::helper('core/url');
    $value = '$1\\path';

    expect($url->setRouteParams('https://example.test/admin/item/id/old/?x=1#part', ['id' => $value]))
        ->toBe('https://example.test/admin/item/id/$1\\path/?x=1#part')
        ->and($url->setRouteParams('https://example.test/admin/item/?x=1#part', ['id' => $value]))
        ->toBe('https://example.test/admin/item/id/$1\\path/?x=1#part');
});
