<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\Command;

use ElevateDxp\Automation\N8n\N8nBlueprintGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:automation:n8n-export', description: 'Export a Elevate DXP webhook subscription as an importable n8n blueprint')]
final class N8nExportCommand extends Command
{
    public function __construct(
        private readonly N8nBlueprintGenerator $generator,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subscription', InputArgument::OPTIONAL, 'Webhook subscription (omit to list)');
        $this->addOption('save', null, InputOption::VALUE_NONE, 'Write to var/elevate-dxp/n8n/<name>.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->generator->isEnabled()) {
            $io->error('Automation is disabled (elevate_dxp.automation.enabled: false).');

            return Command::FAILURE;
        }
        $name = $input->getArgument('subscription');
        if ($name === null) {
            $io->listing($this->generator->subscriptionNames());

            return Command::SUCCESS;
        }

        $blueprint = $this->generator->fromWebhook((string) $name);
        if ($blueprint === null) {
            $io->error("Unknown subscription '$name'. Available: ".implode(', ', $this->generator->subscriptionNames()));

            return Command::INVALID;
        }
        $json = $this->generator->toJson($blueprint);

        if ($input->getOption('save')) {
            $dir = $this->projectDir.'/var/elevate-dxp/n8n';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $io->error('Cannot create '.$dir);

                return Command::FAILURE;
            }
            $file = $dir.'/'.preg_replace('/[^a-z0-9_-]/i', '_', (string) $name).'.json';
            file_put_contents($file, $json);
            $io->success('Saved n8n blueprint → '.$file);

            return Command::SUCCESS;
        }

        $output->writeln($json);

        return Command::SUCCESS;
    }
}
