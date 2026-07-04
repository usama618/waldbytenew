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
    private const SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse($this->render());
    }

    private function render(): string
    {
        $recommendations = $this->fetchRecommendations();
        $stats = $this->fetchStats();

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>SEO Assistant</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2933;}'
            . 'h1{font-size:24px;margin:0 0 20px;}'
            . '.stats{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;margin-bottom:20px;}'
            . '.stat{background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:14px;}'
            . '.stat strong{display:block;font-size:22px;margin-top:4px;}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #d9dde3;border-radius:6px;overflow:hidden;}'
            . 'th,td{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #e3e6ea;font-size:13px;}'
            . 'th{font-weight:700;background:#eef1f4;}'
            . 'tr:last-child td{border-bottom:0;}'
            . '.url{max-width:280px;word-break:break-word;}'
            . '.muted{color:#5d6875;}'
            . '.priority{font-weight:700;}'
            . '.pill{display:inline-block;padding:2px 7px;border-radius:999px;background:#e8edf3;color:#334155;font-size:12px;}'
            . 'code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;}'
            . '</style></head><body>'
            . '<h1>SEO Assistant</h1>'
            . $this->renderStats($stats)
            . '<table><thead><tr>'
            . '<th>Priority</th><th>Status</th><th>Type</th><th>Page</th><th>Query</th><th>Issue</th><th>Recommendation</th><th>Proposed Metadata</th><th>Apply</th>'
            . '</tr></thead><tbody>'
            . ($recommendations === [] ? '<tr><td colspan="9" class="muted">No recommendations yet. Run the CLI sync, snapshot and generate commands first.</td></tr>' : '')
            . implode('', array_map($this->renderRecommendationRow(...), $recommendations))
            . '</tbody></table>'
            . '</body></html>';
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
     * @return array{gsc:int,snapshots:int,recommendations:int}
     */
    private function fetchStats(): array
    {
        return [
            'gsc' => $this->countRows(self::GSC_TABLE),
            'snapshots' => $this->countRows(self::SNAPSHOT_TABLE),
            'recommendations' => $this->countRows(self::RECOMMENDATION_TABLE),
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
     * @param array{gsc:int,snapshots:int,recommendations:int} $stats
     */
    private function renderStats(array $stats): string
    {
        return '<div class="stats">'
            . '<div class="stat"><span class="muted">GSC rows</span><strong>' . $stats['gsc'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Page snapshots</span><strong>' . $stats['snapshots'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Recommendations</span><strong>' . $stats['recommendations'] . '</strong></div>'
            . '</div>';
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
            $metadata = '<span class="muted">No metadata proposal</span>';
        }

        $applyCommand = 'vendor/bin/typo3 seo:recommendations:apply --uid=' . (int)$row['uid'] . ' --yes';

        return '<tr>'
            . '<td class="priority">' . (int)($row['priority'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape((string)($row['recommendation_type'] ?? '')) . '</td>'
            . '<td class="url"><a href="' . $this->escape((string)($row['page_url'] ?? '')) . '" target="_blank" rel="noreferrer">'
            . $this->escape((string)($row['page_url'] ?? '')) . '</a><br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape((string)($row['query_text'] ?? '')) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['issue'] ?? ''))) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['recommendation'] ?? ''))) . '</td>'
            . '<td>' . $metadata . '</td>'
            . '<td><code>' . $this->escape($applyCommand) . '</code></td>'
            . '</tr>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
