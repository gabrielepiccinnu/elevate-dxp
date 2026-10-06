<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Repository;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Model\Experiment;
use Symfony\Contracts\Service\ResetInterface;

final class ExperimentRepository implements ResetInterface
{
    /** @var list<Experiment>|null */
    private ?array $running = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Experiment> running experiments (cached per request) */
    public function findRunning(): array
    {
        if ($this->running !== null) {
            return $this->running;
        }
        try {
            $rows = $this->db->fetchAllAssociative("SELECT * FROM edxp_experiment WHERE status = 'running' ORDER BY id ASC");
        } catch (\Throwable) {
            return $this->running = []; // not installed yet: never break the frontend
        }

        return $this->running = array_map(Experiment::fromRow(...), $rows);
    }

    public function findByKey(string $key): ?Experiment
    {
        $row = $this->db->fetchAssociative('SELECT * FROM edxp_experiment WHERE exp_key = ?', [$key]);

        return $row === false ? null : Experiment::fromRow($row);
    }

    public function find(int $id): ?Experiment
    {
        $row = $this->db->fetchAssociative('SELECT * FROM edxp_experiment WHERE id = ?', [$id]);

        return $row === false ? null : Experiment::fromRow($row);
    }

    /** @return list<Experiment> */
    public function findAll(): array
    {
        return array_map(Experiment::fromRow(...), $this->db->fetchAllAssociative('SELECT * FROM edxp_experiment ORDER BY exp_key'));
    }

    /**
     * @param list<array<string, mixed>> $variants serialised variants (see Variant::toArray())
     */
    public function updateVariants(int $id, array $variants): void
    {
        $this->db->update('edxp_experiment', ['variants' => json_encode($variants, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)], ['id' => $id]);
        $this->reset();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update('edxp_experiment', ['status' => $status], ['id' => $id]);
        $this->reset();
    }

    public function reset(): void
    {
        $this->running = null;
    }
}
