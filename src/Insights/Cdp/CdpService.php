<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Cdp;

use Doctrine\DBAL\Connection;

/**
 * CDP-lite on top of the first-party data collected by the experiments bundle
 * (edxp_visitor_profile, edxp_event, edxp_assignment). No PII is stored.
 */
final class CdpService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Adds conversions, experiment count and segments to profile rows.
     *
     * @param list<array<string, mixed>> $rows profile rows, each with a "visitor_id"
     *
     * @return list<array<string, mixed>>
     */
    public function enrich(array $rows): array
    {
        $ids = array_column($rows, 'visitor_id');
        if ($ids === []) {
            return $rows;
        }
        $conv = $this->db->fetchAllKeyValue(
            "SELECT visitor_id, COUNT(*) FROM edxp_event WHERE event_type = 'conversion' AND visitor_id IN (?) GROUP BY visitor_id",
            [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $exp = $this->db->fetchAllKeyValue(
            'SELECT visitor_id, COUNT(*) FROM edxp_assignment WHERE visitor_id IN (?) GROUP BY visitor_id',
            [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach ($rows as &$row) {
            $row['conversions'] = (int) ($conv[$row['visitor_id']] ?? 0);
            $row['experiments'] = (int) ($exp[$row['visitor_id']] ?? 0);
            $row['segments'] = implode(', ', SegmentEvaluator::segmentsOf($row));
        }

        return $rows;
    }

    /**
     * Visitor count and share per built-in segment.
     *
     * @return list<array{segment: string, description: string, visitors: int, 'share_%': float|int}>
     */
    public function summary(): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_visitor_profile');
        $counts = [
            'converters' => (int) $this->db->fetchOne("SELECT COUNT(DISTINCT visitor_id) FROM edxp_event WHERE event_type = 'conversion'"),
            'engaged' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_visitor_profile WHERE pageviews >= 5'),
            'returning' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_visitor_profile WHERE sessions >= 2'),
            'campaign' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_visitor_profile WHERE utm_last IS NOT NULL'),
            'experiment_exposed' => (int) $this->db->fetchOne('SELECT COUNT(DISTINCT visitor_id) FROM edxp_assignment'),
        ];
        $rows = [];
        foreach (SegmentEvaluator::SEGMENTS as $key => $label) {
            $rows[] = ['segment' => $key, 'description' => $label, 'visitors' => $counts[$key], 'share_%' => $total > 0 ? round($counts[$key] / $total * 100, 1) : 0];
        }

        return $rows;
    }

    /**
     * Event timeline + experiment assignments of one visitor, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $visitorId, int $limit = 200): array
    {
        $events = $this->db->fetchAllAssociative(
            'SELECT created_at AS time, event_name AS event, event_type AS type, event_value AS value, experiment_key, variant_key, url
             FROM edxp_event WHERE visitor_id = ? ORDER BY id DESC LIMIT '.max(1, min(1000, $limit)),
            [$visitorId],
        );
        $assignments = $this->db->fetchAllAssociative(
            "SELECT a.created_at AS time, CONCAT('assigned ', e.exp_key) AS event, IF(a.forced, 'forced', 'assignment') AS type, NULL AS value, e.exp_key AS experiment_key, a.variant_key, NULL AS url
             FROM edxp_assignment a JOIN edxp_experiment e ON e.id = a.experiment_id WHERE a.visitor_id = ?",
            [$visitorId],
        );
        $all = array_merge($events, $assignments);
        usort($all, static fn ($a, $b) => strcmp((string) $b['time'], (string) $a['time']));

        return $all;
    }
}
