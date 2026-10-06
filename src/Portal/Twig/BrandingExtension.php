<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes the portal branding / white-label settings to templates via edxp_branding(). */
final class BrandingExtension extends AbstractExtension
{
    private const COLOR = '/^#(?:[0-9a-fA-F]{3}){1,2}$/';

    /** @param array<string,mixed> $branding */
    public function __construct(private readonly array $branding)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('edxp_branding', $this->branding(...)),
        ];
    }

    /**
     * Colours are injected into a <style> block, so anything that is not a hex colour is replaced
     * by the default (prevents CSS injection through configuration).
     *
     * @return array<string,mixed>
     */
    public function branding(): array
    {
        $b = $this->branding + [
            'portal_name' => 'Elevate DXP Portal',
            'logo_url' => null,
            'primary_color' => '#0e7c7b',
            'accent_color' => '#16242b',
            'footer_text' => '',
        ];
        foreach (['primary_color' => '#0e7c7b', 'accent_color' => '#16242b'] as $key => $default) {
            if (!\is_string($b[$key]) || preg_match(self::COLOR, $b[$key]) !== 1) {
                $b[$key] = $default;
            }
        }

        return $b;
    }
}
