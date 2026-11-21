<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Adminhtml
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

it('neutralizes spreadsheet formulas in string grid exports', function () {
    Mage::app()->getStore()->setConfig(Mage_ImportExport_Model_Export_Adapter_Csv::CONFIG_ESCAPING_FLAG, '1');

    $collection = new class implements IteratorAggregate {
        public function getSelect(): object
        {
            return new class {
                public function limit(): self
                {
                    return $this;
                }
            };
        }

        public function setPageSize(int $size): self
        {
            return $this;
        }

        public function load(): self
        {
            return $this;
        }

        public function getSize(): int
        {
            return 1;
        }

        public function getIterator(): Traversable
        {
            yield new \Maho\DataObject(['value' => '=1+1']);
        }
    };

    $grid = new class extends Mage_Adminhtml_Block_Widget_Grid {
        #[\Override]
        protected function _prepareGrid()
        {
            return $this;
        }
    };
    $grid->setLayout(Mage::getModel('core/layout'));
    $grid->setCollection($collection);
    $grid->setCountTotals(true);
    $grid->setTotals(new \Maho\DataObject(['value' => '@SUM(A1:A2)']));
    $grid->addColumn('value', ['index' => 'value', 'header' => 'Value']);

    $rows = array_map(
        static fn(string $line): array => str_getcsv($line, ',', '"', ''),
        explode("\n", trim($grid->getCsv())),
    );

    expect($rows)->toBe([
        ['Value'],
        [' =1+1'],
        [' @SUM(A1:A2)'],
    ]);
});
