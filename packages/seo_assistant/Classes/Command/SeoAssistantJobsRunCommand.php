<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\ApplyHistoryService;
use App\SeoAssistant\Service\PageSnapshotService;
use App\SeoAssistant\Service\RecommendationApplyService;
use App\SeoAssistant\Service\RecommendationService;
use App\SeoAssistant\Service\RenderedSnapshotService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use App\SeoAssistant\Service\SeoAssistantJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:jobs:run', description: 'Run queued SEO Assistant backend jobs.')]
final class SeoAssistantJobsRunCommand extends Command
{
    public function __construct(
        private readonly SeoAssistantJobService $jobService,
        private readonly PageSnapshotService $pageSnapshotService,
        private readonly RenderedSnapshotService $renderedSnapshotService,
        private readonly RecommendationService $recommendationService,
        private readonly RecommendationApplyService $recommendationApplyService,
        private readonly ApplyHistoryService $applyHistoryService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queued jobs to run.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, min(20, (int)$input->getOption('limit')));
        $processed = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->jobService->claimNext();
            if ($job === null) {
                break;
            }

            $uid = (int)$job['uid'];
            try {
                $result = $this->runJob($job);
                $this->jobService->complete($uid, $result);
                $processed++;
                $io->writeln('Completed SEO Assistant job #' . $uid . ' (' . (string)$job['job_type'] . ').');
            } catch (Throwable $exception) {
                $failed++;
                $this->jobService->fail($uid, $exception->getMessage());
                $this->alertService->record(
                    'seo_job',
                    'SEO Assistant job #' . $uid . ' failed',
                    $exception->getMessage(),
                    [
                        'job_uid' => $uid,
                        'job_type' => (string)($job['job_type'] ?? ''),
                        'payload' => $this->jobService->payload($job),
                    ],
                    'error'
                );
                $io->error('Job #' . $uid . ' failed: ' . $exception->getMessage());
            }
        }

        if ($processed === 0 && $failed === 0) {
            $io->success('No queued SEO Assistant jobs found.');
            return Command::SUCCESS;
        }

        $io->success('SEO Assistant jobs complete: processed ' . $processed . ', failed ' . $failed . '.');
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function runJob(array $job): array
    {
        $payload = $this->jobService->payload($job);
        $type = (string)($job['job_type'] ?? '');

        return match ($type) {
            'generate_recommendations' => $this->runGenerateRecommendationsJob($payload),
            'apply_all_recommendations' => $this->runApplyAllRecommendationsJob($payload),
            default => throw new \RuntimeException('Unsupported SEO Assistant job type "' . $type . '".', 1760000081),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runGenerateRecommendationsJob(array $payload): array
    {
        $renderedLimit = max(1, min(500, (int)($payload['renderedLimit'] ?? 250)));
        $recommendationLimit = max(1, min(500, (int)($payload['recommendationLimit'] ?? 100)));
        $aiLimit = max(1, min(100, (int)($payload['aiLimit'] ?? 10)));
        $minImpressions = max(1, (int)($payload['minImpressions'] ?? 20));

        $pageResult = $this->pageSnapshotService->snapshot(null, false);
        $renderedResult = $this->renderedSnapshotService->snapshot(null, [], $renderedLimit, false);
        $recommendationResult = $this->recommendationService->generate($minImpressions, $recommendationLimit, true, $aiLimit);

        return [
            'pageSnapshots' => $pageResult,
            'renderedSnapshots' => $renderedResult,
            'recommendations' => $recommendationResult,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runApplyAllRecommendationsJob(array $payload): array
    {
        $limit = max(1, min(500, (int)($payload['limit'] ?? 100)));
        $contentCType = trim((string)($payload['contentCType'] ?? 'seo_text'));
        $result = $this->recommendationApplyService->applyAll(false, false, false, $contentCType !== '' ? $contentCType : 'seo_text', $limit);
        $historyUid = $this->applyHistoryService->record(
            'applyAllRecommendations',
            'Apply all automatic recommendations',
            'backend_job',
            $result['failed'] > 0 ? 'partial' : 'success',
            [
                'total' => $result['total'],
                'applied' => $result['applied'],
                'alreadyImplemented' => $result['alreadyImplemented'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'limit' => $limit,
                'message' => 'Bulk apply complete: applied ' . $result['applied']
                    . ', already implemented ' . $result['alreadyImplemented']
                    . ', skipped manual ' . $result['skipped']
                    . ', failed ' . $result['failed'] . '.',
            ],
            $result['rows']
        );

        return [
            'applyAll' => $result,
            'historyUid' => $historyUid,
        ];
    }
}
