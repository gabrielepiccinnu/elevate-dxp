<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Command;

use ElevateDxp\DamMetadata\Admin\DamMetadataSchemasResource;
use ElevateDxp\DamMetadata\Metadata\MetadataApplier;
use ElevateDxp\DamMetadata\Metadata\PredefinedSync;
use ElevateDxp\DamMetadata\Metadata\SchemaService;
use OpenDxp\Model\Asset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:dam:metadata', description: 'DAM metadata: list schemas, sync predefined metadata, bulk-apply, or show an asset')]
final class DamMetadataCommand extends Command
{
    public function __construct(
        private readonly SchemaService $schemas,
        private readonly MetadataApplier $applier,
        private readonly PredefinedSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'schemas | sync | apply | apply-folder | show');
        $this->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Schema name');
        $this->addOption('field', null, InputOption::VALUE_REQUIRED, 'Field name');
        $this->addOption('value', null, InputOption::VALUE_REQUIRED, 'Value to apply');
        $this->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Asset folder path (for apply-folder)');
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite existing values (apply without --field, apply-folder); apply with --field always sets the value');
        $this->addOption('asset', null, InputOption::VALUE_REQUIRED, 'Asset id (for show)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        if ($action === 'schemas') {
            foreach ($this->schemas->all() as $name => $cfg) {
                $fields = array_map(static fn (array $f): string => $f['name'].':'.$f['type'], $cfg['fields'] ?? []);
                $io->writeln(\sprintf('<info>%s</info> (path %s, types %s)', $name,
                    $cfg['path_prefix'] ?? '/', implode('|', $cfg['asset_types'] ?? []) ?: 'any'));
                $io->writeln('   fields: '.implode(', ', $fields));
            }

            return Command::SUCCESS;
        }

        if ($action === 'sync') {
            try {
                $r = $this->sync->sync($input->getOption('schema'));
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('Created %d predefined definition(s)%s; %d already present.',
                \count($r['created']), $r['created'] === [] ? '' : ': '.implode(', ', $r['created']), $r['existing']));

            return Command::SUCCESS;
        }

        if ($action === 'apply') {
            $field = $input->getOption('field');
            $field = $field === null || $field === '' ? null : (string) $field;
            $r = $this->applier->apply(
                (string) $input->getOption('schema'),
                $field,
                $input->getOption('value'),
                'cli',
                MetadataApplier::resolveOverwrite($field, (bool) $input->getOption('overwrite')),
            );
            if ($r['error'] !== null) {
                $io->error($r['error']);

                return Command::FAILURE;
            }
            $io->success(\sprintf('Applied to %d/%d matching asset(s), %d unchanged (existing values kept%s).',
                $r['updated'], $r['matched'], $r['unchanged'], $field === null && !$input->getOption('overwrite') ? '; use --overwrite to replace them' : ''));

            return Command::SUCCESS;
        }

        if ($action === 'apply-folder') {
            $field = $input->getOption('field');
            $r = $this->applier->applyToFolder(
                (string) ($input->getOption('folder') ?? '/'),
                (string) $input->getOption('schema'),
                $field === null || $field === '' ? null : (string) $field,
                $input->getOption('value'),
                (bool) $input->getOption('overwrite'),
            );
            if ($r['error'] !== null) {
                $io->error($r['error']);

                return Command::FAILURE;
            }
            $io->success(DamMetadataSchemasResource::summary($r));

            return Command::SUCCESS;
        }

        if ($action === 'show') {
            $asset = Asset::getById((int) $input->getOption('asset'));
            if (!$asset instanceof Asset) {
                $io->error('Asset not found.');

                return Command::INVALID;
            }
            $rows = [];
            foreach ((array) $asset->getMetadata(null, null, false, true) as $m) {
                $rows[] = [$m['name'] ?? '?', $m['type'] ?? '?', \is_scalar($m['data'] ?? null) ? (string) $m['data'] : json_encode($m['data'] ?? null)];
            }
            $io->title('Metadata for asset #'.$asset->getId().' '.$asset->getFullPath());
            $rows === [] ? $io->writeln('(none)') : $io->table(['Name', 'Type', 'Value'], $rows);

            return Command::SUCCESS;
        }

        $io->error("Unknown action '$action' (use: schemas | sync | apply | apply-folder | show).");

        return Command::INVALID;
    }
}
