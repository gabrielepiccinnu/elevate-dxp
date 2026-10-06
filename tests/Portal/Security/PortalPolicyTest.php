<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Security;

use ElevateDxp\Portal\Repository\CollectionRepository;
use ElevateDxp\Portal\Security\PortalPolicy;
use OpenDxp\Model\Asset;
use PHPUnit\Framework\TestCase;

final class PortalPolicyTest extends TestCase
{
    private function asset(string $type, string $path): Asset
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getType')->willReturn($type);
        $asset->method('getFullPath')->willReturn($path);

        return $asset;
    }

    public function testAllowsOnlyInsideAllowListedFolders(): void
    {
        $policy = new PortalPolicy(['/products']);
        self::assertTrue($policy->canDownload($this->asset('image', '/products/shoe.jpg')));
        self::assertTrue($policy->canDownload($this->asset('image', '/products/sub/shoe.jpg')));
        self::assertFalse($policy->canDownload($this->asset('image', '/products-internal/secret.pdf')), 'prefix must match a folder boundary');
        self::assertFalse($policy->canDownload($this->asset('image', '/private/a.jpg')));
        self::assertFalse($policy->canDownload($this->asset('folder', '/products/sub')), 'folders are never downloadable');
        self::assertFalse($policy->canDownload($this->asset('image', '/products/../private/a.jpg')));
    }

    public function testEmptyAllowListDeniesEverything(): void
    {
        $policy = new PortalPolicy([]);
        self::assertFalse($policy->canDownload($this->asset('image', '/products/a.jpg')));
        self::assertFalse((new PortalPolicy(['', '  ']))->isPathAllowed('/a.jpg'));
    }

    public function testRootAllowsEverything(): void
    {
        self::assertTrue((new PortalPolicy(['/']))->isPathAllowed('/anything/a.jpg'));
    }

    public function testShareTokensAreRandomAndWellFormed(): void
    {
        $a = CollectionRepository::newToken();
        $b = CollectionRepository::newToken();
        self::assertNotSame($a, $b);
        self::assertTrue(CollectionRepository::isWellFormedToken($a));
        self::assertFalse(CollectionRepository::isWellFormedToken(''));
        self::assertFalse(CollectionRepository::isWellFormedToken(strtoupper($a)));
        self::assertFalse(CollectionRepository::isWellFormedToken($a."' OR '1'='1"));
    }
}
