<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Xliff;

/**
 * XLIFF 1.2 export/import of translation units (key => source[/target]).
 *
 * Also reads XLIFF produced by the native OpenDXP XliffBundle (namespaced, one <file> per target
 * language): fillTargetsPerFile() uses each file's own source/target language.
 */
final class XliffService
{
    private const LOAD_FLAGS = \LIBXML_NONET;

    /** @param array<string,string> $units key => source text */
    public function toXliff(array $units, string $sourceLang, string $targetLang, string $original = 'elevate-dxp'): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $xliff = $doc->createElement('xliff');
        $xliff->setAttribute('version', '1.2');
        $doc->appendChild($xliff);

        $file = $doc->createElement('file');
        $file->setAttribute('source-language', $sourceLang);
        $file->setAttribute('target-language', $targetLang);
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', $original);
        $xliff->appendChild($file);

        $body = $doc->createElement('body');
        $file->appendChild($body);

        foreach ($units as $key => $source) {
            $tu = $doc->createElement('trans-unit');
            $tu->setAttribute('id', (string) $key);
            $src = $doc->createElement('source');
            $src->appendChild($doc->createTextNode((string) $source));
            $tu->appendChild($src);
            $body->appendChild($tu);
        }

        return (string) $doc->saveXML();
    }

    /**
     * @return array<string,array{source:string,target:string}>
     */
    public function fromXliff(string $xml): array
    {
        $out = [];
        $doc = $this->load($xml);
        if ($doc === null) {
            return $out;
        }
        foreach ($doc->getElementsByTagName('trans-unit') as $tu) {
            /** @var \DOMElement $tu */
            $id = $tu->getAttribute('id');
            if ($id === '') {
                continue;
            }
            $source = $tu->getElementsByTagName('source')->item(0);
            $target = $tu->getElementsByTagName('target')->item(0);
            $out[$id] = [
                'source' => $source->textContent ?? '',
                'target' => $target->textContent ?? '',
            ];
        }

        return $out;
    }

    public function isValid(string $xml): bool
    {
        return $this->load($xml) !== null;
    }

    public function countUnits(string $xml): int
    {
        return $this->load($xml)?->getElementsByTagName('trans-unit')->length ?? 0;
    }

    /**
     * Fill empty <target> elements using a callback (text -> translated).
     *
     * @param callable(string):string $translate
     */
    public function fillTargets(string $xml, callable $translate): string
    {
        return $this->fillTargetsPerFile($xml, static fn (string $text): string => $translate($text));
    }

    /**
     * Like fillTargets(), but the callback also receives the enclosing <file>'s
     * source-language and target-language attributes (null when absent).
     *
     * @param callable(string,?string,?string):string $translate
     */
    public function fillTargetsPerFile(string $xml, callable $translate): string
    {
        $doc = $this->load($xml);
        if ($doc === null) {
            return $xml;
        }
        $doc->formatOutput = true;
        foreach ($doc->getElementsByTagName('trans-unit') as $tu) {
            /** @var \DOMElement $tu */
            $source = $tu->getElementsByTagName('source')->item(0);
            $existing = $tu->getElementsByTagName('target')->item(0);
            if ($existing instanceof \DOMElement && trim($existing->textContent) !== '') {
                continue;
            }
            [$from, $to] = $this->fileLanguages($tu);
            $translated = $translate($source->textContent ?? '', $from, $to);
            if ($existing instanceof \DOMElement) {
                $existing->textContent = $translated;
            } else {
                $t = $tu->namespaceURI !== null
                    ? $doc->createElementNS($tu->namespaceURI, 'target')
                    : $doc->createElement('target');
                $t->appendChild($doc->createTextNode($translated));
                if ($to !== null) {
                    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $to);
                }
                $tu->appendChild($t);
            }
        }

        return (string) $doc->saveXML();
    }

    /** @return array{0:?string,1:?string} */
    private function fileLanguages(\DOMElement $node): array
    {
        for ($n = $node->parentNode; $n instanceof \DOMElement; $n = $n->parentNode) {
            if ($n->localName === 'file') {
                $from = $n->getAttribute('source-language');
                $to = $n->getAttribute('target-language');

                return [$from !== '' ? $from : null, $to !== '' ? $to : null];
            }
        }

        return [null, null];
    }

    private function load(string $xml): ?\DOMDocument
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null; // no DTDs: blocks entity expansion attacks
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, self::LOAD_FLAGS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $ok ? $doc : null;
    }
}
