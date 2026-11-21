<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_CatalogRule
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('scopes selected On Sale rule products to the current storefront context', function () {
    $websites = array_values(Mage::app()->getWebsites());
    if (count($websites) < 2) {
        $this->markTestSkipped('The fixture needs two websites.');
    }
    $firstWebsite = (int) $websites[0]->getId();
    $secondWebsite = (int) $websites[1]->getId();
    $productId = (int) Mage::getModel('catalog/product')->getCollection()
        ->setPageSize(1)
        ->getFirstItem()
        ->getId();
    if (!$productId) {
        $this->markTestSkipped('The fixture needs one product.');
    }

    $rule = Mage::getModel('catalogrule/rule')
        ->setName('On Sale scope ' . bin2hex(random_bytes(3)))
        ->setIsActive(true)
        ->setWebsiteIds([$firstWebsite])
        ->setCustomerGroupIds([0])
        ->setSimpleAction('by_percent')
        ->setDiscountAmount(10)
        ->save();
    $this->rule = $rule;

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(Mage::getSingleton('core/resource')->getTableName('catalogrule/rule_product'), [
        'rule_id' => (int) $rule->getId(),
        'from_time' => time() - 60,
        'to_time' => time() + 60,
        'customer_group_id' => 0,
        'product_id' => $productId,
        'website_id' => $firstWebsite,
        'action_operator' => 'by_percent',
        'action_amount' => 10,
        'action_stop' => 0,
        'sort_order' => 0,
    ]);

    $resource = Mage::getResourceSingleton('catalogrule/rule');
    expect($resource->getRuleProductIds((int) $rule->getId(), $firstWebsite, 0, time()))
        ->toBe([$productId])
        ->and($resource->getRuleProductIds((int) $rule->getId(), $secondWebsite, 0, time()))
        ->toBe([]);
});

afterEach(function () {
    if (isset($this->rule)) {
        $this->rule->delete();
    }
});
