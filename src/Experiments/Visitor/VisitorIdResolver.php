<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Visitor;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * First-party visitor id for experiments and tracking.
 *
 * The official targeting visitor id (_pc_vis) is created client-side, so it is missing on the
 * very first hit; experiments need a stable id immediately, hence this signed server cookie.
 */
final class VisitorIdResolver implements ResetInterface
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor|curl\/|wget/i';

    private ?string $visitorId = null;

    private bool $issued = false;

    /**
     * @param list<string> $excludedPaths regex fragments matched against the path
     */
    public function __construct(
        private readonly CookieSigner $signer,
        private readonly RequestStack $requestStack,
        private readonly string $cookieName,
        private readonly array $excludedPaths = [],
        private readonly ?string $consentCookie = null,
    ) {
    }

    public function getCookieName(): string
    {
        return $this->cookieName;
    }

    /** Whether experiments/tracking apply to this request (no admin/asset paths, no bots). */
    public function isTrackable(?Request $request = null): bool
    {
        $request ??= $this->requestStack->getMainRequest();
        if ($request === null) {
            return false;
        }
        if ($this->consentCookie !== null && $this->consentCookie !== '' && !$request->cookies->has($this->consentCookie)) {
            return false;
        }
        $path = $request->getPathInfo();
        foreach ($this->excludedPaths as $pattern) {
            if (@preg_match('#'.str_replace('#', '\#', $pattern).'#', $path) === 1) {
                return false;
            }
        }

        return preg_match(self::BOT_PATTERN, (string) $request->headers->get('User-Agent', '')) !== 1;
    }

    /** Existing id from the signed cookie, without issuing a new one. */
    public function peekVisitorId(?Request $request = null): ?string
    {
        if ($this->visitorId !== null) {
            return $this->visitorId;
        }
        $request ??= $this->requestStack->getMainRequest();
        $raw = (string) ($request?->cookies->get($this->cookieName, '') ?? '');

        return $raw !== '' ? $this->signer->verify($raw) : null;
    }

    /** Existing or newly issued id (the response listener then sets the cookie). */
    public function getVisitorId(): string
    {
        if ($this->visitorId === null) {
            $this->visitorId = $this->peekVisitorId();
            if ($this->visitorId === null) {
                $this->visitorId = CookieSigner::generateId();
                $this->issued = true;
            }
        }

        return $this->visitorId;
    }

    public function wasIssued(): bool
    {
        return $this->issued;
    }

    public function signedValue(): ?string
    {
        return $this->visitorId !== null ? $this->signer->sign($this->visitorId) : null;
    }

    public function reset(): void
    {
        $this->visitorId = null;
        $this->issued = false;
    }
}
