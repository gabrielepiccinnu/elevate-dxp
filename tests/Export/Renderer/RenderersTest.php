<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Export\Renderer;

use ElevateDxp\Export\Renderer\CsvRenderer;
use ElevateDxp\Export\Renderer\JsonRenderer;
use ElevateDxp\Export\Renderer\XmlRenderer;
use ElevateDxp\Export\Target\PathGuard;
use PHPUnit\Framework\TestCase;

/** Ported from the legacy OpenPimcore\Tests\ExportBundle\RenderersTest, plus extra cases. */
final class RenderersTest extends TestCase
{
    public function testCsvHeaderAndFormulaInjectionGuard(): void
    {
        $csv = (new CsvRenderer())->render([
            ['id' => 1, 'name' => 'Aurora', 'formula' => '=1+2'],
            ['id' => 2, 'name' => 'Nimbus', 'formula' => '+danger'],
        ]);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        self::assertSame('id,name,formula', $lines[0]);
        // dangerous cells are prefixed with a single quote
        self::assertStringContainsString("'=1+2", $csv);
        self::assertStringContainsString("'+danger", $csv);
    }

    public function testCsvSanitizeCoversAllDangerousPrefixes(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tx", "\rx"] as $cell) {
            self::assertSame("'".$cell, CsvRenderer::sanitize($cell));
        }
        self::assertSame('safe', CsvRenderer::sanitize('safe'));
        self::assertSame('', CsvRenderer::sanitize(null));
        self::assertSame('a|b', CsvRenderer::sanitize(['a', 'b']));
        self::assertSame("'=x|y", CsvRenderer::sanitize(['=x', 'y']));
        self::assertSame('1', CsvRenderer::sanitize(true));
    }

    public function testCsvEmptyAndMissingColumns(): void
    {
        $r = new CsvRenderer();
        self::assertSame('', $r->render([]));
        $csv = $r->render([['a' => 1, 'b' => 2], ['a' => 3]]);
        self::assertSame("a,b\n1,2\n3,\n", $csv);
    }

    public function testJson(): void
    {
        $json = (new JsonRenderer())->render([['id' => 1, 'name' => 'A/B']]);
        self::assertJson($json);
        self::assertStringContainsString('"name": "A/B"', $json);
        self::assertSame('[]', (new JsonRenderer())->render([]));
    }

    public function testXmlEscaping(): void
    {
        $xml = (new XmlRenderer())->render([['name' => 'Tom & <Jerry>']]);
        self::assertStringContainsString('<items>', $xml);
        self::assertStringContainsString('Tom &amp; &lt;Jerry&gt;', $xml);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'rendered XML must be well-formed');
    }

    public function testXmlSafeTagNames(): void
    {
        $xml = (new XmlRenderer())->render([['1st' => 'a', 'my field' => 'b', 'xmlThing' => 'c', 'tags' => ['x', 'y']]]);
        self::assertStringContainsString('<f_1st>a</f_1st>', $xml);
        self::assertStringContainsString('<my_field>b</my_field>', $xml);
        self::assertStringContainsString('<f_xmlThing>c</f_xmlThing>', $xml);
        self::assertStringContainsString('<tags>x|y</tags>', $xml);
        self::assertTrue((new \DOMDocument())->loadXML($xml));
    }

    public function testPathGuardRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathGuard::resolveUnder('/base/exports', '../../etc/passwd');
    }

    public function testPathGuardAllowsWithinBase(): void
    {
        self::assertSame('/base/exports/sub/out.csv', PathGuard::resolveUnder('/base/exports', 'sub/out.csv'));
        self::assertSame('/base/exports/out.csv', PathGuard::resolveUnder('/base/exports', '/out.csv'));
    }

    /** @return iterable<array{string}> */
    public static function badPaths(): iterable
    {
        yield 'null byte' => ["a\0b.csv"];
        yield 'empty' => [''];
        yield 'base itself' => ['./'];
        yield 'backslash traversal' => ['..\\..\\etc\\passwd'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPaths')]
    public function testPathGuardRejects(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathGuard::resolveUnder('/base/exports', $path);
    }
}
