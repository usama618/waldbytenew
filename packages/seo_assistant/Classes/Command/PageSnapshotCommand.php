<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\PageSnapshotService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:pages:snapshot', description: 'Snapshot TYPO3 page SEO fields and visible content text.')]
final class PageSnapshotCommand extends Command
{
    public function __construct(
        private readonly PageSnapshotService $pageSnapshotService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL for generated page URLs.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read pages but do not write snapshot rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->pageSnapshotService->snapshot(
                $input->getOption('base-url') !== null ? (string)$input->getOption('base-url') : null,
                (bool)$input->getOption('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->alertService->record(
                'cron',
                'Page snapshot command failed',
                $exception->getMessage(),
                [
                    'command' => 'seo:pages:snapshot',
                    'base_url' => $input->getOption('base-url') !== null ? (string)$input->getOption('base-url') : '',
                    'dry_run' => (bool)$input->getOption('dry-run'),
                ],
                'error'
            );
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Page snapshot complete for %s: processed %d pages, stored %d snapshots.',
            $result['baseUrl'],
            $result['processed'],
            $result['stored']
        ));

        return Command::SUCCESS;
    }
}
