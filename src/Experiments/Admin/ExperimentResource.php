<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Experiments\Experiment\ExperimentAssigner;
use ElevateDxp\Experiments\Experiment\VariantTargetGroupManager;
use ElevateDxp\Experiments\Installer\ExperimentsInstaller;
use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Model\Variant;
use ElevateDxp\Experiments\Report\ExperimentReport;
use ElevateDxp\Experiments\Report\Stats;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\TargetGroup;

final class ExperimentResource extends AbstractDbalResource
{
    private const VARIANTS_HELP = 'JSON list, e.g. [{"key":"A","weight":50},{"key":"B","weight":50,"payload":{"headline":"New!"}}]. '
        .'"A"/"control" is the baseline. target_group_id is filled by the "Create variant target groups" action.';

    public function __construct(
        Connection $db,
        private readonly ExperimentRepository $experiments,
        private readonly VariantTargetGroupManager $groups,
        private readonly ExperimentReport $report,
    ) {
        parent::__construct($db);
    }

    public function getKey(): string
    {
        return 'experiments';
    }

    public function getLabel(): string
    {
        return 'A/B Experiments';
    }

    public function getGroup(): string
    {
        return 'Marketing';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_experiment';
    }

    public function getPermission(): string
    {
        return ExperimentsInstaller::PERMISSION;
    }

    protected function getTable(): string
    {
        return 'edxp_experiment';
    }

