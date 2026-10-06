<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Data retention for the Experiments module (GDPR storage limitation).
 *
 *  - edxp_event rows older than --events-days are deleted;
 *  - edxp_visitor_profile rows not seen for --profiles-days are deleted, together with the
 *    edxp_assignment rows of those visitors when they have no recent event (an event newer than
 *    the more recent of the two cut-offs), so a returning visitor keeps a consistent variant.
 *
 * Deletes run in chunks (--chunk rows per statement) and are idempotent: running the command twice
 * deletes nothing the second time. --dry-run only counts.
 */
#[AsCommand(name: 'elevate-dxp:experiments:prune', description: 'Delete old experiment events, visitor profiles and orphaned assignments (data retention)')]
final class PruneCommand extends Command
{
    public const DEFAULT_EVENTS_DAYS = 395;
    public const DEFAULT_PROFILES_DAYS = 395;
    public const DEFAULT_CHUNK = 1000;

    private const EVENT = 'edxp_event';
    private const PROFILE = 'edxp_visitor_profile';
    private const ASSIGNMENT = 'edxp_assignment';

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('events-days', null, InputOption::VALUE_REQUIRED, 'Keep the events of the last N days', (string) self::DEFAULT_EVENTS_DAYS);
        $this->addOption('profiles-days', null, InputOption::VALUE_REQUIRED, 'Keep the visitor profiles seen in the last N days', (string) self::DEFAULT_PROFILES_DAYS);
        $this->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Rows deleted per statement', (string) self::DEFAULT_CHUNK);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows that would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $values = [];
        foreach (['events-days', 'profiles-days', 'chunk'] as $name) {
            $raw = (string) $input->getOption($name);
            if (!ctype_digit($raw) || (int) $raw < 1) {
                $io->error("--$name must be a positive integer.");

                return Command::INVALID;
            }
            $values[$name] = (int) $raw;
        }
        $c = self::cutoffs(new \DateTimeImmutable(), $values['events-days'], $values['profiles-days']);

        try {
            if ($input->getOption('dry-run')) {
                $counts = $this->count($c);
                $io->success(\sprintf('Dry run: would delete %d event(s) older than %s, %d visitor profile(s) not seen since %s and %d assignment(s).',
                    $counts['events'], $c['events'], $counts['profiles'], $c['profiles'], $counts['assignments']));

                return Command::SUCCESS;
            }
            $deleted = $this->prune($c, $values['chunk']);
        } catch (\Throwable $e) {
            $io->error('Prune failed: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Deleted %d event(s), %d visitor profile(s) and %d assignment(s).', $deleted['events'], $deleted['profiles'], $deleted['assignments']));

        return Command::SUCCESS;
    }

    /**
     * Cut-off timestamps ("Y-m-d H:i:s"). recentSince is the more recent of the two cut-offs: an
     * event at or after it keeps a visitor's assignments even when the profile is removed.
     *
     * @return array{events:string,profiles:string,recentSince:string}
     */
    public static function cutoffs(\DateTimeImmutable $now, int $eventsDays, int $profilesDays): array
    {
        $events = $now->modify(\sprintf('-%d days', max(1, $eventsDays)));
        $profiles = $now->modify(\sprintf('-%d days', max(1, $profilesDays)));

        return [
            'events' => $events->format('Y-m-d H:i:s'),
            'profiles' => $profiles->format('Y-m-d H:i:s'),
            'recentSince' => max($events, $profiles)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array{events:string,profiles:string,recentSince:string} $c
     *
     * @return array{events:int,profiles:int,assignments:int}
     */
    public function prune(array $c, int $chunk = self::DEFAULT_CHUNK): array
    {
        $chunk = max(1, $chunk);
        $out = ['events' => 0, 'profiles' => 0, 'assignments' => 0];

        do {
            $n = (int) $this->db->executeStatement('DELETE FROM '.self::EVENT.' WHERE created_at < ? ORDER BY id LIMIT '.$chunk, [$c['events']]);
            $out['events'] += $n;
        } while ($n >= $chunk);

        while (true) {
            $ids = array_values(array_map('strval', $this->db->fetchFirstColumn(
                'SELECT visitor_id FROM '.self::PROFILE.' WHERE last_seen < ? ORDER BY visitor_id LIMIT '.$chunk,
                [$c['profiles']],
            )));
            if ($ids === []) {
                break;
            }
            $in = implode(',', array_fill(0, \count($ids), '?'));
            $out['assignments'] += (int) $this->db->executeStatement(
                self::assignmentDeleteSql($in),
                [...$ids, $c['recentSince']],
            );
            $n = (int) $this->db->executeStatement(
                'DELETE FROM '.self::PROFILE.' WHERE visitor_id IN ('.$in.') AND last_seen < ?',
                [...$ids, $c['profiles']],
            );
            $out['profiles'] += $n;
            if ($n === 0 || \count($ids) < $chunk) {
                break; // last chunk, or every candidate was touched concurrently (nothing left to do)
            }
        }

        return $out;
    }

    /**
     * @param array{events:string,profiles:string,recentSince:string} $c
     *
     * @return array{events:int,profiles:int,assignments:int}
     */
    public function count(array $c): array
    {
        return [
            'events' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::EVENT.' WHERE created_at < ?', [$c['events']]),
            'profiles' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::PROFILE.' WHERE last_seen < ?', [$c['profiles']]),
            'assignments' => (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM '.self::ASSIGNMENT.' a INNER JOIN '.self::PROFILE.' p ON p.visitor_id = a.visitor_id'
                .' WHERE p.last_seen < ? AND NOT EXISTS (SELECT 1 FROM '.self::EVENT.' e WHERE e.visitor_id = a.visitor_id AND e.created_at >= ?)',
                [$c['profiles'], $c['recentSince']],
            ),
        ];
    }

    /** DELETE of the assignments of the given visitors (IN list placeholders) without a recent event. */
    public static function assignmentDeleteSql(string $inPlaceholders): string
    {
        return 'DELETE FROM '.self::ASSIGNMENT.' WHERE visitor_id IN ('.$inPlaceholders.')'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::EVENT.' e WHERE e.visitor_id = '.self::ASSIGNMENT.'.visitor_id AND e.created_at >= ?)';
    }
}
