<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Provider;

/**
 * Deterministic offline "translation" for testing/demo: prefixes the target-language tag.
 * Useful to validate the XLIFF round-trip and the TMS flow without a live MT service.
 */
final class PseudoProvider implements TranslationProviderInterface
{
    public function name(): string
    {
        return 'pseudo';
    }

    public function translate(string $text, string $from, string $to): string
    {
        if (trim($text) === '') {
            return $text;
        }

        return \sprintf('[%s] %s', strtoupper($to), $text);
    }
}
