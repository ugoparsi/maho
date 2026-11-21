<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

uses(Tests\MahoBackendTestCase::class);

/**
 * The report export actions unpacked getCsv() and getExcel() into _prepareDownloadResponse().
 * Both methods return a string, so each of these exports ended in an error.
 */
describe('report export actions', function () {
    it('sends the export as a download', function (string $controllerClass, string $action, string $fileName, string $contentType) {
        $request = new Mage_Core_Controller_Request_Http(SymfonyRequest::create('/index.php/admin/report/' . $action));
        $request->setRouteName('adminhtml')->setActionName($action)->setDispatched(true);
        Mage::app()->setRequest($request);
        $response = new Mage_Core_Controller_Response_Http();

        new $controllerClass($request, $response)->{$action . 'Action'}();

        $headers = array_column($response->getHeaders(), 'value', 'name');
        expect($headers['Content-Disposition'])->toBe('attachment; filename="' . $fileName . '"')
            ->and($headers['Content-Type'])->toBe($contentType)
            ->and($response->getBody())->not->toBe('');
    })->with([
        'report grid CSV' => [Mage_Adminhtml_Report_CustomerController::class, 'exportAccountsCsv', 'new_accounts.csv', 'text/csv'],
        'report grid Excel' => [Mage_Adminhtml_Report_CustomerController::class, 'exportAccountsExcel', 'accounts.xml', 'application/vnd.ms-excel'],
    ]);

    it('honors CSV escaping when exporting ordered product names', function (bool $escape): void {
        Mage::app()->getStore()->setConfig(Mage_ImportExport_Model_Export_Adapter_Csv::CONFIG_ESCAPING_FLAG, $escape ? '1' : '0');
        $storeId = (int) Mage::app()->getDefaultStoreView()->getId();
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');
        $productTable = $resource->getTableName('catalog/product');
        $orderTable = $resource->getTableName('sales/order');
        $productType = Mage::getSingleton('eav/config')->getEntityType('catalog_product');
        $name = '+2.00 Reading Glasses, Amber';
        $sku = 'report-export-reading-glasses';
        $date = '2003-06-12';

        $connection->beginTransaction();
        try {
            $connection->insert($productTable, [
                'entity_type_id' => $productType->getId(),
                'attribute_set_id' => $productType->getDefaultAttributeSetId(),
                'type_id' => Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
                'sku' => $sku,
            ]);
            $productId = (int) $connection->lastInsertId($productTable);

            $connection->insert($orderTable, [
                'state' => Mage_Sales_Model_Order::STATE_NEW,
                'status' => 'pending',
                'store_id' => $storeId,
                'created_at' => $date . ' 12:00:00',
            ]);
            $connection->insert($resource->getTableName('sales/order_item'), [
                'order_id' => (int) $connection->lastInsertId($orderTable),
                'product_id' => $productId,
                'store_id' => $storeId,
                'name' => $name,
                'sku' => $sku,
                'qty_ordered' => 2,
            ]);

            $request = new Mage_Core_Controller_Request_Http(SymfonyRequest::create(
                '/index.php/admin/report_product/exportSoldCsv',
                'GET',
                ['report_from' => $date, 'report_to' => $date, 'report_period' => 'day', 'store' => $storeId],
            ));
            $request->setRouteName('adminhtml')->setActionName('exportSoldCsv')->setDispatched(true);
            Mage::app()->setRequest($request);
            $response = new Mage_Core_Controller_Response_Http();
            new Mage_Adminhtml_Report_ProductController($request, $response)->exportSoldCsvAction();

            $lines = explode("\n", trim($response->getBody()));
            expect($lines)->toHaveCount(3);
            $row = str_getcsv($lines[1], escape: '');
            expect($row[1])->toBe($escape ? ' ' . $name : $name)
                ->and((float) $row[2])->toBe(2.0);
        } finally {
            $connection->rollBack();
        }
    })->with([
        'escaping enabled' => [true],
        'escaping disabled' => [false],
    ]);
});
