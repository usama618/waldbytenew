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

#[AsCommand(name: 'seo:recommendations:apply', description: 'Apply one selected SEO recommendation to page metadata or content.')]
final class RecommendationsApplyCommand extends Command
{
    protected static $defaultDescription = 'Apply one selected SEO recommendation to page metadata or content.';

    public function __construct(
        private readonly RecommendationApplyService $recommendationApplyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Apply one selected SEO recommendation to page metadata or content.')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Recommendation uid to apply.')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Actually write the change. Without this option, the command is a dry run.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow applying non-draft/non-approved recommendations or publishing fallback content.')
            ->addOption('publish-content', null, InputOption::VALUE_NONE, 'Publish created content elements immediately. Without this option, content is created hidden.')
            ->addOption('content-ctype', null, InputOption::VALUE_REQUIRED, 'CType to use for content-gap recommendations.', 'seo_text');
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
                (bool)$input->getOption('publish-content'),
                (string)$input->getOption('content-ctype'),
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->section($result['dryRun'] ? 'Dry run' : 'Applied');
        $io->definitionList(
            ['Recommendation UID' => $result['uid']],
            ['Page UID' => $result['pageUid']],
            ['Action' => $result['actionType']],
            ['Apply capability' => $result['applyCapability']],
            ['Changed fields' => implode(', ', $result['changedFields'])],
            ['SEO title' => $result['seoTitle']],
            ['Description' => $result['description']],
            ['Content UID' => $result['contentUid'] > 0 ? (string)$result['contentUid'] : '-'],
            ['Content status' => $result['contentHeader'] !== '' ? ($result['contentHidden'] ? 'hidden draft' : 'published') : '-'],
            ['Content header' => $result['contentHeader'] !== '' ? $result['contentHeader'] : '-'],
        );

        if ($result['dryRun']) {
            $io->note('Run again with --yes to write the change. Content recommendations create hidden elements unless --publish-content is also passed.');
        }

        return Command::SUCCESS;
    }
}
