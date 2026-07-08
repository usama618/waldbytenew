<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\ApplyHistoryService;
use App\SeoAssistant\Service\ConfigurationService;
use App\SeoAssistant\Service\RecommendationApplyService;
use App\SeoAssistant\Service\RecommendationService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:recommendations:generate', description: 'Generate draft SEO recommendations from snapshots and GSC data.')]
final class RecommendationsGenerateCommand extends Command
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly ConfigurationService $configuration,
        private readonly RecommendationApplyService $recommendationApplyService,
        private readonly ApplyHistoryService $applyHistoryService,
        private readonly SeoAssistantAlertService $alertService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-impressions', null, InputOption::VALUE_REQUIRED, 'Minimum impressions for a query/page candidate.', (string)$this->configuration->getMinImpressions())
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum GSC candidates to evaluate.', (string)$this->configuration->getRecommendationLimit())
            ->addOption('ai-limit', null, InputOption::VALUE_REQUIRED, 'Maximum page contexts to analyze with AI when configured.', (string)$this->configuration->getAiLimit())
            ->addOption('disable-ai', null, InputOption::VALUE_NONE, 'Use only rule-based recommendations.')
            ->addOption('apply-automatic', null, InputOption::VALUE_NONE, 'After generation, apply all automatic recommendations safely. Manual/template rows are skipped.')
            ->addOption('apply-limit', null, InputOption::VALUE_REQUIRED, 'Maximum automatic recommendations to apply when --apply-automatic is used.', '100')
            ->addOption('content-ctype', null, InputOption::VALUE_REQUIRED, 'CType to use for automatic content-draft recommendations.', 'seo_text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->recommendationService->generate(
                (int)$input->getOption('min-impressions'),
                (int)$input->getOption('limit'),
                !(bool)$input->getOption('disable-ai'),
                (int)$input->getOption('ai-limit'),
            );
        } catch (Throwable $exception) {
            $this->alertService->record(
                'cron',
                'Recommendation generation command failed',
                $exception->getMessage(),
                [
                    'command' => 'seo:recommendations:generate',
                    'min_impressions' => (int)$input->getOption('min-impressions'),
                    'limit' => (int)$input->getOption('limit'),
                    'ai_limit' => (int)$input->getOption('ai-limit'),
                    'disable_ai' => (bool)$input->getOption('disable-ai'),
                ],
                'error'
            );
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Recommendation generation complete: mode %s%s, evaluated %d GSC candidates and %d rendered URLs, stored %d drafts, AI analyses used %d. AI configured: %s.',
            $result['generationMode'],
            $result['fallbackUsed'] ? ' (fallback)' : '',
            $result['evaluated'],
            $result['renderedEvaluated'],
            $result['stored'],
            $result['aiUsed'],
            $result['aiConfigured'] ? 'yes' : 'no'
        ));

        if ((bool)$input->getOption('apply-automatic')) {
            return $this->applyAutomaticRecommendations($input, $io);
        }

        return Command::SUCCESS;
    }

    private function applyAutomaticRecommendations(InputInterface $input, SymfonyStyle $io): int
    {
        $limit = max(1, min(500, (int)$input->getOption('apply-limit')));
        $contentCType = trim((string)$input->getOption('content-ctype'));
        if ($contentCType === '') {
            $contentCType = 'seo_text';
        }

        try {
            $applyResult = $this->recommendationApplyService->applyAll(false, false, true, $contentCType, $limit);
        } catch (Throwable $exception) {
            $this->alertService->record(
                'cron',
                'Automatic recommendation apply after generation failed',
                $exception->getMessage(),
                [
                    'command' => 'seo:recommendations:generate',
                    'apply_automatic' => true,
                    'apply_limit' => $limit,
                    'content_ctype' => $contentCType,
                ],
                'error'
            );
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $historyUid = $this->applyHistoryService->record(
            'applyAllRecommendations',
            'Apply all automatic recommendations after generation',
            'cli_auto',
            $applyResult['failed'] > 0 ? 'partial' : 'success',
            [
                'total' => $applyResult['total'],
                'applied' => $applyResult['applied'],
                'alreadyImplemented' => $applyResult['alreadyImplemented'],
                'skipped' => $applyResult['skipped'],
                'failed' => $applyResult['failed'],
                'limit' => $limit,
                'message' => 'Automatic apply after generation complete: applied ' . $applyResult['applied']
                    . ', already implemented ' . $applyResult['alreadyImplemented']
                    . ', skipped manual ' . $applyResult['skipped']
                    . ', failed ' . $applyResult['failed'] . '.',
            ],
            $applyResult['rows']
        );

        $io->definitionList(
            ['Automatic apply candidates' => (string)$applyResult['total']],
            ['Applied' => (string)$applyResult['applied']],
            ['Already implemented' => (string)$applyResult['alreadyImplemented']],
            ['Skipped manual' => (string)$applyResult['skipped']],
            ['Failed' => (string)$applyResult['failed']],
            ['History UID' => (string)$historyUid],
        );

        if ($applyResult['failed'] > 0) {
            $this->alertService->record(
                'cron',
                'Automatic recommendation apply after generation had failures',
                'Automatic apply finished with ' . $applyResult['failed'] . ' failed recommendation(s).',
                [
                    'command' => 'seo:recommendations:generate',
                    'apply_automatic' => true,
                    'apply_limit' => $limit,
                    'history_uid' => $historyUid,
                    'result' => $applyResult,
                ],
                'warning'
            );
        }

        return $applyResult['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
