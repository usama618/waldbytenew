<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\RecommendationApplyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:recommendations:apply', description: 'Apply one selected SEO recommendation to page metadata.')]
final class RecommendationsApplyCommand extends Command
{
    public function __construct(
        private readonly RecommendationApplyService $recommendationApplyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Recommendation uid to apply.')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Actually write the page metadata. Without this option, the command is a dry run.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow applying non-draft/non-approved recommendations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getOption('uid');
        if ($uid <= 0) {
            $io->error('Provide a recommendation uid with --uid=123.');
            return Command::FAILURE;
        }

        try {
            $result = $this->recommendationApplyService->apply(
                $uid,
                !(bool)$input->getOption('yes'),
                (bool)$input->getOption('force'),
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->section($result['dryRun'] ? 'Dry run' : 'Applied');
        $io->definitionList(
            ['Recommendation UID' => $result['uid']],
            ['Page UID' => $result['pageUid']],
            ['SEO title' => $result['seoTitle']],
            ['Description' => $result['description']],
        );

        if ($result['dryRun']) {
            $io->note('Run again with --yes to write pages.seo_title and pages.description.');
        }

        return Command::SUCCESS;
    }
}
