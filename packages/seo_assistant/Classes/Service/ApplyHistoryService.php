<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ApplyHistoryService
{
    private const TABLE = 'tx_seoassistant_apply_history';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';

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

        $manualRows = array_values(array_filter(array_map(
            fn(array $row): array => $this->enrichManualRowForDownload($row),
            array_values(array_filter($rows, fn(array $row): bool => $this->isManualHistoryRow($row)))
        ), fn(array $row): bool => is_array($row['current'] ?? null) || is_array($row['proposed'] ?? null)));
        if ($manualRows !== []) {
            $lines[] = '';
            $lines[] = '## Manual Recommendation Details';
            $lines[] = '';
            $lines[] = 'Use these entries for local template or code changes. Each item lists the current CMS/rendered state and the recommended target state.';
            $lines[] = '';
            foreach ($manualRows as $row) {
                $lines = array_merge($lines, $this->manualRowMarkdown($row));
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

    /**
     * @param array<string,mixed> $row
     */
    private function isManualHistoryRow(array $row): bool
    {
        return (string)($row['status'] ?? '') === 'skipped'
            || in_array((string)($row['capability'] ?? ''), ['manual', 'manual_review'], true)
            || (string)($row['action'] ?? '') === 'manual_review';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function enrichManualRowForDownload(array $row): array
    {
        if (is_array($row['current'] ?? null) && is_array($row['proposed'] ?? null)) {
            return $row;
        }

        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            return $row;
        }

        $recommendation = $this->fetchRecommendation($uid);
        if ($recommendation === null) {
            return $row;
        }

        $pageUid = (int)($row['pageUid'] ?? $row['page_uid'] ?? $recommendation['page_uid'] ?? 0);
        $pageUrl = (string)($row['pageUrl'] ?? $row['page_url'] ?? $recommendation['page_url'] ?? '');
        $payload = $this->decodeJson((string)($recommendation['action_payload_json'] ?? '{}'));

        return array_replace($row, [
            'pageUid' => $pageUid,
            'pageUrl' => $pageUrl,
            'query' => (string)($row['query'] ?? $recommendation['query_text'] ?? ''),
            'priority' => (int)($row['priority'] ?? $recommendation['priority'] ?? 0),
            'type' => (string)($row['type'] ?? $recommendation['recommendation_type'] ?? ''),
            'issue' => (string)($row['issue'] ?? $recommendation['issue'] ?? ''),
            'recommendation' => (string)($row['recommendation'] ?? $recommendation['recommendation'] ?? ''),
            'current' => is_array($row['current'] ?? null) ? $row['current'] : $this->currentStateForDownload($pageUid, $pageUrl),
            'proposed' => is_array($row['proposed'] ?? null) ? $row['proposed'] : [
                'seo_title' => (string)($payload['seo_title'] ?? $recommendation['proposed_seo_title'] ?? ''),
                'description' => (string)($payload['description'] ?? $recommendation['proposed_description'] ?? ''),
                'action_payload' => $payload,
                'recommendation_text' => (string)($recommendation['recommendation'] ?? ''),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRecommendation(int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, Connection::PARAM_INT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentStateForDownload(int $pageUid, string $pageUrl): array
    {
        $page = $pageUid > 0 ? $this->fetchPageRecord($pageUid) : [];
        $pageSnapshot = $this->fetchPageSnapshot($pageUid, $pageUrl);
        $renderedSnapshot = $this->fetchRenderedSnapshot($pageUrl);

        return [
            'cms_page' => [
                'uid' => $pageUid,
                'title' => (string)($page['title'] ?? ''),
                'nav_title' => (string)($page['nav_title'] ?? ''),
                'slug' => (string)($page['slug'] ?? ''),
                'seo_title' => (string)($page['seo_title'] ?? ''),
                'description' => (string)($page['description'] ?? ''),
                'no_index' => (int)($page['no_index'] ?? 0),
                'no_follow' => (int)($page['no_follow'] ?? 0),
                'canonical_link' => (string)($page['canonical_link'] ?? ''),
            ],
            'cms_snapshot' => [
                'h1' => (string)($pageSnapshot['h1'] ?? ''),
                'word_count' => (int)($pageSnapshot['word_count'] ?? 0),
                'robots' => (string)($pageSnapshot['robots'] ?? ''),
                'canonical_url' => (string)($pageSnapshot['canonical_url'] ?? ''),
                'content_preview' => $this->shortText((string)($pageSnapshot['content_text'] ?? ''), 700),
            ],
            'rendered_frontend' => [
                'html_title' => (string)($renderedSnapshot['html_title'] ?? ''),
                'meta_description' => (string)($renderedSnapshot['meta_description'] ?? ''),
                'robots' => (string)($renderedSnapshot['robots'] ?? ''),
                'canonical_url' => (string)($renderedSnapshot['canonical_url'] ?? ''),
                'word_count' => (int)($renderedSnapshot['word_count'] ?? 0),
                'h1_count' => (int)($renderedSnapshot['h1_count'] ?? 0),
                'missing_alt_count' => (int)($renderedSnapshot['missing_alt_count'] ?? 0),
                'visible_text_preview' => $this->shortText((string)($renderedSnapshot['visible_text'] ?? ''), 700),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPageRecord(int $pageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        $select = array_values(array_intersect(['uid', 'title', 'nav_title', 'seo_title', 'description', 'slug', 'no_index', 'no_follow', 'canonical_link'], $columns));
        if ($select === []) {
            return [];
        }

        $row = $connection->createQueryBuilder()
            ->select(...$select)
            ->from('pages')
            ->where('uid = :uid')
            ->setParameter('uid', $pageUid, Connection::PARAM_INT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPageSnapshot(int $pageUid, string $pageUrl): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->setMaxResults(1);

        if ($pageUid > 0) {
            $queryBuilder
                ->where('page_uid = :pageUid')
                ->setParameter('pageUid', $pageUid, Connection::PARAM_INT);
        } elseif ($pageUrl !== '') {
            $queryBuilder
                ->where('page_url = :pageUrl')
                ->setParameter('pageUrl', $pageUrl);
        } else {
            return [];
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchRenderedSnapshot(string $pageUrl): array
    {
        if ($pageUrl === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE)
            ->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('url', ':pageUrl'),
                    $queryBuilder->expr()->eq('final_url', ':pageUrl')
                )
            )
            ->setParameter('pageUrl', $pageUrl)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function manualRowMarkdown(array $row): array
    {
        $current = is_array($row['current'] ?? null) ? $row['current'] : [];
        $proposed = is_array($row['proposed'] ?? null) ? $row['proposed'] : [];
        $cmsPage = is_array($current['cms_page'] ?? null) ? $current['cms_page'] : [];
        $cmsSnapshot = is_array($current['cms_snapshot'] ?? null) ? $current['cms_snapshot'] : [];
        $rendered = is_array($current['rendered_frontend'] ?? null) ? $current['rendered_frontend'] : [];
        $payload = is_array($proposed['action_payload'] ?? null) ? $proposed['action_payload'] : [];

        $lines = [
            '### UID ' . (int)($row['uid'] ?? 0) . ' - ' . ((string)($row['type'] ?? '') ?: (string)($row['action'] ?? 'manual recommendation')),
            '',
            '- Page: ' . ((string)($row['pageUrl'] ?? '') ?: '-'),
            '- Page UID: ' . (int)($row['pageUid'] ?? 0),
            '- Query: ' . ((string)($row['query'] ?? '') ?: '-'),
            '- Priority: ' . (int)($row['priority'] ?? 0),
            '- Action: ' . ((string)($row['action'] ?? '') ?: '-'),
            '- Capability: ' . ((string)($row['capability'] ?? '') ?: '-'),
            '',
            '**Issue**',
            '',
            (string)($row['issue'] ?? '-'),
            '',
            '**Recommendation**',
            '',
            (string)($row['recommendation'] ?? $proposed['recommendation_text'] ?? '-'),
            '',
            '**Current State**',
            '',
            '- CMS title: ' . ((string)($cmsPage['title'] ?? '') ?: '-'),
            '- CMS SEO title: ' . ((string)($cmsPage['seo_title'] ?? '') ?: '-'),
            '- CMS description: ' . ((string)($cmsPage['description'] ?? '') ?: '-'),
            '- CMS slug: ' . ((string)($cmsPage['slug'] ?? '') ?: '-'),
            '- CMS robots: no_index=' . (int)($cmsPage['no_index'] ?? 0) . ', no_follow=' . (int)($cmsPage['no_follow'] ?? 0),
            '- CMS canonical: ' . ((string)($cmsPage['canonical_link'] ?? '') ?: '-'),
            '- Snapshot H1: ' . ((string)($cmsSnapshot['h1'] ?? '') ?: '-'),
            '- Snapshot words: ' . (int)($cmsSnapshot['word_count'] ?? 0),
            '- Rendered title: ' . ((string)($rendered['html_title'] ?? '') ?: '-'),
            '- Rendered description: ' . ((string)($rendered['meta_description'] ?? '') ?: '-'),
            '- Rendered robots: ' . ((string)($rendered['robots'] ?? '') ?: '-'),
            '- Rendered canonical: ' . ((string)($rendered['canonical_url'] ?? '') ?: '-'),
            '- Rendered words: ' . (int)($rendered['word_count'] ?? 0),
            '- Rendered H1 count: ' . (int)($rendered['h1_count'] ?? 0),
            '- Missing image alts: ' . (int)($rendered['missing_alt_count'] ?? 0),
            '',
        ];

        if ((string)($cmsSnapshot['content_preview'] ?? '') !== '') {
            $lines[] = 'Current CMS content preview:';
            $lines[] = '';
            $lines[] = $this->fencedText((string)$cmsSnapshot['content_preview']);
            $lines[] = '';
        }

        if ((string)($rendered['visible_text_preview'] ?? '') !== '') {
            $lines[] = 'Current rendered text preview:';
            $lines[] = '';
            $lines[] = $this->fencedText((string)$rendered['visible_text_preview']);
            $lines[] = '';
        }

        $lines[] = '**Recommended Target**';
        $lines[] = '';
        if ((string)($proposed['seo_title'] ?? '') !== '') {
            $lines[] = '- SEO title: ' . (string)$proposed['seo_title'];
        }
        if ((string)($proposed['description'] ?? '') !== '') {
            $lines[] = '- Meta description: ' . (string)$proposed['description'];
        }
        if ($payload !== []) {
            $lines[] = '- Action payload:';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $this->json($payload);
            $lines[] = '```';
        }
        if ((string)($proposed['recommendation_text'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Recommendation text:';
            $lines[] = '';
            $lines[] = (string)$proposed['recommendation_text'];
        }

        $lines[] = '';

        return $lines;
    }

    private function trim(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private function markdownCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], trim($value));
    }

    private function fencedText(string $value): string
    {
        return '```text' . "\n" . str_replace('```', "'''", trim($value)) . "\n" . '```';
    }

    private function shortText(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 3) . '...' : $value;
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
