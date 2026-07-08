<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ApplyHistoryService
{
    private const TABLE = 'tx_seoassistant_apply_history';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $rows
     */
    public function record(
        string $actionType,
        string $actionLabel,
        string $triggerSource,
        string $status,
        array $summary,
        array $rows,
    ): int {
        $now = time();
        $total = (int)($summary['total'] ?? count($rows));
        $applied = (int)($summary['applied'] ?? 0);
        $alreadyImplemented = (int)($summary['alreadyImplemented'] ?? $summary['already_implemented'] ?? 0);
        $skipped = (int)($summary['skipped'] ?? 0);
        $failed = (int)($summary['failed'] ?? 0);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'action_type' => $this->trim($actionType, 64),
                'action_label' => $this->trim($actionLabel, 255),
                'trigger_source' => $this->trim($triggerSource, 32),
                'status' => $this->trim($status, 32),
                'total' => $total,
                'applied' => $applied,
                'already_implemented' => $alreadyImplemented,
                'skipped' => $skipped,
                'failed' => $failed,
                'summary' => (string)($summary['message'] ?? $this->summaryText($total, $applied, $alreadyImplemented, $skipped, $failed)),
                'result_json' => $this->json([
                    'summary' => $summary,
                    'rows' => $rows,
                ]),
            ],
            [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'total' => Connection::PARAM_INT,
                'applied' => Connection::PARAM_INT,
                'already_implemented' => Connection::PARAM_INT,
                'skipped' => Connection::PARAM_INT,
                'failed' => Connection::PARAM_INT,
            ]
        );

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRecent(int $limit = 20): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetchByUid(int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $history
     */
    public function buildMarkdown(array $history): string
    {
        $result = $this->decodeJson((string)($history['result_json'] ?? '{}'));
        $rows = array_values(array_filter((array)($result['rows'] ?? []), 'is_array'));
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $lines = [
            '# SEO Assistant Apply History - Run ' . (int)($history['uid'] ?? 0),
            '',
            '- Date: ' . date('Y-m-d H:i', (int)($history['crdate'] ?? 0)),
            '- Action: ' . (string)($history['action_label'] ?? ''),
            '- Action type: ' . (string)($history['action_type'] ?? ''),
            '- Source: ' . (string)($history['trigger_source'] ?? ''),
            '- Status: ' . (string)($history['status'] ?? ''),
            '- Total: ' . (int)($history['total'] ?? 0),
            '- Applied: ' . (int)($history['applied'] ?? 0),
            '- Already implemented: ' . (int)($history['already_implemented'] ?? 0),
            '- Skipped: ' . (int)($history['skipped'] ?? 0),
            '- Failed: ' . (int)($history['failed'] ?? 0),
            '',
            '## Summary',
            '',
            (string)($history['summary'] ?? $summary['message'] ?? 'No summary recorded.'),
            '',
            '## Recommendation Results',
            '',
        ];

        if ($rows === []) {
            $lines[] = 'No recommendation rows were recorded for this run.';
        } else {
            $lines[] = '| UID | Page UID | Status | Action | Capability | Message |';
            $lines[] = '| --- | --- | --- | --- | --- | --- |';
            foreach ($rows as $row) {
                $lines[] = '| '
                    . (int)($row['uid'] ?? 0) . ' | '
                    . (int)($row['pageUid'] ?? $row['page_uid'] ?? 0) . ' | '
                    . $this->markdownCell((string)($row['status'] ?? '')) . ' | '
                    . $this->markdownCell((string)($row['action'] ?? $row['actionType'] ?? '')) . ' | '
                    . $this->markdownCell((string)($row['capability'] ?? $row['applyCapability'] ?? '')) . ' | '
                    . $this->markdownCell((string)($row['message'] ?? '')) . ' |';
            }
        }

        $lines[] = '';
        $lines[] = '## Raw Result';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = $this->json($result);
        $lines[] = '```';

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function summaryText(int $total, int $applied, int $alreadyImplemented, int $skipped, int $failed): string
    {
        return 'Applied ' . $applied
            . ', already implemented ' . $alreadyImplemented
            . ', skipped ' . $skipped
            . ', failed ' . $failed
            . ', total ' . $total . '.';
    }

    private function trim(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private function markdownCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], trim($value));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function json($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '{}';
    }
}
