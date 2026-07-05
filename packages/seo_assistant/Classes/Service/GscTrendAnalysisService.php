<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use DateTimeImmutable;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class GscTrendAnalysisService
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const INSIGHT_TABLE = 'tx_seoassistant_gsc_insight';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @return array{currentRows:int,previousRows:int,evaluated:int,stored:int,currentStart:string,currentEnd:string,previousStart:string,previousEnd:string}
     */
    public function analyze(
        string $currentStart,
        string $currentEnd,
        string $previousStart,
        string $previousEnd,
        int $minImpressions = 20,
        int $limit = 100,
        string $searchType = 'web',
        bool $dryRun = false,
    ): array {
        $currentFrom = $this->dateToTimestamp($currentStart);
        $currentTo = $this->dateToTimestamp($currentEnd);
        $previousFrom = $this->dateToTimestamp($previousStart);
        $previousTo = $this->dateToTimestamp($previousEnd);
        if ($previousTo >= $currentFrom) {
            throw new RuntimeException('Previous comparison window must end before the current window starts.', 1760000061);
        }

        $currentPages = $this->fetchCurrentPageMap();
        if ($currentPages === []) {
            throw new RuntimeException('No current TYPO3 page snapshots found. Run seo:pages:snapshot before analyzing GSC trends.', 1760000063);
        }
        $currentMetrics = $this->fetchWindowMetrics($currentFrom, $currentTo, $searchType, $currentPages);
        $previousMetrics = $this->fetchWindowMetrics($previousFrom, $previousTo, $searchType, $currentPages);
        $insights = $this->buildInsights(
            $currentMetrics,
            $previousMetrics,
            $currentPages,
            $currentFrom,
            $currentTo,
            $previousFrom,
            $previousTo,
            max(1, $minImpressions)
        );

        usort(
            $insights,
            static fn(array $a, array $b): int => (int)($b['priority'] <=> $a['priority'])
        );
        $insights = array_slice($insights, 0, max(1, $limit));

        $stored = 0;
        if (!$dryRun) {
            foreach ($insights as $insight) {
                $stored += $this->storeInsight($insight);
            }
        }

        return [
            'currentRows' => count($currentMetrics),
            'previousRows' => count($previousMetrics),
            'evaluated' => count($insights),
            'stored' => $stored,
            'currentStart' => $currentStart,
            'currentEnd' => $currentEnd,
            'previousStart' => $previousStart,
            'previousEnd' => $previousEnd,
        ];
    }

    /**
     * @return array<string,array{page_uid:int,page_url:string}>
     */
    private function fetchCurrentPageMap(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('page_uid', 'page_url')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->where('page_url <> :empty')
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pageUrl = (string)($row['page_url'] ?? '');
            $normalizedUrl = $this->urlNormalizer->normalize($pageUrl);
            if ($normalizedUrl === '') {
                continue;
            }
            $pages[$normalizedUrl] = [
                'page_uid' => (int)($row['page_uid'] ?? 0),
                'page_url' => $pageUrl,
            ];
        }

        return $pages;
    }

    /**
     * @param array<string,array{page_uid:int,page_url:string}> $currentPages
     * @return array<string,array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float}>
     */
    private function fetchWindowMetrics(int $from, int $to, string $searchType, array $currentPages): array
    {
        if ($currentPages === []) {
            return [];
        }

        $rows = $this->connectionPool->getConnectionForTable(self::GSC_TABLE)
            ->createQueryBuilder()
            ->select('page_url', 'query_text')
            ->addSelectLiteral('SUM(clicks) AS clicks_sum')
            ->addSelectLiteral('SUM(impressions) AS impressions_sum')
            ->addSelectLiteral('SUM(position * impressions) AS weighted_position_sum')
            ->from(self::GSC_TABLE)
            ->where('date_from = :dateFrom')
            ->andWhere('date_to = :dateTo')
            ->andWhere('search_type = :searchType')
            ->andWhere('page_url <> :empty')
            ->andWhere('query_text <> :empty')
            ->groupBy('page_url', 'query_text')
            ->setParameter('dateFrom', $from, Connection::PARAM_INT)
            ->setParameter('dateTo', $to, Connection::PARAM_INT)
            ->setParameter('searchType', $searchType)
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchAllAssociative();

        $metrics = [];
        foreach ($rows as $row) {
            $pageUrl = (string)($row['page_url'] ?? '');
            $normalizedUrl = $this->urlNormalizer->normalize($pageUrl);
            if (!isset($currentPages[$normalizedUrl])) {
                continue;
            }

            $query = trim((string)($row['query_text'] ?? ''));
            if ($query === '') {
                continue;
            }

            $clicks = (float)($row['clicks_sum'] ?? 0);
            $impressions = (float)($row['impressions_sum'] ?? 0);
            $position = $impressions > 0
                ? (float)($row['weighted_position_sum'] ?? 0) / $impressions
                : 0.0;
            $key = $this->metricKey($normalizedUrl, $query);

            $metrics[$key] = [
                'page_url' => $currentPages[$normalizedUrl]['page_url'],
                'query_text' => $query,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
                'position' => $position,
            ];
        }

        return $metrics;
    }

    /**
     * @param array<string,array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float}> $currentMetrics
     * @param array<string,array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float}> $previousMetrics
     * @param array<string,array{page_uid:int,page_url:string}> $currentPages
     * @return list<array<string,mixed>>
     */
    private function buildInsights(
        array $currentMetrics,
        array $previousMetrics,
        array $currentPages,
        int $currentFrom,
        int $currentTo,
        int $previousFrom,
        int $previousTo,
        int $minImpressions,
    ): array {
        $keys = array_values(array_unique(array_merge(array_keys($currentMetrics), array_keys($previousMetrics))));
        $insights = [];

        foreach ($keys as $key) {
            $current = $currentMetrics[$key] ?? null;
            $previous = $previousMetrics[$key] ?? null;
            if ($current === null && $previous === null) {
                continue;
            }

            $base = $current ?? $previous;
            $normalizedUrl = $this->urlNormalizer->normalize((string)$base['page_url']);
            if (!isset($currentPages[$normalizedUrl])) {
                continue;
            }

            $current = $current ?? $this->emptyMetrics((string)$base['page_url'], (string)$base['query_text']);
            $previous = $previous ?? $this->emptyMetrics((string)$base['page_url'], (string)$base['query_text']);
            if (max($current['impressions'], $previous['impressions']) < $minImpressions) {
                continue;
            }

            $clicksDelta = $current['clicks'] - $previous['clicks'];
            $impressionsDelta = $current['impressions'] - $previous['impressions'];
            $ctrDelta = $current['ctr'] - $previous['ctr'];
            $positionDelta = $previous['position'] > 0 && $current['position'] > 0
                ? $previous['position'] - $current['position']
                : 0.0;

            $classification = $this->classifyTrend($current, $previous, $clicksDelta, $impressionsDelta, $ctrDelta, $positionDelta);
            if ($classification === null) {
                continue;
            }

            [$trendType, $priority, $summary] = $classification;
            $pageUrl = (string)$current['page_url'];
            $query = (string)$current['query_text'];
            $insights[] = [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'page_uid' => $currentPages[$normalizedUrl]['page_uid'],
                'page_url' => $pageUrl,
                'query_text' => $query,
                'current_from' => $currentFrom,
                'current_to' => $currentTo,
                'previous_from' => $previousFrom,
                'previous_to' => $previousTo,
                'current_clicks' => $current['clicks'],
                'current_impressions' => $current['impressions'],
                'current_ctr' => $current['ctr'],
                'current_position' => $current['position'],
                'previous_clicks' => $previous['clicks'],
                'previous_impressions' => $previous['impressions'],
                'previous_ctr' => $previous['ctr'],
                'previous_position' => $previous['position'],
                'clicks_delta' => $clicksDelta,
                'impressions_delta' => $impressionsDelta,
                'ctr_delta' => $ctrDelta,
                'position_delta' => $positionDelta,
                'trend_type' => $trendType,
                'priority' => $priority,
                'summary' => $summary,
                'evidence_json' => json_encode([
                    'current' => $current,
                    'previous' => $previous,
                    'clicks_delta_percent' => $this->percentDelta($current['clicks'], $previous['clicks']),
                    'impressions_delta_percent' => $this->percentDelta($current['impressions'], $previous['impressions']),
                    'ctr_delta_points' => $ctrDelta * 100,
                    'position_delta' => $positionDelta,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'insight_hash' => hash('sha256', implode('|', [
                    $pageUrl,
                    $query,
                    $currentFrom,
                    $currentTo,
                    $previousFrom,
                    $previousTo,
                    $trendType,
                ])),
            ];
        }

        return $insights;
    }

    /**
     * @param array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float} $current
     * @param array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float} $previous
     * @return array{string,int,string}|null
     */
    private function classifyTrend(
        array $current,
        array $previous,
        float $clicksDelta,
        float $impressionsDelta,
        float $ctrDelta,
        float $positionDelta,
    ): ?array {
        $currentClicks = $current['clicks'];
        $previousClicks = $previous['clicks'];
        $currentImpressions = $current['impressions'];
        $previousImpressions = $previous['impressions'];
        $currentCtr = $current['ctr'];
        $currentPosition = $current['position'];
        $clicksPercent = $this->percentDelta($currentClicks, $previousClicks);
        $impressionsPercent = $this->percentDelta($currentImpressions, $previousImpressions);

        if ($clicksDelta >= 3 && ($clicksPercent >= 0.2 || $previousClicks <= 5)) {
            $priority = 70 + min(25, (int)round($clicksDelta));
            return [
                'content_working',
                min(100, $priority),
                'Organic clicks are increasing. Keep and strengthen this content because the query/page pair is gaining traction.',
            ];
        }

        if ($clicksDelta <= -3 && ($clicksPercent <= -0.2 || $previousClicks >= 10)) {
            $priority = 75 + min(20, (int)round(abs($clicksDelta)));
            return [
                'content_declining',
                min(100, $priority),
                'Organic clicks are decreasing. Review ranking, SERP intent, title/description and freshness for this content.',
            ];
        }

        if ($impressionsDelta >= 20 && $impressionsPercent >= 0.25 && $clicksDelta <= 1 && $currentCtr < 0.03) {
            return [
                'visibility_opportunity',
                82,
                'Visibility is increasing but clicks are not following. Improve title, description and content alignment for the search intent.',
            ];
        }

        if ($currentImpressions >= 50 && $currentClicks <= 1 && $currentCtr < 0.015) {
            return [
                'content_not_working',
                78,
                'The content gets impressions but almost no organic clicks. Treat this as weak search-result fit or weak content match.',
            ];
        }

        if ($currentImpressions >= 20 && $currentPosition >= 4 && $currentPosition <= 20 && $positionDelta >= 1) {
            return [
                'striking_distance_improving',
                72,
                'Average position is improving in striking distance. Expand the section, add internal links and reinforce the topic.',
            ];
        }

        if ($currentImpressions >= 20 && $currentPosition >= 4 && $currentPosition <= 20 && $currentCtr < 0.04) {
            return [
                'striking_distance',
                68,
                'The page ranks within reach but CTR is still weak. Improve snippet and answer depth for this query.',
            ];
        }

        if ($impressionsDelta <= -20 && $impressionsPercent <= -0.25 && $clicksDelta <= 0) {
            return [
                'visibility_declining',
                66,
                'Search visibility is declining. Check whether demand shifted, rankings dropped, or the content became stale.',
            ];
        }

        return null;
    }

    /**
     * @return array{page_url:string,query_text:string,clicks:float,impressions:float,ctr:float,position:float}
     */
    private function emptyMetrics(string $pageUrl, string $query): array
    {
        return [
            'page_url' => $pageUrl,
            'query_text' => $query,
            'clicks' => 0.0,
            'impressions' => 0.0,
            'ctr' => 0.0,
            'position' => 0.0,
        ];
    }

    private function percentDelta(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 1.0 : 0.0;
        }

        return ($current - $previous) / $previous;
    }

    /**
     * @param array<string,mixed> $insight
     */
    private function storeInsight(array $insight): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::INSIGHT_TABLE);
        $existingUid = (int)$connection->createQueryBuilder()
            ->select('uid')
            ->from(self::INSIGHT_TABLE)
            ->where('insight_hash = :insightHash')
            ->setParameter('insightHash', (string)$insight['insight_hash'])
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'page_uid' => Connection::PARAM_INT,
            'current_from' => Connection::PARAM_INT,
            'current_to' => Connection::PARAM_INT,
            'previous_from' => Connection::PARAM_INT,
            'previous_to' => Connection::PARAM_INT,
            'priority' => Connection::PARAM_INT,
        ];

        if ($existingUid > 0) {
            unset($insight['crdate']);
            unset($types['crdate']);
            $connection->update(self::INSIGHT_TABLE, $insight, ['uid' => $existingUid], $types);
            return 1;
        }

        $connection->insert(self::INSIGHT_TABLE, $insight, $types);
        return 1;
    }

    private function metricKey(string $normalizedUrl, string $query): string
    {
        return $normalizedUrl . '|' . mb_strtolower(trim($query));
    }

    private function dateToTimestamp(string $date): int
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateTime instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid date "' . $date . '". Use YYYY-MM-DD.', 1760000062);
        }

        return $dateTime->getTimestamp();
    }
}
