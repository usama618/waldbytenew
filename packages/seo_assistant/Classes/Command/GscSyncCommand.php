<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\SearchConsoleService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:gsc:sync', description: 'Import Google Search Console Search Analytics rows.')]
final class GscSyncCommand extends Command
{
    public function __construct(
        private readonly SearchConsoleService $searchConsoleService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $yesterday = new DateTimeImmutable('yesterday');
        $startDate = $yesterday->modify('-27 days');

        $this
            ->addOption('start-date', null, InputOption::VALUE_REQUIRED, 'Start date in YYYY-MM-DD format.', $startDate->format('Y-m-d'))
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'End date in YYYY-MM-DD format.', $yesterday->format('Y-m-d'))
            ->addOption('site-url', null, InputOption::VALUE_REQUIRED, 'Search Console property, e.g. sc-domain:waldbyte.de.')
            ->addOption('dimensions', null, InputOption::VALUE_REQUIRED, 'Comma-separated Search Console dimensions.', 'page,query')
            ->addOption('row-limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to fetch. Google caps this at 25000.', '25000')
            ->addOption('search-type', null, InputOption::VALUE_REQUIRED, 'Search type, usually web.', 'web')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch data but do not write database rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->searchConsoleService->sync(
                (string)$input->getOption('start-date'),
                (string)$input->getOption('end-date'),
                $input->getOption('site-url') !== null ? (string)$input->getOption('site-url') : null,
                array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('dimensions'))))),
                (int)$input->getOption('row-limit'),
                (string)$input->getOption('search-type'),
                (bool)$input->getOption('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->alertService->record(
                'gsc',
                'GSC sync failed',
                $exception->getMessage(),
                [
                    'command' => 'seo:gsc:sync',
                    'start_date' => (string)$input->getOption('start-date'),
                    'end_date' => (string)$input->getOption('end-date'),
                    'site_url' => $input->getOption('site-url') !== null ? (string)$input->getOption('site-url') : '',
                    'dimensions' => (string)$input->getOption('dimensions'),
                    'search_type' => (string)$input->getOption('search-type'),
                    'dry_run' => (bool)$input->getOption('dry-run'),
                ],
                'error'
            );
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'GSC sync complete for %s: fetched %d rows, stored %d rows (%s to %s).',
            $result['siteUrl'],
            $result['fetched'],
            $result['stored'],
            $result['startDate'],
            $result['endDate']
        ));

        return Command::SUCCESS;
    }
}
