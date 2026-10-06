<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Template;

/**
 * Google Merchant RSS 2.0 feed with the g: namespace (http://base.google.com/ns/1.0).
 * Standard RSS elements (title, link, description) are emitted without prefix; Google-specific
 * attributes (id, image_link, price, availability, brand, condition, gtin, mpn) use the g: prefix.
 *
 * Spec: https://support.google.com/merchants/answer/14987622 (RSS 2.0 specification).
 */
final class GoogleMerchantTemplate implements FeedTemplateInterface
{
    public const NS = 'http://base.google.com/ns/1.0';
    private const STANDARD = ['title', 'link', 'description'];
    private const G_PRICE = ['price', 'sale_price'];

    public function name(): string
    {
        return 'google_merchant';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function contentType(): string
    {
        return 'application/rss+xml; charset=UTF-8';
    }

    public function requiredFields(): array
    {
        return ['id', 'title', 'description', 'link', 'image_link', 'availability', 'price'];
    }

    public function render(array $rows, array $meta): string
    {
        $currency = (string) ($meta['currency'] ?? 'EUR');
        $channel = (array) ($meta['channel'] ?? []);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', self::NS);
        $doc->appendChild($rss);

        $channelEl = $doc->createElement('channel');
        $channelEl->appendChild($this->text($doc, 'title', (string) ($channel['title'] ?? 'Product feed')));
        $channelEl->appendChild($this->text($doc, 'link', (string) ($channel['link'] ?? 'https://example.com')));
        $channelEl->appendChild($this->text($doc, 'description', (string) ($channel['description'] ?? '')));
        $rss->appendChild($channelEl);

        foreach ($rows as $row) {
            $item = $doc->createElement('item');
            foreach ($row as $field => $value) {
                $field = (string) $field;
                $str = \is_array($value)
                    ? implode(',', array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $value))
                    : (\is_scalar($value) ? (string) $value : '');
                if (\in_array($field, self::G_PRICE, true) && $str !== '') {
                    $str = $this->formatPrice($str, $currency);
                }
                $local = preg_replace('/[^A-Za-z0-9_]/', '_', $field) ?: 'field';
                if (!preg_match('/^[A-Za-z_]/', $local)) {
                    $local = 'f_'.$local;
                }
                // Prefixed name bound by the xmlns:g declaration on <rss> (no per-element redeclaration).
                $el = $doc->createElement(\in_array($local, self::STANDARD, true) ? $local : 'g:'.$local);
                $el->appendChild($doc->createTextNode($str));
                $item->appendChild($el);
            }
            $channelEl->appendChild($item);
        }

        return (string) $doc->saveXML();
    }

    private function text(\DOMDocument $doc, string $tag, string $value): \DOMElement
    {
        $el = $doc->createElement($tag);
        $el->appendChild($doc->createTextNode($value));

        return $el;
    }

    private function formatPrice(string $price, string $currency): string
    {
        // Google expects "<number> <currency>", e.g. "12.99 EUR".
        if (preg_match('/[A-Z]{3}$/', trim($price))) {
            return trim($price);
        }

        return number_format((float) $price, 2, '.', '').' '.$currency;
    }
}
