<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Command;

use ElevateDxp\Translation\Provider\ProviderRegistry;
use ElevateDxp\Translation\Xliff\XliffService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Auto-translates empty <target>s of an XLIFF 1.2 file, e.g. one exported by the native XliffBundle. */
#[AsCommand(name: 'elevate-dxp:translation:fill-xliff', description: 'Fill empty XLIFF targets via a translation provider')]
final class TranslationFillXliffCommand extends Command
{
    public function __construct(
        private readonly XliffService $xliff,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('input', InputArgument::REQUIRED, 'XLIFF file to read');
        $this->addArgument('output', InputArgument::OPTIONAL, 'File to write (default: stdout)');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider key (default: configured provider)');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source language when the file does not declare one', 'en');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target language when the file does not declare one', 'de');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('input');
        $xml = is_file($file) ? (string) file_get_contents($file) : '';
        if (!$this->xliff->isValid($xml)) {
            $io->error("'$file' is not a readable XLIFF document.");

            return Command::FAILURE;
        }
        $name = $input->getOption('provider');
        try {
            $provider = $name === null ? $this->providers->get() : $this->providers->getOrFail((string) $name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $from = (string) $input->getOption('from');
        $to = (string) $input->getOption('to');
        $filled = $this->xliff->fillTargetsPerFile($xml, static fn (string $t, ?string $f, ?string $l): string => $provider->translate($t, $f ?? $from, $l ?? $to));

        $target = $input->getArgument('output');
        if ($target === null) {
            $output->write($filled);

            return Command::SUCCESS;
        }
        file_put_contents((string) $target, $filled);
        $io->success(\sprintf('Wrote %s (%d units, provider "%s").', $target, $this->xliff->countUnits($filled), $provider->name()));

        return Command::SUCCESS;
    }
}
