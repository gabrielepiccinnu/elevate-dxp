<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Twig;

use ElevateDxp\Portal\Twig\BrandingExtension;
use PHPUnit\Framework\TestCase;

final class BrandingExtensionTest extends TestCase
{
    public function testExposesBrandingFunction(): void
    {
        $ext = new BrandingExtension(['portal_name' => 'Acme', 'primary_color' => '#ff0000']);
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());
        self::assertSame(['edxp_branding'], $names);
        self::assertSame('Acme', $ext->branding()['portal_name']);
        self::assertSame('#ff0000', $ext->branding()['primary_color']);
    }

    public function testRejectsCssInjectionInColours(): void
    {
        $ext = new BrandingExtension(['primary_color' => 'red;}</style><script>alert(1)</script>', 'accent_color' => '#abc']);
        self::assertSame('#0e7c7b', $ext->branding()['primary_color']);
        self::assertSame('#abc', $ext->branding()['accent_color']);
    }
}
