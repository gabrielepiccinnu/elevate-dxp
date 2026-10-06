<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Feed;

use ElevateDxp\Feed\Template\GenericCsvTemplate;
use ElevateDxp\Feed\Template\GoogleMerchantTemplate;
use ElevateDxp\Feed\Validator\FeedValidator;
use PHPUnit\Framework\TestCase;

/** Feed templates and the feed validator. */
final class FeedTest extends TestCase
{
    /** @return array<string, string> */
    private function row(): array
    {
        return [
            'id' => 'SKU1', 'title' => 'Aurora', 'description' => 'Nice chair',
            'link' => 'https://shop/p/SKU1', 'image_link' => '/products/x.png',
            'availability' => 'in stock', 'price' => '12.99', 'brand' => 'Demo',
        ];
    }

    public function testGoogleMerchantNamespaceAndPrefixes(): void
    {
        $xml = (new GoogleMerchantTemplate())->render([$this->row()], [
            'currency' => 'EUR',
            'channel' => ['title' => 'Feed', 'link' => 'https://shop', 'description' => 'd'],
        ]);

        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $xml);
        self::assertStringContainsString('<g:id>SKU1</g:id>', $xml);
        self::assertStringContainsString('<title>Aurora</title>', $xml);          // standard, no prefix
        self::assertStringContainsString('<g:image_link>/products/x.png</g:image_link>', $xml);
        self::assertStringContainsString('<g:price>12.99 EUR</g:price>', $xml);   // currency appended
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
    }

    public function testGoogleMerchantElementsAreInTheGoogleNamespace(): void
    {
        $xml = (new GoogleMerchantTemplate())->render([$this->row() + ['sale_price' => '9.5 USD', 'bad key' => 'x & y']], []);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('g', GoogleMerchantTemplate::NS);
        self::assertSame('SKU1', $xp->evaluate('string(/rss/channel/item/g:id)'));
        self::assertSame('9.5 USD', $xp->evaluate('string(/rss/channel/item/g:sale_price)'));
        self::assertSame('x & y', $xp->evaluate('string(/rss/channel/item/g:bad_key)'));
        self::assertSame('Product feed', $xp->evaluate('string(/rss/channel/title)'));
    }

    public function testValidatorDetectsMissingRequired(): void
    {
        $validator = new FeedValidator();
        $template = new GoogleMerchantTemplate();

        $ok = $validator->validate($template, [$this->row()]);
        self::assertSame([], $ok);

        $bad = $this->row();
        unset($bad['price'], $bad['image_link']);
        $issues = $validator->validate($template, [$bad]);
        self::assertCount(1, $issues);
        self::assertContains('price', $issues[0]['missing']);
        self::assertContains('image_link', $issues[0]['missing']);
    }

    public function testGenericCsvUsesFormulaInjectionGuard(): void
    {
        $t = new GenericCsvTemplate();
        self::assertSame('csv', $t->extension());
        $csv = $t->render([['id' => 1, 'title' => '=cmd|x']], []);
        self::assertSame("id,title\n1,'=cmd|x\n", $csv);
        self::assertSame(['id', 'title'], $t->requiredFields());
    }
}
