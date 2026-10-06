<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\DataProvider;

use ElevateDxp\Experiments\Repository\VisitorProfileRepository;
use ElevateDxp\Experiments\Visitor\VisitorIdResolver;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\DataProvider\DataProviderInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/**
 * First-party profile facts for conditions ("edxp_profile"):
 * sessions (incl. the current one), pageviews, first_seen, UTM first/last touch merged with
 * the UTM parameters of the current request.
 */
final class VisitorProfileDataProvider implements DataProviderInterface
{
    public const PROVIDER_KEY = 'edxp_profile';

    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(
        private readonly VisitorProfileRepository $profiles,
        private readonly VisitorIdResolver $visitorIdResolver,
    ) {
    }

    public function load(VisitorInfo $visitorInfo): void
    {
        $visitorId = $this->visitorIdResolver->peekVisitorId($visitorInfo->getRequest());
        $profile = $visitorId !== null ? $this->profiles->find($visitorId) : null;

        $sessions = 1;
        if ($profile !== null) {
            $idle = time() - strtotime((string) $profile['last_seen']);
            $sessions = (int) $profile['sessions'] + ($idle > VisitorProfileRepository::SESSION_GAP_SECONDS ? 1 : 0);
        }

        $visitorInfo->set(self::PROVIDER_KEY, [
            'known' => $profile !== null,
            'sessions' => $sessions,
            'pageviews' => (int) ($profile['pageviews'] ?? 0),
            'first_seen' => $profile['first_seen'] ?? null,
            'utm_first' => $profile['utm_first'] ?? [],
            'utm' => self::currentUtm($visitorInfo->getRequest()->query->all()) ?: ($profile['utm_last'] ?? []),
        ]);
    }

    /**
     * @param array<array-key, mixed> $query request query parameters
     *
     * @return array<string,string>
     */
    public static function currentUtm(array $query): array
    {
        $utm = [];
        foreach (self::UTM_KEYS as $k) {
            if (isset($query[$k]) && \is_string($query[$k]) && $query[$k] !== '') {
                $utm[$k] = mb_substr($query[$k], 0, 150);
            }
        }

        return $utm;
    }
}
