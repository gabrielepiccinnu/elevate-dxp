<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Provider;

interface TranslationProviderInterface
{
    public function name(): string;

    public function translate(string $text, string $from, string $to): string;
}
