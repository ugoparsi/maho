<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Tests
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

class InvoiceCaptureLockOrderService extends Mage_Sales_Service_Order
{
    public static int $locks = 0;

    #[\Override]
    public function withOrderLock(int $orderId, callable $operation): mixed
    {
        self::$locks++;
        return parent::withOrderLock($orderId, $operation);
    }
}

class InvoiceCaptureTestMethod extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'invoice_capture_test';
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
}

function invoiceCaptureOrder(): Mage_Sales_Model_Order
{
    $order = Mage::getModel('sales/order')
        ->setIncrementId('INVOICE-CAPTURE-' . uniqid('', true))
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
        ->setTotalPaid(0.0)
        ->setBaseTotalPaid(0.0);

    foreach (['invoice-capture-a', 'invoice-capture-b'] as $sku) {
        $order->addItem(Mage::getModel('sales/order_item')->setData([
            'sku' => $sku,
            'product_type' => 'simple',
            'qty_ordered' => 1,
            'price' => 50,
            'base_price' => 50,
            'row_total' => 50,
            'base_row_total' => 50,
        ]));
    }

    $order->setPayment(Mage::getModel('sales/order_payment')->setMethod('invoice_capture_test'));
    $order->save();
    return Mage::getModel('sales/order')->load($order->getId());
}

function invoiceCapturePendingInvoice(Mage_Sales_Model_Order $order, string $sku): Mage_Sales_Model_Order_Invoice
{
    $itemId = 0;
    foreach ($order->getAllItems() as $item) {
        if ($item->getSku() === $sku) {
            $itemId = (int) $item->getId();
            break;
        }
    }

    $invoice = $order->prepareInvoice([$itemId => 1]);
    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::NOT_CAPTURE);
    $invoice->register();
    $order->setIsInProcess(true);
    Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($order)->save();

    return $invoice;
}

beforeEach(function () {
    $config = Mage::getConfig();
    $config->setNode('default/payment/invoice_capture_test/model', InvoiceCaptureTestMethod::class);
    $config->setNode('default/payment/invoice_capture_test/active', 1);
    Mage::app()->getStore()->setConfig('payment/invoice_capture_test/model', InvoiceCaptureTestMethod::class);
    Mage::app()->getStore()->setConfig('payment/invoice_capture_test/active', 1);
    $this->order = invoiceCaptureOrder();
});

afterEach(function () {
    Mage::getModel('sales/order')->load($this->order->getId())->delete();
});

it('serializes legacy invoice capture per order and preserves every paid total', function () {
    $invoiceA = invoiceCapturePendingInvoice($this->order, 'invoice-capture-a');
    $this->order = Mage::getModel('sales/order')->load($this->order->getId());
    $invoiceB = invoiceCapturePendingInvoice($this->order, 'invoice-capture-b');

    $config = Mage::getConfig();
    $config->setNode('global/services/sales/rewrite/order', InvoiceCaptureLockOrderService::class);
    (function (): void {
        $this->_classNameCache = [];
    })->call($config);
    Mage::unregister('_service/sales/order');
    InvoiceCaptureLockOrderService::$locks = 0;

    try {
        $reloadedA = Mage::getModel('sales/order_invoice')->load($invoiceA->getId());
        $reloadedB = Mage::getModel('sales/order_invoice')->load($invoiceB->getId());
        expect(Mage::getStoreConfig('payment/invoice_capture_test/model'))->toBe(InvoiceCaptureTestMethod::class)
            ->and($reloadedA->getState())->toBe(Mage_Sales_Model_Order_Invoice::STATE_OPEN)
            ->and($reloadedB->getState())->toBe(Mage_Sales_Model_Order_Invoice::STATE_OPEN)
            ->and($reloadedA->getOrder()->getPayment()->canCapture())->toBeTrue()
            ->and($reloadedB->getOrder()->getPayment()->canCapture())->toBeTrue();
        $api = new Mage_Sales_Model_Order_Invoice_Api();
        $api->capture($invoiceA->getIncrementId());
        $api->capture($invoiceB->getIncrementId());

        $reloaded = Mage::getModel('sales/order')->load($this->order->getId());
        expect(InvoiceCaptureLockOrderService::$locks)->toBe(2)
            ->and((int) Mage::getModel('sales/order_invoice')->load($invoiceA->getId())->getState())
            ->toBe(Mage_Sales_Model_Order_Invoice::STATE_PAID)
            ->and((int) Mage::getModel('sales/order_invoice')->load($invoiceB->getId())->getState())
            ->toBe(Mage_Sales_Model_Order_Invoice::STATE_PAID)
            ->and((float) $reloaded->getBaseTotalPaid())->toBe(100.0);
    } finally {
        $config->setNode('global/services/sales/rewrite/order', '');
        (function (): void {
            $this->_classNameCache = [];
        })->call($config);
        Mage::unregister('_service/sales/order');
    }
});
