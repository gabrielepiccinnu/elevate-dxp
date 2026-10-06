<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Admin;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Builds absolute guest share URLs and admin download URLs. */
class ShareUrlGenerator
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function share(string $token): string
    {
        return $this->urls->generate('elevate_dxp_portal_share_view', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function cartDownload(): string
    {
        return $this->urls->generate('elevate_dxp_portal_admin_download_cart');
    }

    public function collectionDownload(int $id): string
    {
        return $this->urls->generate('elevate_dxp_portal_admin_download_collection', ['id' => $id]);
    }
}
