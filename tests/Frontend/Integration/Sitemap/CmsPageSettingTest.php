<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

uses(Tests\MahoFrontendTestCase::class);

/**
 * The admin saves "identifier|page_id" in a CMS page setting when two pages share the identifier.
 * The sitemap and llms.txt must still find the home page and the no-route page.
 */
function cmsPageSettingCreatePage(?string $identifier = null, array $stores = [0], ?string $robots = null): Mage_Cms_Model_Page
{
    $identifier ??= 'test-setting-' . uniqid();
    $page = Mage::getModel('cms/page');
    $page->setTitle('A ' . $identifier)
        ->setIdentifier($identifier)
        ->setIsActive()
        ->setRootTemplate('one_column')
        ->setStores($stores)
        ->setData('meta_robots', $robots)
        ->setContent('<p>' . $identifier . '</p>')
        ->save();
    return $page;
}

/**
 * @return list<string>
 */
function cmsPageSettingSitemapUrls(int $storeId): array
{
    $sitemap = new class extends Mage_Sitemap_Model_Sitemap {
        /** @var list<string> */
        public array $urls = [];

        public function collectPageUrls(int $storeId): array
        {
            $this->generatePagesSitemap($storeId, 'http://localhost/', '2026-01-01', 50000);
            return $this->urls;
        }

        #[\Override]
        public function writeSingleSitemapFile(string $type, array $items, string $baseUrl, string $lastmod, string $changefreq, float $priority, int $pageNumber, int $totalItems, int $maxUrlsPerFile): void
        {
            foreach ($items as $item) {
                $this->urls[] = (string) $item->getUrl();
            }
        }
    };
    return $sitemap->collectPageUrls($storeId);
}

beforeEach(function () {
    $this->store = Mage::app()->getStore();
    $this->page = cmsPageSettingCreatePage();
    $this->store->setConfig(
        Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE,
        $this->page->getIdentifier() . '|' . $this->page->getId(),
    );
});

afterEach(function () {
    $this->page->delete();
});

it('gives the home page the base URL in the sitemap', function () {
    $urls = cmsPageSettingSitemapUrls((int) $this->store->getId());

    expect($urls)->toContain('')
        ->and($urls)->not->toContain($this->page->getIdentifier());
});

it('omits the base URL when the CMS home page is noindex', function (): void {
    $this->page->setData('meta_robots', 'NOINDEX,NOFOLLOW')->save();

    expect(cmsPageSettingSitemapUrls((int) $this->store->getId()))->not->toContain('');
});

it('does not list the home page in llms.txt', function () {
    $store = $this->store;
    $pageLinks = fn(): string => implode("\n", Mage::getSingleton('sitemap/llms')->getPageLinks($store));

    expect($pageLinks())->not->toContain($this->page->getTitle());

    $store->setConfig(Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE, 'no-such-page');
    expect($pageLinks())->toContain($this->page->getTitle());
});

it('does not list the no-route page in the sitemap', function () {
    $noRoute = cmsPageSettingCreatePage();
    $saved = $this->store->getConfig(Mage_Cms_Helper_Page::XML_PATH_NO_ROUTE_PAGE);
    $this->store->setConfig(Mage_Cms_Helper_Page::XML_PATH_NO_ROUTE_PAGE, $noRoute->getIdentifier() . '|' . $noRoute->getId());

    try {
        expect(cmsPageSettingSitemapUrls((int) $this->store->getId()))->not->toContain($noRoute->getIdentifier());
    } finally {
        $this->store->setConfig(Mage_Cms_Helper_Page::XML_PATH_NO_ROUTE_PAGE, $saved);
        $noRoute->delete();
    }
});

it('uses an explicit CMS robots directive to decide whether to list the page', function (?string $robots, bool $listed): void {
    $page = cmsPageSettingCreatePage(robots: $robots);
    $this->store->setConfig('design/head/default_robots', 'NOINDEX,NOFOLLOW');

    try {
        $urls = cmsPageSettingSitemapUrls((int) $this->store->getId());
        expect(in_array($page->getIdentifier(), $urls, true))->toBe($listed);
    } finally {
        $page->delete();
    }
})->with([
    'noindex, follow' => ['NOINDEX,FOLLOW', false],
    'noindex, nofollow' => ['NOINDEX,NOFOLLOW', false],
    'lowercase with spaces' => ['noindex, follow', false],
    'space separated directives' => ['follow noindex', false],
    'different directive' => ['noindexifembedded, follow', true],
    'index, follow' => ['INDEX,FOLLOW', true],
    'index, nofollow' => ['INDEX,NOFOLLOW', true],
    'empty field' => ['', true],
    'null field' => [null, true],
]);

it('uses the CMS page of the sitemap store when an identifier also has a default page', function (string $defaultRobots, string $storeRobots, bool $listedInStore): void {
    $storeId = (int) $this->store->getId();
    $otherStore = Mage::getModel('core/store')
        ->setCode('sitemap_' . uniqid())
        ->setName('Other Sitemap Store')
        ->setWebsiteId((int) $this->store->getWebsiteId())
        ->setGroupId((int) $this->store->getGroupId())
        ->setIsActive()
        ->save();
    Mage::app()->reinitStores();
    $defaultPage = cmsPageSettingCreatePage(robots: $defaultRobots);
    $identifier = $defaultPage->getIdentifier();
    $storePage = null;

    try {
        $storePage = cmsPageSettingCreatePage($identifier, [$storeId], $storeRobots);
        $pageResource = Mage::getResourceModel('cms/page');
        expect((int) $pageResource->checkIdentifier($identifier, $storeId))->toBe((int) $storePage->getId())
            ->and((int) $pageResource->checkIdentifier($identifier, (int) $otherStore->getId()))->toBe((int) $defaultPage->getId())
            ->and(in_array($identifier, cmsPageSettingSitemapUrls($storeId), true))->toBe($listedInStore)
            ->and(in_array($identifier, cmsPageSettingSitemapUrls((int) $otherStore->getId()), true))->toBe(!$listedInStore);
    } finally {
        $storePage?->delete();
        $defaultPage->delete();
        Mage::app()->withStore(Mage_Core_Model_App::ADMIN_STORE_ID, fn() => $otherStore->delete());
    }
})->with([
    'store page is noindex' => ['INDEX,FOLLOW', 'NOINDEX,FOLLOW', false],
    'default page is noindex' => ['NOINDEX,NOFOLLOW', 'INDEX,FOLLOW', true],
]);
