<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\RecommendationVerificationService;
use App\SeoAssistant\Service\RenderedSnapshotService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:recommendations:verify', description: 'Verify applied SEO recommendations against rendered frontend snapshots.')]
final class RecommendationsVerifyCommand extends Command
{
    public function __construct(
        private readonly RecommendationVerificationService $recommendationVerificationService,
        private readonly RenderedSnapshotService $renderedSnapshotService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Recommendation uid to verify.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Verify pending applied recommendations.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum recommendations for --all.', '100')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the rendered snapshot for each verified page first.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getOption('uid');
        $all = (bool)$input->getOption('all');
        if ($uid <= 0 && !$all) {
            $io->error('Provide --uid=123 or --all.');
            return Command::FAILURE;
        }

        $uids = $uid > 0
            ? [$uid]
            : $this->recommendationVerificationService->fetchPendingRecommendationUids((int)$input->getOption('limit'));

        if ($uids === []) {
            $io->success('No pending applied recommendations need verification.');
            return Command::SUCCESS;
        }

        $rows = [];
        $failed = false;
        foreach ($uids as $recommendationUid) {
            try {
                if ((bool)$input->getOption('refresh')) {
                    $pageUrl = $this->recommendationVerificationService->getRecommendationPageUrl($recommendationUid);
                    if ($pageUrl !== '') {
                        $this->renderedSnapshotService->snapshot(null, [$pageUrl], 1, false);
                    }
                }

                $result = $this->recommendationVerificationService->verify($recommendationUid);
                $rows[] = [
                    $result['uid'],
                    $result['status'],
                    implode(', ', $result['checkedFields']),
                    $result['pageUrl'],
                    $result['message'],
                ];
            } catch (Throwable $exception) {
                $failed = true;
                $this->alertService->record(
                    'cron',
                    'Recommendation verification failed',
                    $exception->getMessage(),
                    [
                        'command' => 'seo:recommendations:verify',
                        'recommendation_uid' => $recommendationUid,
                        'refresh' => (bool)$input->getOption('refresh'),
                    ],
                    'error'
                );
                $rows[] = [
                    $recommendationUid,
                    'error',
                    '',
                    '',
                    $exception->getMessage(),
                ];
            }
        }

        $io->table(['UID', 'Status', 'Fields', 'URL', 'Message'], $rows);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
