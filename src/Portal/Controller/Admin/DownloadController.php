<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Controller\Admin;

use ElevateDxp\Portal\Cart\CartStorage;
use ElevateDxp\Portal\Cart\ZipBuilder;
use ElevateDxp\Portal\Installer\PortalInstaller;
use ElevateDxp\Portal\Repository\CollectionRepository;
use OpenDxp\Bundle\AdminBundle\Controller\AdminAbstractController;
use OpenDxp\Model\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Binary ZIP downloads for admin users (cart and collections). JSON features go through the core
 * ResourceController; streams cannot, so they live here, under /admin (admin firewall + session).
 */
#[Route('/elevate-dxp/portal')]
final class DownloadController extends AdminAbstractController
{
    public function __construct(
        private readonly CartStorage $cart,
        private readonly CollectionRepository $collections,
        private readonly ZipBuilder $zip,
    ) {
    }

    #[Route('/download/cart', name: 'elevate_dxp_portal_admin_download_cart', methods: ['GET'])]
    public function cart(): Response
    {
        $user = $this->allowedUser();
        if ($user === null) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }
        $items = $this->cart->all();
        if ($items === []) {
            return new JsonResponse(['success' => false, 'message' => 'The cart is empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->zip->stream($items, 'cart', (string) $user->getName());
    }

    #[Route('/download/collection/{id}', name: 'elevate_dxp_portal_admin_download_collection', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function collection(int $id): Response
    {
        $user = $this->allowedUser();
        if ($user === null) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }
        if ($this->collections->find($id, $user->isAdmin() ? null : (string) $user->getName()) === null) {
            return new JsonResponse(['success' => false, 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->zip->stream($this->collections->items($id), 'collection-'.$id, (string) $user->getName());
    }

    private function allowedUser(): ?User
    {
        $user = $this->getAdminUser();
        if (!$user instanceof User) {
            return null;
        }

        return $user->isAdmin() || $user->isAllowed(PortalInstaller::PERMISSION) ? $user : null;
    }
}
