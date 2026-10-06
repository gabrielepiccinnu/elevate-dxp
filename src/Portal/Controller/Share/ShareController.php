<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Controller\Share;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Portal\Cart\ZipBuilder;
use ElevateDxp\Portal\Repository\CollectionRepository;
use ElevateDxp\Portal\Security\PortalPolicy;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\Concrete;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * PUBLIC, anonymous guest access to a shared collection via an expiring token.
 *
 * Anti-enumeration: malformed, unknown, revoked and expired tokens all get the very same
 * 403 response (body, headers, no hint which case applied). The token is 160 random bits.
 * Responses are never cached and never leak the token through the Referer header.
 */
#[Route('/elevate-dxp/portal/share')]
final class ShareController
{
    public const DENIED_MESSAGE = 'This share link is invalid or has expired.';

    public function __construct(
        private readonly Environment $twig,
        private readonly AuditLoggerInterface $audit,
        private readonly CollectionRepository $collections,
        private readonly ZipBuilder $zip,
        private readonly PortalPolicy $policy,
    ) {
    }

    #[Route('/{token}', name: 'elevate_dxp_portal_share_view', requirements: ['token' => '[^/]{1,128}'], methods: ['GET'])]
    public function view(string $token): Response
    {
        $collection = $this->collections->findByToken($token);
        if ($collection === null) {
            return self::denied();
        }
        $this->audit->log(new AuditEvent('portal.share.view', 'guest', 'ok', ['collection' => (int) $collection['id']]));

        return self::harden(new Response($this->twig->render('@ElevateDxp/portal/share.html.twig', [
            'collection' => $collection,
            'items' => $this->describe($this->collections->items((int) $collection['id'])),
            'token' => $token,
        ])));
    }

    #[Route('/{token}/download', name: 'elevate_dxp_portal_share_download', requirements: ['token' => '[^/]{1,128}'], methods: ['GET'])]
    public function download(string $token): Response
    {
        $collection = $this->collections->findByToken($token);
        if ($collection === null) {
            return self::denied();
        }

        return self::harden($this->zip->stream($this->collections->items((int) $collection['id']), 'shared-'.(int) $collection['id'], 'guest'));
    }

    public static function denied(): Response
    {
        return self::harden(new Response(self::DENIED_MESSAGE, Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    private static function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    /**
     * Only policy-allowed assets reveal their name/preview to guests.
     *
     * @param list<array{type:string,id:int}> $items
     *
     * @return list<array{type:string,id:int,label:string,thumb:?string}>
     */
    private function describe(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $label = $it['type'].' #'.$it['id'];
            $thumb = null;
            if ($it['type'] === 'asset') {
                $a = Asset::getById($it['id']);
                if (!$a instanceof Asset || !$this->policy->canDownload($a)) {
                    continue;
                }
                $label = (string) $a->getFilename();
                $thumb = $a instanceof Asset\Image ? $a->getFullPath() : null;
            } else {
                $o = Concrete::getById($it['id']);
                if (!$o instanceof Concrete || !$o->isPublished()) {
                    continue;
                }
                $label = method_exists($o, 'getName') && \is_scalar($o->getName()) && (string) $o->getName() !== '' ? (string) $o->getName() : (string) $o->getKey();
            }
            $out[] = ['type' => $it['type'], 'id' => $it['id'], 'label' => $label, 'thumb' => $thumb];
        }

        return $out;
    }
}
