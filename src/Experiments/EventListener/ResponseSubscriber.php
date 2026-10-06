<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\EventListener;

use ElevateDxp\Experiments\Experiment\ExperimentRuntime;
use ElevateDxp\Experiments\Repository\VisitorProfileRepository;
use ElevateDxp\Experiments\Targeting\DataProvider\VisitorProfileDataProvider;
use ElevateDxp\Experiments\Visitor\VisitorIdResolver;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\VisitorInfoStorageInterface;
use OpenDxp\Http\RequestHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Frontend HTML responses: issue the visitor cookie, keep personalised responses out of shared
 * caches, update the first-party profile and inject the tracking runtime.
 */
final class ResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VisitorIdResolver $visitorIdResolver,
        private readonly ExperimentRuntime $runtime,
        private readonly VisitorProfileRepository $profiles,
        private readonly VisitorInfoStorageInterface $visitorInfoStorage,
        private readonly RequestHelper $requestHelper,
        private readonly bool $enabled,
        private readonly int $cookieTtlDays,
        private readonly bool $profileEnabled,
        private readonly bool $injectRuntime,
        private readonly bool $trackingEnabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after the targeting listener (-115) so VisitorInfo and rule actions are final
        return [KernelEvents::RESPONSE => ['onResponse', -120]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$this->enabled || !$event->isMainRequest() || $request->getMethod() !== 'GET' || !$this->isHtml($response)) {
            return;
        }
        if (!$this->requestHelper->isFrontendRequest($request) || $this->requestHelper->isFrontendRequestByAdmin($request)) {
            return;
        }
        if (!$this->visitorIdResolver->isTrackable($request)) {
            return;
        }

        $assignments = $this->runtime->assignAll();
        if ($assignments === [] && !$this->profileEnabled && !$this->trackingEnabled) {
            return;
        }

        $visitorId = $this->visitorIdResolver->getVisitorId();
        if ($this->visitorIdResolver->wasIssued()) {
            $response->headers->setCookie(Cookie::create(
                $this->visitorIdResolver->getCookieName(),
                (string) $this->visitorIdResolver->signedValue(),
                time() + $this->cookieTtlDays * 86400,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
        }

        if ($assignments !== []) {
            // personalised: never store in shared caches
            $response->setPrivate();
            $response->headers->set('X-Edxp-Experiments', (string) \count($assignments));
        }

        if ($this->profileEnabled && $response->isSuccessful()) {
            $visitorInfo = $this->visitorInfoStorage->hasVisitorInfo() ? $this->visitorInfoStorage->getVisitorInfo() : null;
            $this->profiles->touch(
                $visitorId,
                $request->getUri(),
                $request->headers->get('Referer'),
                VisitorProfileDataProvider::currentUtm($request->query->all()) ?: null,
                $visitorInfo?->getVisitorId(),
                $visitorInfo !== null ? array_map(static fn ($g) => $g->getName(), $visitorInfo->getAssignedTargetGroups()) : [],
            );
        }

        if ($this->injectRuntime && ($this->trackingEnabled || $assignments !== [])) {
            $this->inject($response, $assignments);
        }
    }

    /**
     * @param array<string,string> $assignments
     */
    private function inject(Response $response, array $assignments): void
    {
        $content = (string) $response->getContent();
        $pos = stripos($content, '</head>');
        if ($pos === false) {
            return;
        }
        $config = json_encode(['trackUrl' => '/_edxp/track', 'experiments' => (object) $assignments], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES);
        $snippet = '<script>window.edxpConfig='.$config.';</script>'
            .'<script src="/bundles/elevatedxp/js/edxp-runtime.js" defer></script>';
        $response->setContent(substr($content, 0, $pos).$snippet.substr($content, $pos));
    }

    private function isHtml(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', 'text/html');

        return str_contains($type, 'text/html') && !$response->isRedirection();
    }
}
