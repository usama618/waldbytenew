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
            ->addOption('all', null, InputOption::VALUE_NONE, 'Apply all automatic recommendations. Manual/template recommendations are skipped.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum recommendations for --all.', '100')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Actually write the change. Without this option, the command is a dry run.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow applying non-draft/non-approved recommendations or publishing fallback content.')
            ->addOption('publish-content', null, InputOption::VALUE_NONE, 'Publish created content elements immediately. Without this option, content is created hidden.')
            ->addOption('content-ctype', null, InputOption::VALUE_REQUIRED, 'CType to use for content-gap recommendations.', 'seo_text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getOption('uid');
        $all = (bool)$input->getOption('all');
        if ($uid > 0 && $all) {
            $io->error('Use either --uid=123 or --all, not both.');
            return Command::FAILURE;
        }
        if ($uid <= 0 && !$all) {
            $io->error('Provide --uid=123 or --all.');
            return Command::FAILURE;
        }

        if ($all) {
            return $this->executeAll($input, $io);
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
            ['Image alt updated' => (string)($result['imageAltUpdated'] ?? 0)],
            ['Image alt skipped' => (string)($result['imageAltSkipped'] ?? 0)],
            ['Already implemented' => ($result['alreadyImplemented'] ?? false) ? 'yes' : 'no'],
            ['Message' => (string)($result['message'] ?? '')],
        );

        if ($result['dryRun']) {
            $io->note('Run again with --yes to write the change. Content recommendations create hidden elements unless --publish-content is also passed.');
        }

        return Command::SUCCESS;
    }

    private function executeAll(InputInterface $input, SymfonyStyle $io): int
    {
        $result = $this->recommendationApplyService->applyAll(
            !(bool)$input->getOption('yes'),
            (bool)$input->getOption('force'),
            (bool)$input->getOption('publish-content'),
            (string)$input->getOption('content-ctype'),
            (int)$input->getOption('limit'),
        );

        $io->section($result['dryRun'] ? 'Dry run: apply all automatic recommendations' : 'Applied all automatic recommendations');
        $io->definitionList(
            ['Candidates' => (string)$result['total']],
            [$result['dryRun'] ? 'Would apply' : 'Applied' => (string)$result['applied']],
            ['Already implemented' => (string)$result['alreadyImplemented']],
            ['Skipped manual' => (string)$result['skipped']],
            ['Failed' => (string)$result['failed']],
        );

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                (int)($row['uid'] ?? 0),
                (string)($row['status'] ?? ''),
                (string)($row['action'] ?? ''),
                (string)($row['capability'] ?? ''),
                (string)($row['message'] ?? ''),
            ];
        }
        if ($rows !== []) {
            $io->table(['UID', 'Status', 'Action', 'Capability', 'Message'], $rows);
        }

        if ($result['dryRun']) {
            $io->note('Run again with --all --yes to write automatic changes. Content recommendations create hidden elements unless --publish-content is also passed.');
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
