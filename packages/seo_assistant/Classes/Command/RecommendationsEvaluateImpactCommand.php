<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\RecommendationImpactEvaluationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:recommendations:evaluate-impact', description: 'Evaluate applied SEO recommendation impact with delayed GSC comparison windows.')]
final class RecommendationsEvaluateImpactCommand extends Command
{
    public function __construct(
        private readonly RecommendationImpactEvaluationService $impactEvaluationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-age-days', null, InputOption::VALUE_REQUIRED, 'Minimum days after apply before evaluation.', '35')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Evaluation stage preset: early, first, stronger, final, or custom.', 'first')
            ->addOption('window-days', null, InputOption::VALUE_REQUIRED, 'Before/after comparison window length.', '28')
            ->addOption('buffer-days', null, InputOption::VALUE_REQUIRED, 'Days ignored after apply before the after-window starts.', '7')
            ->addOption('min-impressions', null, InputOption::VALUE_REQUIRED, 'Minimum impressions in either window before judging impact.', '20')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum applied recommendations to evaluate.', '100')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Fetch exact before/after GSC windows before evaluating.')
            ->addOption('row-limit', null, InputOption::VALUE_REQUIRED, 'Maximum GSC rows to fetch per synced window.', '25000')
            ->addOption('search-type', null, InputOption::VALUE_REQUIRED, 'Search type, usually web.', 'web')
            ->addOption('disable-ai', null, InputOption::VALUE_NONE, 'Use rule-based evaluation only, without OpenAI explanation.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-evaluate windows even when an evaluation already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate but do not store rows. Also skips OpenAI calls.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->impactEvaluationService->evaluate(
                (int)$input->getOption('min-age-days'),
                (int)$input->getOption('window-days'),
                (int)$input->getOption('buffer-days'),
                (int)$input->getOption('min-impressions'),
                (int)$input->getOption('limit'),
                (bool)$input->getOption('sync'),
                (int)$input->getOption('row-limit'),
                (string)$input->getOption('search-type'),
                !(bool)$input->getOption('disable-ai') && !(bool)$input->getOption('dry-run'),
                (bool)$input->getOption('force'),
                (bool)$input->getOption('dry-run'),
                (string)$input->getOption('stage'),
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Candidates' => (string)$result['total']],
            ['Evaluated' => (string)$result['evaluated']],
            ['Stored' => (string)$result['stored']],
            ['Pending' => (string)$result['pending']],
            ['Skipped' => (string)$result['skipped']],
            ['AI used' => (string)$result['aiUsed']],
        );

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                (int)($row['uid'] ?? 0),
                (string)($row['status'] ?? ''),
                (string)($row['confidence'] ?? ''),
                (string)($row['evaluationStage'] ?? ''),
                (string)($row['appliedDate'] ?? ''),
                (string)($row['before'] ?? ''),
                (string)($row['after'] ?? ''),
                (string)($row['query'] ?? ''),
                (string)($row['message'] ?? ''),
            ];
        }
        if ($rows !== []) {
            $io->table(['UID', 'Impact', 'Conf.', 'Stage', 'Applied', 'Before', 'After', 'Query', 'Summary'], $rows);
        }

        $io->success('SEO recommendation impact evaluation complete.');
        return Command::SUCCESS;
    }
}
