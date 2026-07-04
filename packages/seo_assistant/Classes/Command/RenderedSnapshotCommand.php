<?php

declare(strict_types=1);

namespace App\SeoAssistant\Command;

use App\SeoAssistant\Service\RenderedSnapshotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'seo:rendered:snapshot', description: 'Crawl rendered frontend URLs and snapshot SEO-relevant HTML.')]
final class RenderedSnapshotCommand extends Command
{
    public function __construct(
        private readonly RenderedSnapshotService $renderedSnapshotService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL used for same-host checks.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Specific URL or path to crawl. Can be used multiple times.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum URLs to crawl.', '250')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Crawl and parse URLs but do not write rendered snapshot rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->renderedSnapshotService->snapshot(
                $input->getOption('base-url') !== null ? (string)$input->getOption('base-url') : null,
                array_values(array_map('strval', (array)$input->getOption('url'))),
                (int)$input->getOption('limit'),
                (bool)$input->getOption('dry-run'),
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Rendered snapshot complete for %s: processed %d URLs, stored %d snapshots, %d failed.',
            $result['baseUrl'],
            $result['processed'],
            $result['stored'],
            $result['failed']
        ));

        return Command::SUCCESS;
    }
}
