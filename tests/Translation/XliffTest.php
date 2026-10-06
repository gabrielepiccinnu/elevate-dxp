<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Translation;

use ElevateDxp\Translation\Provider\PseudoProvider;
use ElevateDxp\Translation\Xliff\XliffService;
use PHPUnit\Framework\TestCase;

/** Ported from OpenPimcore\Tests\TranslationBundle\XliffTest, plus native-export and hardening cases. */
final class XliffTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $x = new XliffService();
        $xml = $x->toXliff(['greeting' => 'Hello', 'cta' => 'Buy now'], 'en', 'de');

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'XLIFF must be well-formed');
        self::assertStringContainsString('source-language="en"', $xml);
        self::assertStringContainsString('target-language="de"', $xml);
        self::assertStringContainsString('id="greeting"', $xml);

        $parsed = $x->fromXliff($xml);
        self::assertSame('Hello', $parsed['greeting']['source']);
        self::assertSame('', $parsed['greeting']['target']);
    }

    public function testFillTargets(): void
    {
        $x = new XliffService();
        $xml = $x->toXliff(['k' => 'hello'], 'en', 'de');
        $filled = $x->fillTargets($xml, static fn (string $t): string => strtoupper($t));
        $parsed = $x->fromXliff($filled);
        self::assertSame('HELLO', $parsed['k']['target']);
    }

    public function testPseudoProvider(): void
    {
        $p = new PseudoProvider();
        self::assertSame('pseudo', $p->name());
        self::assertSame('[DE] Hi', $p->translate('Hi', 'en', 'de'));
        self::assertSame('', $p->translate('', 'en', 'de'));
    }

    public function testEscapingAndExistingTargetsKept(): void
    {
        $x = new XliffService();
        $xml = $x->toXliff(['a' => 'Fish & <Chips>', 'b' => 'b'], 'en', 'it');
        $xml = $x->fillTargets($xml, static fn (string $t): string => $t === 'b' ? 'B' : $t.'!');
        $again = $x->fillTargets($xml, static fn (string $t): string => 'changed');
        $parsed = $x->fromXliff($again);
        self::assertSame('Fish & <Chips>', $parsed['a']['source']);
        self::assertSame('Fish & <Chips>!', $parsed['a']['target']);
        self::assertSame('B', $parsed['b']['target'], 'non-empty targets are never overwritten');
        self::assertSame(2, $x->countUnits($again));
    }

    public function testNativeNamespacedMultiFileExport(): void
    {
        // Shape produced by the OpenDXP XliffBundle Xliff12Exporter: one <file> per target language.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">'
            .'<file original="document-5" source-language="en" target-language="de" datatype="html"><header/><body>'
            .'<trans-unit id="tag~-~title"><source>Title</source></trans-unit></body></file>'
            .'<file original="document-5" source-language="en" target-language="fr" datatype="html"><header/><body>'
            .'<trans-unit id="tag~-~title_fr"><source>Title</source><target/></trans-unit></body></file></xliff>';
        $x = new XliffService();
        $filled = $x->fillTargetsPerFile($xml, static fn (string $t, ?string $from, ?string $to): string => "$from>$to:$t");
        $parsed = $x->fromXliff($filled);
        self::assertSame('en>de:Title', $parsed['tag~-~title']['target']);
        self::assertSame('en>fr:Title', $parsed['tag~-~title_fr']['target']);

        $doc = new \DOMDocument();
        $doc->loadXML($filled);
        $targets = $doc->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:1.2', 'target');
        self::assertSame(2, $targets->length, 'created targets keep the XLIFF namespace');
    }

    public function testRejectsDoctypeAndGarbage(): void
    {
        $x = new XliffService();
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><xliff><file><body><trans-unit id="a"><source>&e;</source></trans-unit></body></file></xliff>';
        self::assertFalse($x->isValid($xxe));
        self::assertSame([], $x->fromXliff($xxe));
        self::assertSame($xxe, $x->fillTargets($xxe, static fn (string $t): string => 'x'));
        self::assertFalse($x->isValid('not xml'));
        self::assertSame(0, $x->countUnits(''));
    }
}
