<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\GscTrendAnalysisService;
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

#[AsCommand(name: 'seo:gsc:analyze-trends', description: 'Analyze historical Google Search Console trends for current TYPO3 pages.')]
final class GscAnalyzeTrendsCommand extends Command
{
    public function __construct(
        private readonly GscTrendAnalysisService $gscTrendAnalysisService,
        private readonly SearchConsoleService $searchConsoleService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $yesterday = new DateTimeImmutable('yesterday');
        $currentStart = $yesterday->modify('-27 days');
        $previousEnd = $currentStart->modify('-1 day');
        $previousStart = $previousEnd->modify('-27 days');

        $this
            ->addOption('current-start', null, InputOption::VALUE_REQUIRED, 'Current window start date in YYYY-MM-DD format.', $currentStart->format('Y-m-d'))
            ->addOption('current-end', null, InputOption::VALUE_REQUIRED, 'Current window end date in YYYY-MM-DD format.', $yesterday->format('Y-m-d'))
            ->addOption('previous-start', null, InputOption::VALUE_REQUIRED, 'Previous window start date in YYYY-MM-DD format.', $previousStart->format('Y-m-d'))
            ->addOption('previous-end', null, InputOption::VALUE_REQUIRED, 'Previous window end date in YYYY-MM-DD format.', $previousEnd->format('Y-m-d'))
            ->addOption('site-url', null, InputOption::VALUE_REQUIRED, 'Search Console property, e.g. sc-domain:waldbyte.de. Used only with --sync.')
            ->addOption('search-type', null, InputOption::VALUE_REQUIRED, 'Search type, usually web.', 'web')
            ->addOption('row-limit', null, InputOption::VALUE_REQUIRED, 'Maximum GSC rows to fetch per window when --sync is used.', '25000')
            ->addOption('min-impressions', null, InputOption::VALUE_REQUIRED, 'Minimum impressions in either window before an insight is considered.', '20')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum insights to store.', '100')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Fetch the two exact GSC comparison windows before analyzing.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyze but do not write insight rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $currentStart = (string)$input->getOption('current-start');
        $currentEnd = (string)$input->getOption('current-end');
        $previousStart = (string)$input->getOption('previous-start');
        $previousEnd = (string)$input->getOption('previous-end');
        $searchType = (string)$input->getOption('search-type');

        try {
            if ((bool)$input->getOption('sync')) {
                $previousSync = $this->searchConsoleService->sync(
                    $previousStart,
                    $previousEnd,
                    $input->getOption('site-url') !== null ? (string)$input->getOption('site-url') : null,
                    ['page', 'query'],
                    (int)$input->getOption('row-limit'),
                    $searchType,
                    (bool)$input->getOption('dry-run'),
                );
                $currentSync = $this->searchConsoleService->sync(
                    $currentStart,
                    $currentEnd,
                    $input->getOption('site-url') !== null ? (string)$input->getOption('site-url') : null,
                    ['page', 'query'],
                    (int)$input->getOption('row-limit'),
                    $searchType,
                    (bool)$input->getOption('dry-run'),
                );
                $io->note(sprintf(
                    'Synced exact comparison windows: previous %d rows, current %d rows.',
                    $previousSync['fetched'],
                    $currentSync['fetched']
                ));
            }

            $result = $this->gscTrendAnalysisService->analyze(
                $currentStart,
                $currentEnd,
                $previousStart,
                $previousEnd,
                (int)$input->getOption('min-impressions'),
                (int)$input->getOption('limit'),
                $searchType,
                (bool)$input->getOption('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->alertService->record(
                'gsc',
                'GSC trend analysis failed',
                $exception->getMessage(),
                [
                    'command' => 'seo:gsc:analyze-trends',
                    'current_start' => $currentStart,
                    'current_end' => $currentEnd,
                    'previous_start' => $previousStart,
                    'previous_end' => $previousEnd,
                    'sync' => (bool)$input->getOption('sync'),
                    'search_type' => $searchType,
                    'dry_run' => (bool)$input->getOption('dry-run'),
                ],
                'error'
            );
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'GSC trend analysis complete: compared %s to %s against %s to %s, current rows %d, previous rows %d, evaluated %d insights, stored %d.',
            $result['currentStart'],
            $result['currentEnd'],
            $result['previousStart'],
            $result['previousEnd'],
            $result['currentRows'],
            $result['previousRows'],
            $result['evaluated'],
            $result['stored']
        ));

        return Command::SUCCESS;
    }
}
