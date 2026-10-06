<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Repository;

use Doctrine\DBAL\Connection;

final class VisitorProfileRepository
{
    public const SESSION_GAP_SECONDS = 1800;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Profile row with utm_first, utm_last and target_groups decoded from JSON, or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $visitorId): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM edxp_visitor_profile WHERE visitor_id = ?', [$visitorId]);
        } catch (\Throwable) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        foreach (['utm_first', 'utm_last', 'target_groups'] as $k) {
            $row[$k] = \is_string($row[$k]) ? (json_decode($row[$k], true) ?: []) : [];
        }

        return $row;
    }

    /**
     * Upserts the profile for one page view. Best effort: never throws.
     *
     * @param array<string,string>|null $utm
     * @param list<string>              $targetGroups
     */
    public function touch(string $visitorId, string $url, ?string $referrer, ?array $utm, ?string $targetingVisitorId, array $targetGroups): void
    {
        $url = mb_substr($url, 0, 500);
        $referrer = $referrer !== null ? mb_substr($referrer, 0, 500) : null;
        $utmJson = $utm ? json_encode($utm, \JSON_UNESCAPED_SLASHES) : null;
        $tgJson = $targetGroups !== [] ? json_encode(array_values($targetGroups), \JSON_UNESCAPED_UNICODE) : null;

        try {
            $this->db->executeStatement(
                'INSERT INTO edxp_visitor_profile
                    (visitor_id, targeting_visitor_id, first_seen, last_seen, pageviews, sessions, first_url, last_url, referrer, utm_first, utm_last, target_groups)
                 VALUES (:vid, :tvid, NOW(), NOW(), 1, 1, :url, :url, :ref, :utm, :utm, :tg)
                 ON DUPLICATE KEY UPDATE
                    sessions = sessions + IF(last_seen < NOW() - INTERVAL '.self::SESSION_GAP_SECONDS.' SECOND, 1, 0),
                    last_seen = NOW(),
                    pageviews = pageviews + 1,
                    last_url = VALUES(last_url),
                    targeting_visitor_id = COALESCE(VALUES(targeting_visitor_id), targeting_visitor_id),
                    utm_first = COALESCE(utm_first, VALUES(utm_first)),
                    utm_last = COALESCE(VALUES(utm_last), utm_last),
                    target_groups = COALESCE(VALUES(target_groups), target_groups)',
                ['vid' => $visitorId, 'tvid' => $targetingVisitorId, 'url' => $url, 'ref' => $referrer, 'utm' => $utmJson, 'tg' => $tgJson],
            );
        } catch (\Throwable) {
        }
    }
}
