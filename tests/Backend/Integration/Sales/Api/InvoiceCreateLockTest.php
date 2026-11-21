<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Tests
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

class InvoiceCreateLockOrderService extends Mage_Sales_Service_Order
{
    public static int $locks = 0;

    #[\Override]
    public function withOrderLock(int $orderId, callable $operation): mixed
    {
        self::$locks++;
        return parent::withOrderLock($orderId, $operation);
    }
}

function invoiceCreateOrder(): Mage_Sales_Model_Order
{
    $order = Mage::getModel('sales/order')
        ->setIncrementId('INVOICE-CREATE-' . uniqid('', true))
        ->setStoreId(1)
        ->setState(Mage_Sales_Model_Order::STATE_PROCESSING)
        ->setStatus('processing')
        ->setBaseCurrencyCode('USD')
        ->setOrderCurrencyCode('USD')
        ->setBaseToOrderRate(1.0)
        ->setSubtotal(100.0)
        ->setBaseSubtotal(100.0)
        ->setGrandTotal(100.0)
        ->setBaseGrandTotal(100.0)
        ->setTotalInvoiced(0.0)
        ->setBaseTotalInvoiced(0.0);

    foreach (['invoice-create-a', 'invoice-create-b'] as $sku) {
        $order->addItem(Mage::getModel('sales/order_item')->setData([
            'sku' => $sku,
            'product_type' => 'simple',
            'qty_ordered' => 1,
            'qty_to_invoice' => 1,
            'price' => 50,
            'base_price' => 50,
            'row_total' => 50,
            'base_row_total' => 50,
        ]));
    }

    $order->setPayment(Mage::getModel('sales/order_payment')->setMethod('checkmo'));
    $order->save();
    return Mage::getModel('sales/order')->load($order->getId());
}

function invoiceCreateItemId(Mage_Sales_Model_Order $order, string $sku): int
{
    foreach ($order->getAllItems() as $item) {
        if ($item->getSku() === $sku) {
            return (int) $item->getId();
        }
    }
    throw new RuntimeException('Order item not found: ' . $sku);
}

beforeEach(function () {
    $this->order = invoiceCreateOrder();
});

afterEach(function () {
    Mage::getModel('sales/order')->load($this->order->getId())->delete();
});

it('serializes legacy invoice creation per order and preserves the invoiced total', function () {
    $config = Mage::getConfig();
    $config->setNode('global/services/sales/rewrite/order', InvoiceCreateLockOrderService::class);
    (function (): void {
        $this->_classNameCache = [];
    })->call($config);
    Mage::unregister('_service/sales/order');
    InvoiceCreateLockOrderService::$locks = 0;

    try {
        $api = new Mage_Sales_Model_Order_Invoice_Api();
        $api->create($this->order->getIncrementId(), [invoiceCreateItemId($this->order, 'invoice-create-a') => 1]);
        $this->order = Mage::getModel('sales/order')->load($this->order->getId());
        $api->create($this->order->getIncrementId(), [invoiceCreateItemId($this->order, 'invoice-create-b') => 1]);

        $reloaded = Mage::getModel('sales/order')->load($this->order->getId());
        $invoices = Mage::getResourceModel('sales/order_invoice_collection')
            ->addFieldToFilter('order_id', (int) $reloaded->getId());

        expect(InvoiceCreateLockOrderService::$locks)->toBe(2)
            ->and($invoices->getSize())->toBe(2)
            ->and((float) $reloaded->getBaseTotalInvoiced())->toBe(100.0)
            ->and((float) $reloaded->getTotalInvoiced())->toBe(100.0);
    } finally {
        $config->setNode('global/services/sales/rewrite/order', '');
        (function (): void {
            $this->_classNameCache = [];
        })->call($config);
        Mage::unregister('_service/sales/order');
    }
});
