<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AiRunHistoryService
{
    private const TABLE = 'tx_seoassistant_ai_run';
    private const RETAIN_RUNS = 10;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function getRecentRuns(int $limit = self::RETAIN_RUNS): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->compactRunForPrompt(...), $rows);
    }

    /**
     * @param list<array<string,mixed>> $contexts
     * @param list<array<string,mixed>> $recommendations
     */
    public function recordRun(
        string $model,
        string $mode,
        int $pagesAnalyzed,
        int $recommendationsGenerated,
        int $recommendationsStored,
        array $contexts,
        array $recommendations,
    ): void {
        $now = time();
        $summary = $this->buildFocusSummary($recommendations);
        $context = [
            'pages' => array_slice(array_map($this->compactContextForStorage(...), $contexts), 0, 30),
            'recommendations' => array_slice(array_map($this->compactRecommendationForStorage(...), $recommendations), 0, 60),
        ];

        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'model' => $model,
                'mode' => $mode,
                'pages_analyzed' => $pagesAnalyzed,
                'recommendations_generated' => $recommendationsGenerated,
                'recommendations_stored' => $recommendationsStored,
                'focus_summary' => $summary,
                'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'pages_analyzed' => Connection::PARAM_INT,
                'recommendations_generated' => Connection::PARAM_INT,
                'recommendations_stored' => Connection::PARAM_INT,
            ]
        );

        $this->pruneOldRuns();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function compactRunForPrompt(array $row): array
    {
        $context = json_decode((string)($row['context_json'] ?? '{}'), true);
        if (!is_array($context)) {
            $context = [];
        }

        return [
            'date' => date('Y-m-d H:i', (int)($row['crdate'] ?? 0)),
            'model' => (string)($row['model'] ?? ''),
            'mode' => (string)($row['mode'] ?? ''),
            'pages_analyzed' => (int)($row['pages_analyzed'] ?? 0),
            'recommendations_generated' => (int)($row['recommendations_generated'] ?? 0),
            'recommendations_stored' => (int)($row['recommendations_stored'] ?? 0),
            'focus_summary' => (string)($row['focus_summary'] ?? ''),
            'recommendations' => array_slice((array)($context['recommendations'] ?? []), 0, 20),
        ];
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     */
    private function buildFocusSummary(array $recommendations): string
    {
        if ($recommendations === []) {
            return 'No AI recommendations returned.';
        }

        $counts = [];
        foreach ($recommendations as $recommendation) {
            $type = (string)($recommendation['recommendation_type'] ?? 'unknown');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        arsort($counts);

        $parts = [];
        foreach (array_slice($counts, 0, 6, true) as $type => $count) {
            $parts[] = $type . ': ' . $count;
        }

        return 'AI focused on ' . implode(', ', $parts) . '.';
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function compactContextForStorage(array $context): array
    {
        return [
            'page_url' => (string)($context['page_url'] ?? ''),
            'page_uid' => (int)($context['page_uid'] ?? 0),
            'top_queries' => array_slice(array_map(
                static fn(array $query): array => [
                    'query' => (string)($query['query'] ?? ''),
                    'impressions' => (float)($query['impressions'] ?? 0),
                    'position' => (float)($query['position'] ?? 0),
                ],
                array_filter((array)($context['search_console_queries'] ?? []), 'is_array')
            ), 0, 5),
            'rendered_issue_codes' => array_values(array_filter(array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                array_filter((array)($context['rendered_page']['issues'] ?? []), 'is_array')
            ))),
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array<string,mixed>
     */
    private function compactRecommendationForStorage(array $recommendation): array
    {
        return [
            'page_url' => (string)($recommendation['page_url'] ?? ''),
            'type' => (string)($recommendation['recommendation_type'] ?? ''),
            'query' => (string)($recommendation['query_text'] ?? ''),
            'priority' => (int)($recommendation['priority'] ?? 0),
            'issue' => mb_substr((string)($recommendation['issue'] ?? ''), 0, 220),
        ];
    }

    private function pruneOldRuns(): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $uidsToKeep = $connection->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(self::RETAIN_RUNS)
            ->executeQuery()
            ->fetchFirstColumn();

        $uidsToKeep = array_map('intval', $uidsToKeep);
        if ($uidsToKeep === []) {
            return;
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->notIn('uid', ':uids'))
            ->setParameter('uids', $uidsToKeep, Connection::PARAM_INT_ARRAY)
            ->executeStatement();
    }
}
