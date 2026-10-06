<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Command;

use ElevateDxp\Translation\Provider\ProviderRegistry;
use ElevateDxp\Translation\Xliff\XliffService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:translation:demo', description: 'XLIFF round-trip + auto-translate via the configured provider')]
final class TranslationDemoCommand extends Command
{
    public function __construct(
        private readonly XliffService $xliff,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source language', 'en');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target language', 'de');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider override (pseudo|libretranslate)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = (string) $input->getOption('from');
        $to = (string) $input->getOption('to');
        $provider = $this->providers->get($input->getOption('provider'));

        $units = [
            'product.name' => 'Aurora Chair',
            'product.cta' => 'Add to cart',
            'product.desc' => 'A comfortable demo chair.',
        ];

        // 1) export to XLIFF
        $xml = $this->xliff->toXliff($units, $from, $to);
        $io->writeln('1) XLIFF export: '.($this->xliff->isValid($xml) ? '<info>valid</info>' : '<error>invalid</error>').' ('.\strlen($xml).' bytes)');

        // 2) auto-fill targets via provider
        $filled = $this->xliff->fillTargets($xml, static fn (string $t): string => $provider->translate($t, $from, $to));

        // 3) import back
        $parsed = $this->xliff->fromXliff($filled);
        $io->section(\sprintf('Round-trip %s→%s via "%s"', $from, $to, $provider->name()));
        $rows = [];
        foreach ($parsed as $key => $u) {
            $rows[] = [$key, $u['source'], $u['target']];
        }
        $io->table(['Key', 'Source', 'Target'], $rows);

        $io->success('Translation XLIFF round-trip OK.');

        return Command::SUCCESS;
    }
}
