<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Repository;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Experiment\AssignmentStoreInterface;

final class AssignmentRepository implements AssignmentStoreInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function getVariant(string $visitorId, int $experimentId): ?string
    {
        try {
            $val = $this->db->fetchOne(
                'SELECT variant_key FROM edxp_assignment WHERE visitor_id = ? AND experiment_id = ?',
                [$visitorId, $experimentId],
            );
        } catch (\Throwable) {
            return null;
        }

        return $val === false ? null : (string) $val;
    }

    public function store(string $visitorId, int $experimentId, string $variantKey, bool $force = false): bool
    {
        try {
            $affected = $this->db->executeStatement(
                $force
                    ? 'INSERT INTO edxp_assignment (visitor_id, experiment_id, variant_key, forced, created_at) VALUES (?, ?, ?, 1, NOW())
                       ON DUPLICATE KEY UPDATE variant_key = VALUES(variant_key), forced = 1'
                    : 'INSERT IGNORE INTO edxp_assignment (visitor_id, experiment_id, variant_key, forced, created_at) VALUES (?, ?, ?, 0, NOW())',
                [$visitorId, $experimentId, $variantKey],
            );
        } catch (\Throwable) {
            return false; // stickiness persistence must never break the request
        }

        return $affected === 1;
    }

    /** @return array<string,string> experiment key => variant for one visitor */
    public function forVisitor(string $visitorId): array
    {
        return $this->db->fetchAllKeyValue(
            'SELECT e.exp_key, a.variant_key FROM edxp_assignment a JOIN edxp_experiment e ON e.id = a.experiment_id WHERE a.visitor_id = ?',
            [$visitorId],
        );
    }
}
