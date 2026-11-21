<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_CatalogRule
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

afterEach(function () {
    Mage::unregister('_resource_singleton/catalogrule/rule');
});

it('preloads every quote pricing context and only fetches missing products', function () {
    $resource = new class extends Mage_CatalogRule_Model_Resource_Rule {
        public array $calls = [];

        public function getRulePrices($date, $websiteId, $customerGroupId, $productIds)
        {
            $this->calls[] = [$date, (int) $websiteId, (int) $customerGroupId, array_values($productIds)];
            return array_fill_keys($productIds, (float) $customerGroupId);
        }
    };
    Mage::register('_resource_singleton/catalogrule/rule', $resource, true);

    $items = [new \Maho\DataObject(['product_id' => 42])];
    $quote = new class ($items) {
        public int $customerGroupId = 1;

        public function __construct(public array $items) {}

        public function getAllItems(): array
        {
            return $this->items;
        }

        public function getStoreId(): int
        {
            return (int) Mage::app()->getStore()->getId();
        }

        public function getStore(): Mage_Core_Model_Store
        {
            return Mage::app()->getStore();
        }

        public function getCustomerGroupId(): int
        {
            return $this->customerGroupId;
        }
    };

    $observer = new \Maho\Event\Observer(['quote' => $quote]);
    $catalogRuleObserver = new Mage_CatalogRule_Model_Observer();
    $catalogRuleObserver->preloadPriceRules($observer);

    $quote->customerGroupId = 2;
    $catalogRuleObserver->preloadPriceRules($observer);

    $items[] = new \Maho\DataObject(['product_id' => 43]);
    $quote->items = $items;
    $catalogRuleObserver->preloadPriceRules($observer);

    expect(array_column($resource->calls, 2))->toBe([1, 2, 2])
        ->and(array_column($resource->calls, 3))->toBe([[42], [42], [43]]);
});
