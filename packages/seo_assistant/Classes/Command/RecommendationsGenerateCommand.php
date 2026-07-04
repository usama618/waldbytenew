<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\RecommendationService;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-impressions', null, InputOption::VALUE_REQUIRED, 'Minimum impressions for a query/page candidate.', '20')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum GSC candidates to evaluate.', '100')
            ->addOption('ai-limit', null, InputOption::VALUE_REQUIRED, 'Maximum recommendations to refine with AI when configured.', '10')
            ->addOption('disable-ai', null, InputOption::VALUE_NONE, 'Use only rule-based recommendations.');
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
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Recommendation generation complete: evaluated %d candidates, stored %d drafts, AI refinements used %d. AI configured: %s.',
            $result['evaluated'],
            $result['stored'],
            $result['aiUsed'],
            $result['aiConfigured'] ? 'yes' : 'no'
        ));

        return Command::SUCCESS;
    }
}
