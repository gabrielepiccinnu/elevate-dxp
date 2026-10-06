<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Command;

use ElevateDxp\Workflow\Config\WorkflowConfigWriter;
use ElevateDxp\Workflow\Store\WorkflowYamlStore;
use ElevateDxp\Workflow\Validator\WorkflowValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:workflow:apply', description: 'Write a stored workflow to the opendxp.workflows config (then run cache:clear)')]
final class WorkflowApplyCommand extends Command
{
    public function __construct(
        private readonly WorkflowYamlStore $store,
        private readonly WorkflowValidator $validator,
        private readonly WorkflowConfigWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Workflow name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $def = $this->store->load((string) $input->getArgument('name'));
        if ($def === null) {
            $io->error('Workflow not found.');

            return Command::FAILURE;
        }
        $errors = $this->validator->validate($def);
        if ($errors !== []) {
            $io->error($errors);

            return Command::FAILURE;
        }
        $io->success('Written '.$this->writer->write($def).'. Run "bin/console cache:clear" to activate it.');

        return Command::SUCCESS;
    }
}
