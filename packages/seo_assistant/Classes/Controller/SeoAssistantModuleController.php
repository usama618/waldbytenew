<?php

declare(strict_types=1);

namespace App\SeoAssistant\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;

final class SeoAssistantModuleController
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const AI_RUN_TABLE = 'tx_seoassistant_ai_run';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse($this->render());
    }

    private function render(): string
    {
        $missingTables = $this->findMissingTables();
        if ($missingTables !== []) {
            return $this->renderMissingTables($missingTables);
        }

        $recommendations = $this->fetchRecommendations();
        $aiRuns = $this->fetchAiRuns();
        $renderedSnapshots = $this->fetchRenderedSnapshots();
        $pageSnapshots = $this->fetchPageSnapshots();
        $stats = $this->fetchStats();

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>SEO Assistant</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2933;}'
            . 'h1{font-size:24px;margin:0 0 6px;}'
            . 'h2{font-size:18px;margin:28px 0 12px;}'
            . 'p{margin:0 0 18px;}'
            . '.muted{color:#5d6875;}'
            . '.stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin:18px 0 22px;}'
            . '.stat{background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:14px;}'
            . '.stat strong{display:block;font-size:22px;margin-top:4px;}'
            . '.panel{background:#fff;border:1px solid #d9dde3;border-radius:6px;overflow:hidden;margin-bottom:24px;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #e3e6ea;font-size:13px;}'
            . 'th{font-weight:700;background:#eef1f4;}'
            . 'tr:last-child td{border-bottom:0;}'
            . '.url{max-width:300px;word-break:break-word;}'
            . '.priority{font-weight:700;}'
            . '.pill{display:inline-block;padding:2px 7px;border-radius:999px;background:#e8edf3;color:#334155;font-size:12px;white-space:nowrap;}'
            . '.pill-critical{background:#fee2e2;color:#991b1b;}'
            . '.pill-warning{background:#fef3c7;color:#92400e;}'
            . '.pill-notice{background:#e0f2fe;color:#075985;}'
            . '.issues{display:flex;gap:5px;flex-wrap:wrap;}'
            . 'code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;white-space:normal;}'
            . '</style></head><body>'
            . '<h1>SEO Assistant</h1>'
            . '<p class="muted">Central overview for Search Console, rendered frontend audits, CMS content snapshots and reviewable recommendations.</p>'
            . $this->renderStats($stats)
            . '<h2>AI Run Memory</h2>'
            . '<div class="panel">' . $this->renderAiRunsTable($aiRuns) . '</div>'
            . '<h2>Recommendations</h2>'
            . '<div class="panel">' . $this->renderRecommendationsTable($recommendations) . '</div>'
            . '<h2>Rendered URL Audit</h2>'
            . '<div class="panel">' . $this->renderRenderedSnapshotsTable($renderedSnapshots) . '</div>'
            . '<h2>CMS Content Snapshots</h2>'
            . '<div class="panel">' . $this->renderPageSnapshotsTable($pageSnapshots) . '</div>'
            . '</body></html>';
    }

    /**
     * @return list<string>
     */
    private function findMissingTables(): array
    {
        $tableNames = $this->connectionPool
            ->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->getSchemaInformation()
            ->listTableNames();

        $requiredTables = [
            self::GSC_TABLE,
            self::PAGE_SNAPSHOT_TABLE,
            self::RENDERED_SNAPSHOT_TABLE,
            self::RECOMMENDATION_TABLE,
            self::AI_RUN_TABLE,
        ];

        return array_values(array_diff($requiredTables, $tableNames));
    }

    /**
     * @param list<string> $missingTables
     */
    private function renderMissingTables(array $missingTables): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>SEO Assistant Setup Required</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2933;}'
            . '.box{max-width:860px;background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:18px;}'
            . 'h1{font-size:24px;margin:0 0 12px;}'
            . 'p{line-height:1.5;}'
            . 'code{display:block;background:#111827;color:#f8fafc;border-radius:4px;padding:12px;margin:10px 0;white-space:pre-wrap;}'
            . '.missing{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#991b1b;}'
            . '</style></head><body><div class="box">'
            . '<h1>SEO Assistant database setup required</h1>'
            . '<p>The extension is loaded, but its database tables are missing. Run TYPO3 extension setup on the live release.</p>'
            . '<code>cd /var/www/waldbytenew/current' . "\n" . 'php vendor/bin/typo3 extension:setup' . "\n" . 'php vendor/bin/typo3 cache:flush</code>'
            . '<p>Missing tables:</p><ul>'
            . implode('', array_map(fn(string $table): string => '<li class="missing">' . $this->escape($table) . '</li>', $missingTables))
            . '</ul></div></body></html>';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAiRuns(): array
    {
        return $this->connectionPool->getConnectionForTable(self::AI_RUN_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::AI_RUN_TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRecommendations(): array
    {
        return $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->orderBy('priority', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(100)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRenderedSnapshots(): array
    {
        return $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->orderBy('missing_alt_count', 'DESC')
            ->addOrderBy('word_count', 'ASC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(100)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchPageSnapshots(): array
    {
        return $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->orderBy('word_count', 'ASC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(100)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array{gsc:int,pages:int,rendered:int,recommendations:int,aiRuns:int}
     */
    private function fetchStats(): array
    {
        return [
            'gsc' => $this->countRows(self::GSC_TABLE),
            'pages' => $this->countRows(self::PAGE_SNAPSHOT_TABLE),
            'rendered' => $this->countRows(self::RENDERED_SNAPSHOT_TABLE),
            'recommendations' => $this->countRows(self::RECOMMENDATION_TABLE),
            'aiRuns' => $this->countRows(self::AI_RUN_TABLE),
        ];
    }

    private function countRows(string $table): int
    {
        return (int)$this->connectionPool->getConnectionForTable($table)
            ->createQueryBuilder()
            ->count('uid')
            ->from($table)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array{gsc:int,pages:int,rendered:int,recommendations:int,aiRuns:int} $stats
     */
    private function renderStats(array $stats): string
    {
        return '<div class="stats">'
            . '<div class="stat"><span class="muted">GSC rows</span><strong>' . $stats['gsc'] . '</strong></div>'
            . '<div class="stat"><span class="muted">CMS pages</span><strong>' . $stats['pages'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Rendered URLs</span><strong>' . $stats['rendered'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Recommendations</span><strong>' . $stats['recommendations'] . '</strong></div>'
            . '<div class="stat"><span class="muted">AI memory runs</span><strong>' . $stats['aiRuns'] . '</strong></div>'
            . '</div>';
    }

    /**
     * @param list<array<string,mixed>> $runs
     */
    private function renderAiRunsTable(array $runs): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Model</th><th>Mode</th><th>Pages</th><th>Generated</th><th>Stored</th><th>Focus</th>'
            . '</tr></thead><tbody>'
            . ($runs === [] ? '<tr><td colspan="7" class="muted">No AI runs recorded yet.</td></tr>' : '')
            . implode('', array_map($this->renderAiRunRow(...), $runs))
            . '</tbody></table>';
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     */
    private function renderRecommendationsTable(array $recommendations): string
    {
        return '<table><thead><tr>'
            . '<th>UID</th><th>Priority</th><th>Status</th><th>Type</th><th>Page</th><th>Query</th><th>Issue</th><th>Recommendation</th><th>Proposed Metadata</th><th>Apply</th>'
            . '</tr></thead><tbody>'
            . ($recommendations === [] ? '<tr><td colspan="10" class="muted">No recommendations yet. Run the snapshot and generate commands first.</td></tr>' : '')
            . implode('', array_map($this->renderRecommendationRow(...), $recommendations))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderAiRunRow(array $row): string
    {
        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td>' . $this->escape((string)($row['model'] ?? '')) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['mode'] ?? '')) . '</span></td>'
            . '<td>' . (int)($row['pages_analyzed'] ?? 0) . '</td>'
            . '<td>' . (int)($row['recommendations_generated'] ?? 0) . '</td>'
            . '<td>' . (int)($row['recommendations_stored'] ?? 0) . '</td>'
            . '<td>' . $this->escape((string)($row['focus_summary'] ?? '')) . '</td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     */
    private function renderRenderedSnapshotsTable(array $snapshots): string
    {
        return '<table><thead><tr>'
            . '<th>URL</th><th>Status</th><th>Rendered Title</th><th>Description</th><th>Words</th><th>H1</th><th>Images</th><th>Links</th><th>Issues</th>'
            . '</tr></thead><tbody>'
            . ($snapshots === [] ? '<tr><td colspan="9" class="muted">No rendered snapshots yet. Run <code>vendor/bin/typo3 seo:rendered:snapshot</code>.</td></tr>' : '')
            . implode('', array_map($this->renderRenderedSnapshotRow(...), $snapshots))
            . '</tbody></table>';
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     */
    private function renderPageSnapshotsTable(array $snapshots): string
    {
        return '<table><thead><tr>'
            . '<th>Page</th><th>SEO Title</th><th>Description</th><th>H1</th><th>Words</th><th>Robots</th><th>Content Preview</th>'
            . '</tr></thead><tbody>'
            . ($snapshots === [] ? '<tr><td colspan="7" class="muted">No CMS snapshots yet. Run <code>vendor/bin/typo3 seo:pages:snapshot</code>.</td></tr>' : '')
            . implode('', array_map($this->renderPageSnapshotRow(...), $snapshots))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderRecommendationRow(array $row): string
    {
        $metadata = '';
        if ((string)($row['proposed_seo_title'] ?? '') !== '') {
            $metadata .= '<strong>Title:</strong> ' . $this->escape((string)$row['proposed_seo_title']) . '<br>';
        }
        if ((string)($row['proposed_description'] ?? '') !== '') {
            $metadata .= '<strong>Description:</strong> ' . $this->escape((string)$row['proposed_description']);
        }
        if ($metadata === '') {
            $metadata = '<span class="muted">Manual review</span>';
        }

        $applyCommand = ($row['proposed_seo_title'] !== '' || $row['proposed_description'] !== '')
            ? 'vendor/bin/typo3 seo:recommendations:apply --uid=' . (int)$row['uid'] . ' --yes'
            : 'Manual content/template change';

        return '<tr>'
            . '<td>' . (int)($row['uid'] ?? 0) . '</td>'
            . '<td class="priority">' . (int)($row['priority'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape((string)($row['recommendation_type'] ?? '')) . '</td>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape((string)($row['query_text'] ?? '')) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['issue'] ?? ''))) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['recommendation'] ?? ''))) . '</td>'
            . '<td>' . $metadata . '</td>'
            . '<td><code>' . $this->escape($applyCommand) . '</code></td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderRenderedSnapshotRow(array $row): string
    {
        $issues = json_decode((string)($row['issues_json'] ?? '[]'), true);
        $issueHtml = '<span class="muted">No issues</span>';
        if (is_array($issues) && $issues !== []) {
            $issueHtml = '<div class="issues">' . implode('', array_map($this->renderIssuePill(...), $issues)) . '</div>';
        }

        return '<tr>'
            . '<td class="url">' . $this->renderUrl((string)($row['url'] ?? '')) . '</td>'
            . '<td>' . (int)($row['http_status'] ?? 0) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['html_title'] ?? ''), 90)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['meta_description'] ?? ''), 120)) . '</td>'
            . '<td>' . (int)($row['word_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['h1_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['image_count'] ?? 0) . ' / missing alt ' . (int)($row['missing_alt_count'] ?? 0) . '</td>'
            . '<td>internal ' . (int)($row['internal_link_count'] ?? 0) . '<br>external ' . (int)($row['external_link_count'] ?? 0) . '</td>'
            . '<td>' . $issueHtml . '</td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderPageSnapshotRow(array $row): string
    {
        return '<tr>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape($this->shorten((string)($row['seo_title'] ?: $row['title'] ?? ''), 90)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['description'] ?? ''), 120)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['h1'] ?? ''), 80)) . '</td>'
            . '<td>' . (int)($row['word_count'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['robots'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape($this->shorten((string)($row['content_text'] ?? ''), 180)) . '</td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function renderIssuePill(array $issue): string
    {
        $severity = (string)($issue['severity'] ?? 'notice');
        $code = (string)($issue['code'] ?? 'issue');
        $class = match ($severity) {
            'critical' => 'pill pill-critical',
            'warning' => 'pill pill-warning',
            default => 'pill pill-notice',
        };

        return '<span class="' . $class . '" title="' . $this->escape((string)($issue['message'] ?? '')) . '">'
            . $this->escape($code)
            . '</span>';
    }

    private function renderUrl(string $url): string
    {
        if ($url === '') {
            return '<span class="muted">No URL</span>';
        }

        return '<a href="' . $this->escape($url) . '" target="_blank" rel="noreferrer">' . $this->escape($url) . '</a>';
    }

    private function shorten(string $value, int $limit): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 1))) . '...';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