    /** @return list<string> */
    protected function getSearchColumns(): array
    {
        return ['exp_key', 'name', 'goal_event'];
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        $groups = ['' => '— all visitors —'];
        foreach ((new TargetGroup\Listing())->getTargetGroups() as $g) {
            if (!str_starts_with($g->getName(), 'exp:')) {
                $groups[$g->getId()] = $g->getName();
            }
        }

        return [
            'panel' => 'crud',
            'fields' => [
                Field::id(),
                Field::text('exp_key', 'Key', ['required' => true, 'width' => 140, 'help' => 'Used in Twig: edxp_variant(\'key\'). Letters, digits, _ and -.']),
                Field::text('name', 'Name', ['required' => true]),
                Field::select('status', 'Status', Experiment::STATUSES, ['required' => true, 'default' => 'draft', 'width' => 100]),
                Field::number('traffic', 'Traffic %', ['default' => 100, 'width' => 80, 'help' => 'Share of eligible visitors entering the experiment.']),
                Field::text('goal_event', 'Goal event', ['width' => 120, 'help' => 'Conversion event name, sent via edxp.track(\'signup\') or data-edxp-track="signup".']),
                Field::select('target_group_id', 'Audience (target group)', $groups, ['grid' => false, 'help' => 'Only visitors in this target group enter the experiment.']),
                Field::text('url_pattern', 'URL scope', ['grid' => false, 'help' => 'Path prefix (/shop) or regex (#^/shop/.+#). Empty = whole site.']),
                Field::json('variants', 'Variants', ['required' => true, 'help' => self::VARIANTS_HELP, 'default' => [['key' => 'A', 'weight' => 50], ['key' => 'B', 'weight' => 50]]]),
                Field::datetime('start_at', 'Start', ['grid' => false]),
                Field::datetime('end_at', 'End', ['grid' => false]),
                Field::textarea('description', 'Hypothesis / notes'),
                Field::datetime('updated_at', 'Updated', ['readOnly' => true, 'width' => 150]),
            ],
            'actions' => [
                Action::record('start', 'Start', ['iconCls' => 'opendxp_icon_play', 'confirm' => 'Start the experiment? Visitors will be assigned immediately.']),
                Action::record('pause', 'Pause', ['iconCls' => 'opendxp_icon_pause']),
                Action::record('complete', 'Complete', ['iconCls' => 'opendxp_icon_stop']),
                Action::record('variant_groups', 'Create variant target groups', ['iconCls' => 'opendxp_icon_target_groups']),
                Action::record('results', 'Results', ['iconCls' => 'elevatedxp_icon_report']),
                Action::record('simulate', 'Simulate split', [
                    'iconCls' => 'opendxp_icon_calculator',
                    'params' => [Field::number('visitors', 'Simulated visitors', ['default' => 10000])],
                ]),
                Action::record('sample_size', 'Sample size', [
                    'iconCls' => 'opendxp_icon_info',
                    'params' => [
                        Field::number('baseline', 'Baseline conversion rate %', ['default' => 3]),
                        Field::number('lift', 'Minimum detectable lift %', ['default' => 10]),
                    ],
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    protected function beforeSave(array $data, ?array $existing): array
    {
        $key = trim((string) ($data['exp_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key)) {
            throw new \InvalidArgumentException('Key must be 1-100 chars: letters, digits, _ or -.');
        }
        $data['exp_key'] = $key;

        $status = (string) ($data['status'] ?? 'draft');
        if (!isset(Experiment::STATUSES[$status])) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        $traffic = (int) ($data['traffic'] ?? 100);
        if ($traffic < 0 || $traffic > 100) {
            throw new \InvalidArgumentException('Traffic must be between 0 and 100.');
        }
        $data['traffic'] = $traffic;

        $raw = $data['variants'] ?? [];
        if (\is_string($raw)) {
            $raw = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        }
        if (!\is_array($raw) || \count($raw) < 2) {
            throw new \InvalidArgumentException('An experiment needs at least two variants.');
        }
        $variants = array_map(static fn ($v) => Variant::fromArray((array) $v), array_values($raw));
        $keys = array_map(static fn (Variant $v) => $v->key, $variants);
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new \InvalidArgumentException('Variant keys must be unique.');
        }
        if (array_sum(array_map(static fn (Variant $v) => $v->weight, $variants)) <= 0) {
            throw new \InvalidArgumentException('The sum of variant weights must be positive.');
        }
        $data['variants'] = array_map(static fn (Variant $v) => $v->toArray(), $variants);

        $pattern = trim((string) ($data['url_pattern'] ?? ''));
        if ($pattern !== '' && $pattern[0] === '#' && @preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('URL scope regex is invalid.');
        }
        $data['url_pattern'] = $pattern !== '' ? $pattern : null;
        $data['target_group_id'] = ($data['target_group_id'] ?? '') !== '' ? (int) $data['target_group_id'] : null;
        $data['goal_event'] = ($g = strtolower(trim((string) ($data['goal_event'] ?? '')))) !== '' ? $g : null;

        foreach (['start_at', 'end_at'] as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            if ($v === '') {
                $data[$k] = null;
                continue;
            }
            try {
                $data[$k] = (new \DateTimeImmutable($v))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                throw new \InvalidArgumentException(\sprintf('Invalid date "%s".', $v));
            }
        }
        if ($data['start_at'] !== null && $data['end_at'] !== null && $data['end_at'] <= $data['start_at']) {
            throw new \InvalidArgumentException('End must be after start.');
        }

        if ($existing !== null && $existing['status'] === 'running' && $existing['variants'] !== $data['variants']) {
            $oldKeys = array_column((array) $existing['variants'], 'key');
            if (array_diff($oldKeys, $keys) !== []) {
                throw new \InvalidArgumentException('Pause the experiment before removing or renaming variants.');
            }
        }

        $this->experiments->reset();

        return $data;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function runAction(string $action, ?string $id, array $params): array
    {
        $experiment = $id !== null ? $this->experiments->find((int) $id) : null;
        if ($experiment === null) {
            throw new \InvalidArgumentException('Experiment not found.');
        }

        switch ($action) {
            case 'start':
                if ($experiment->goalEvent === null) {
                    throw new \InvalidArgumentException('Set a goal event before starting.');
                }
                $this->experiments->setStatus($experiment->id, 'running');

                return Action::message(\sprintf('Experiment "%s" is running.', $experiment->key), true);
            case 'pause':
                $this->experiments->setStatus($experiment->id, 'paused');

                return Action::message('Paused. Assigned visitors keep their variant when you resume.', true);
            case 'complete':
                $this->experiments->setStatus($experiment->id, 'completed');

                return Action::message('Completed. No new visitors will be assigned.', true);
            case 'variant_groups':
                return Action::table($this->groups->ensure($experiment), 'Variant target groups')
                    + ['message' => 'Edit variant content per target group in the page editor ("exp:'.$experiment->key.':<variant>").', 'reload' => true];
            case 'results':
                return Action::table($this->report->build($experiment), \sprintf('Results — %s (goal: %s)', $experiment->name, $experiment->goalEvent ?? '—'));
            case 'simulate':
                $n = max(100, min(200000, (int) ($params['visitors'] ?? 10000)));
                $counts = [];
                for ($i = 0; $i < $n; ++$i) {
                    $vid = bin2hex(random_bytes(8));
                    if ($experiment->traffic < 100 && ExperimentAssigner::bucket($vid.'|gate|'.$experiment->key, 100) >= $experiment->traffic) {
                        $counts['(not enrolled)'] = ($counts['(not enrolled)'] ?? 0) + 1;
                        continue;
                    }
                    $k = ExperimentAssigner::pickWeighted($experiment, $vid);
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
                $rows = [];
                foreach ($counts as $k => $c) {
                    $rows[] = ['variant' => $k, 'visitors' => $c, 'share_%' => round($c / $n * 100, 2)];
                }

                return Action::table($rows, \sprintf('Simulated split over %d visitors', $n));
            case 'sample_size':
                $baseline = (float) ($params['baseline'] ?? 3) / 100;
                $lift = (float) ($params['lift'] ?? 10) / 100;
                $perVariant = Stats::sampleSizePerVariant($baseline, $lift);
                if ($perVariant === null) {
                    throw new \InvalidArgumentException('Baseline must be between 0 and 100 and lift positive.');
                }

                return Action::message(\sprintf(
                    'About %s visitors per variant (%s total for %d variants) to detect a %.1f%% relative lift on a %.2f%% baseline (95%% confidence, 80%% power).',
                    number_format($perVariant), number_format($perVariant * \count($experiment->variants)), \count($experiment->variants), $lift * 100, $baseline * 100,
                ));
        }

        return parent::runAction($action, $id, $params);
    }
}
