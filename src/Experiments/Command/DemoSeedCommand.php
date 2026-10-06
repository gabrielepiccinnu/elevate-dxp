<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Command;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Experiment\VariantTargetGroupManager;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\Rule;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\TargetGroup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds a runnable demo: a "VIP" audience (rule: ?vip=1 via the edxp_query_param condition)
 * and a running "hero" A/B experiment with variant target groups and goal "signup".
 */
#[AsCommand(name: 'elevate-dxp:experiments:demo-seed', description: 'Create a demo audience, rule and running A/B experiment')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExperimentRepository $experiments,
        private readonly VariantTargetGroupManager $groups,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $vip = TargetGroup::getByName('VIP visitors') ?? new TargetGroup();
        if ($vip->getId() === null) {
            $vip->setName('VIP visitors');
            $vip->setDescription('Demo audience: visitors who arrived with ?vip=1');
            $vip->setThreshold(1);
            $vip->setActive(true);
            $vip->save();
        }

        $rule = Rule::getByName('Elevate DXP demo: VIP via query param') ?? new Rule();
        if ($rule->getId() === null) {
            $rule->setName('Elevate DXP demo: VIP via query param');
            $rule->setScope(Rule::SCOPE_HIT);
            $rule->setActive(true);
            $rule->setConditions([['type' => 'edxp_query_param', 'name' => 'vip', 'mode' => 'equals', 'value' => '1', 'operator' => null, 'bracketLeft' => false, 'bracketRight' => false]]);
            $rule->setActions([['type' => 'assign_target_group', 'targetGroup' => $vip->getId(), 'weight' => 5]]);
            $rule->save();
        }

        if ($this->experiments->findByKey('hero') === null) {
            $this->db->insert('edxp_experiment', [
                'exp_key' => 'hero',
                'name' => 'Homepage hero headline',
                'description' => 'Hypothesis: a benefit-led headline increases sign-ups.',
                'status' => 'running',
                'traffic' => 100,
                'goal_event' => 'signup',
                'variants' => json_encode([
                    ['key' => 'A', 'weight' => 50, 'payload' => ['headline' => 'Welcome to our store']],
                    ['key' => 'B', 'weight' => 50, 'payload' => ['headline' => 'Save 20% on your first order']],
                ]),
            ]);
        }
        $experiment = $this->experiments->findByKey('hero');
        $this->groups->ensure($experiment);

        $io->success([
            'Target group "VIP visitors" and rule "?vip=1" ready.',
            'Experiment "hero" running with variants A/B, goal "signup".',
            "In a Twig template: {{ edxp_variant_payload('hero').headline }} and <button data-edxp-track=\"signup\">.",
        ]);

        return Command::SUCCESS;
    }
}
