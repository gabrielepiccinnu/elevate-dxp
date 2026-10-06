<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Command;

use ElevateDxp\Workflow\Config\WorkflowConfigWriter;
use ElevateDxp\Workflow\Store\WorkflowYamlStore;
use ElevateDxp\Workflow\Validator\WorkflowValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:workflow:list', description: 'List workflow definitions with validation and apply status')]
final class WorkflowListCommand extends Command
{
    public function __construct(
        private readonly WorkflowYamlStore $store,
        private readonly WorkflowValidator $validator,
        private readonly WorkflowConfigWriter $writer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->store->names() as $name) {
            $def = $this->store->load($name);
            $errs = $def ? $this->validator->validate($def) : ['unreadable'];
            $rows[] = [
                $name,
                $def ? \count($def->places) : 0,
                $def ? \count($def->transitions) : 0,
                $errs === [] ? 'valid' : implode('; ', $errs),
                $def ? $this->writer->status($def) : '-',
            ];
        }
        if ($rows === []) {
            $io->warning('No workflow definitions. Run elevate-dxp:workflow:demo-seed.');

            return Command::SUCCESS;
        }
        $io->table(['Name', 'Places', 'Transitions', 'Status', 'Applied'], $rows);

        return Command::SUCCESS;
    }
}
