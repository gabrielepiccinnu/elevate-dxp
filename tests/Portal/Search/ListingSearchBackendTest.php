<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Search;

use ElevateDxp\Portal\Search\Listing\ListingFactory;
use ElevateDxp\Portal\Search\ListingSearchBackend;
use ElevateDxp\Portal\Search\SearchQuery;
use OpenDxp\Model\Asset;
use PHPUnit\Framework\TestCase;

final class ListingSearchBackendTest extends TestCase
{
    private function factory(?Asset\Listing $assets = null, array $objectFields = ['name', 'sku', 'price']): ListingFactory
    {
        return new class($assets, $objectFields) extends ListingFactory {
            public function __construct(private readonly ?Asset\Listing $assetListing, private readonly array $fields)
            {
            }

            public function assets(): Asset\Listing
            {
                return $this->assetListing ?? throw new \LogicException('no listing');
            }

            public function hasObjectField(string $className, string $field): bool
            {
                return \in_array($field, $this->fields, true);
            }
        };
    }

    public function testAssetConditionExcludesFoldersAndSearchesMetadata(): void
    {
        $backend = new ListingSearchBackend($this->factory(), ['asset_fields' => ['filename'], 'asset_metadata' => true]);
        [$sql, $params] = $backend->assetCondition(new SearchQuery('asset', 'logo', pathPrefix: '/brand'));

        self::assertStringStartsWith('`type` != ?', $sql);
        self::assertStringContainsString('`filename` LIKE ?', $sql);
        self::assertStringContainsString('assets_metadata', $sql);
        self::assertStringEndsWith('`path` LIKE ?', $sql);
        self::assertSame(['folder', '%logo%', '%logo%', '/brand/%'], $params);
    }

    public function testAssetSearchCanBeRestrictedToAllowedPaths(): void
    {
        $backend = new ListingSearchBackend($this->factory(), ['asset_metadata' => false, 'restrict_assets_to_allowed_paths' => true], ['/products']);
        [$sql, $params] = $backend->assetCondition(new SearchQuery('asset'));
        self::assertSame('`type` != ? AND (`path` LIKE ?)', $sql);
        self::assertSame(['folder', '/products/%'], $params);
    }

    public function testObjectConditionUsesConfiguredFieldsAndRanges(): void
    {
        $backend = new ListingSearchBackend($this->factory(), ['object_fields' => ['Product' => ['name', 'sku']]]);
        $q = SearchQuery::fromArray(['type' => 'object', 'class' => 'Product', 'q' => 'boot', 'min' => 5, 'range_field' => 'price']);
        [$sql, $params] = $backend->objectCondition($q);

        self::assertSame('(`name` LIKE ? OR `sku` LIKE ?) AND `price` >= ?', $sql);
        self::assertSame(['%boot%', '%boot%', 5.0], $params);
    }

    public function testUnknownObjectFieldIsRejected(): void
    {
        $backend = new ListingSearchBackend($this->factory(), ['object_fields' => ['Product' => ['name']]]);
        $this->expectException(\InvalidArgumentException::class);
        $backend->objectCondition(SearchQuery::fromArray(['type' => 'object', 'class' => 'Product', 'min' => 1, 'range_field' => 'secret_column']));
    }

    public function testSupportsAssetsAndObjectsWithClass(): void
    {
        $backend = new ListingSearchBackend($this->factory());
        self::assertSame('listing', $backend->getName());
        self::assertTrue($backend->supports(new SearchQuery('asset')));
        self::assertTrue($backend->supports(new SearchQuery('object', className: 'Product')));
        self::assertFalse($backend->supports(new SearchQuery('object')));
    }

    public function testAssetSearchAppliesConditionOrderAndPaging(): void
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getId')->willReturn(42);
        $asset->method('getFilename')->willReturn('logo.png');
        $asset->method('getFullPath')->willReturn('/brand/logo.png');
        $asset->method('getType')->willReturn('image');
        $asset->method('getMimeType')->willReturn('image/png');
        $asset->method('getModificationDate')->willReturn(1700000000);

        $listing = $this->createMock(Asset\Listing::class);
        $listing->expects(self::once())->method('setCondition')->with('`type` != ? AND (`filename` LIKE ?)', ['folder', '%logo%'])->willReturnSelf();
        $listing->expects(self::once())->method('setOrderKey')->with(['modificationDate'])->willReturnSelf();
        $listing->expects(self::once())->method('setOrder')->with(['DESC'])->willReturnSelf();
        $listing->expects(self::once())->method('setLimit')->with(10)->willReturnSelf();
        $listing->expects(self::once())->method('setOffset')->with(10)->willReturnSelf();
        $listing->method('getData')->willReturn([$asset]);
        $listing->method('count')->willReturn(11);

        $backend = new ListingSearchBackend($this->factory($listing), ['asset_fields' => ['filename'], 'asset_metadata' => false]);
        $result = $backend->search(new SearchQuery('asset', 'logo', orderBy: ['modificationDate' => 'DESC'], page: 2, pageSize: 10));

        self::assertSame(11, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame([
            'id' => 42, 'type' => 'asset', 'key' => 'logo.png', 'fullPath' => '/brand/logo.png',
            'subtype' => 'image', 'mimetype' => 'image/png', 'modificationDate' => 1700000000,
        ], $result->items[0]);
    }

    public function testOrderingOnNonAllowListedFieldIsRejected(): void
    {
        $listing = $this->createStub(Asset\Listing::class);
        $backend = new ListingSearchBackend($this->factory($listing), ['asset_metadata' => false]);
        $this->expectException(\InvalidArgumentException::class);
        $backend->search(new SearchQuery('asset', orderBy: ['customSettings' => 'ASC']));
    }
}
