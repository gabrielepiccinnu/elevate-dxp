<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Command;

use ElevateDxp\Workflow\Mermaid\MermaidRenderer;
use ElevateDxp\Workflow\Model\DemoWorkflow;
use ElevateDxp\Workflow\Store\WorkflowYamlStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:workflow:demo-seed', description: 'Seed a demo product_review workflow and print its Mermaid diagram')]
final class WorkflowDemoSeedCommand extends Command
{
    public function __construct(
        private readonly WorkflowYamlStore $store,
        private readonly MermaidRenderer $mermaid,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $def = DemoWorkflow::create();
        $this->store->save($def);

        $io->success(\sprintf('Seeded workflow "%s".', $def->name));
        $io->section('Mermaid diagram');
        $io->writeln($this->mermaid->render($def));

        return Command::SUCCESS;
    }
}
