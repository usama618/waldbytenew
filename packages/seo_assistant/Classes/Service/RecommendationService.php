<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationService
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly OpenAiRecommendationService $openAiRecommendationService,
    ) {}

    /**
     * @return array{evaluated:int,stored:int,aiUsed:int,aiConfigured:bool}
     */
    public function generate(int $minImpressions = 20, int $limit = 100, bool $useAi = true, int $aiLimit = 10): array
    {
        $snapshots = $this->fetchSnapshotsByUrl();
        $candidates = $this->fetchGscCandidates($minImpressions, $limit);
        $stored = 0;
        $aiUsed = 0;

        foreach ($candidates as $candidate) {
            $pageUrl = (string)($candidate['page_url'] ?? '');
            $query = trim((string)($candidate['query_text'] ?? ''));
            if ($pageUrl === '' || $query === '') {
                continue;
            }

            $snapshot = $snapshots[$this->urlNormalizer->normalize($pageUrl)] ?? null;
            $recommendation = $this->buildRuleBasedRecommendation($candidate, $snapshot);
            if ($recommendation === null) {
                continue;
            }

            if ($useAi && $aiUsed < $aiLimit && $this->openAiRecommendationService->isConfigured()) {
                $aiRecommendation = $this->openAiRecommendationService->createRecommendation([
                    'page_url' => $pageUrl,
                    'query' => $query,
                    'gsc' => [
                        'clicks' => (float)($candidate['clicks_sum'] ?? 0),
                        'impressions' => (float)($candidate['impressions_sum'] ?? 0),
                        'ctr' => $this->calculateCtr($candidate),
                        'position' => (float)($candidate['avg_position'] ?? 0),
                    ],
                    'page' => [
                        'title' => (string)($snapshot['title'] ?? ''),
                        'seo_title' => (string)($snapshot['seo_title'] ?? ''),
                        'description' => (string)($snapshot['description'] ?? ''),
                        'h1' => (string)($snapshot['h1'] ?? ''),
                        'word_count' => (int)($snapshot['word_count'] ?? 0),
                        'content_excerpt' => mb_substr((string)($snapshot['content_text'] ?? ''), 0, 2500),
                    ],
                    'rule_based_issue' => $recommendation['issue'],
                    'rule_based_recommendation' => $recommendation['recommendation'],
                ]);

                if ($aiRecommendation !== null) {
                    $recommendation = array_merge($recommendation, [
                        'issue' => $aiRecommendation['issue'] !== '' ? $aiRecommendation['issue'] : $recommendation['issue'],
                        'recommendation' => $aiRecommendation['recommendation'] !== '' ? $aiRecommendation['recommendation'] : $recommendation['recommendation'],
                        'proposed_seo_title' => $aiRecommendation['proposed_seo_title'] !== '' ? $aiRecommendation['proposed_seo_title'] : $recommendation['proposed_seo_title'],
                        'proposed_description' => $aiRecommendation['proposed_description'] !== '' ? $aiRecommendation['proposed_description'] : $recommendation['proposed_description'],
                        'priority' => $aiRecommendation['priority'],
                        'ai_model' => $this->openAiRecommendationService->getModel(),
                    ]);
                    $aiUsed++;
                }
            }

            $stored += $this->storeRecommendation($recommendation);
        }

        return [
            'evaluated' => count($candidates),
            'stored' => $stored,
            'aiUsed' => $aiUsed,
            'aiConfigured' => $this->openAiRecommendationService->isConfigured(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function fetchSnapshotsByUrl(): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::SNAPSHOT_TABLE);
        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::SNAPSHOT_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[$this->urlNormalizer->normalize((string)($row['page_url'] ?? ''))] = $row;
        }

        return $snapshots;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchGscCandidates(int $minImpressions, int $limit): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::GSC_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('page_url', 'query_text')
            ->addSelect('SUM(clicks) AS clicks_sum')
            ->addSelect('SUM(impressions) AS impressions_sum')
            ->addSelect('AVG(position) AS avg_position')
            ->from(self::GSC_TABLE)
            ->where('page_url <> :empty')
            ->andWhere('query_text <> :empty')
            ->groupBy('page_url', 'query_text')
            ->having('SUM(impressions) >= :minImpressions')
            ->orderBy('impressions_sum', 'DESC')
            ->addOrderBy('avg_position', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->setParameter('empty', '')
            ->setParameter('minImpressions', max(1, $minImpressions), Connection::PARAM_INT);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>|null
     */
    private function buildRuleBasedRecommendation(array $candidate, ?array $snapshot): ?array
    {
        $pageUrl = (string)$candidate['page_url'];
        $query = trim((string)$candidate['query_text']);
        $impressions = (float)($candidate['impressions_sum'] ?? 0);
        $position = (float)($candidate['avg_position'] ?? 0);
        $ctr = $this->calculateCtr($candidate);
        $pageText = implode(' ', [
            (string)($snapshot['title'] ?? ''),
            (string)($snapshot['seo_title'] ?? ''),
            (string)($snapshot['description'] ?? ''),
            (string)($snapshot['h1'] ?? ''),
            (string)($snapshot['content_text'] ?? ''),
        ]);
        $queryCovered = $this->textCoversQuery($pageText, $query);

        $type = '';
        $priority = 50;
        $issue = '';
        $action = '';

        if ($snapshot !== null && trim((string)($snapshot['description'] ?? '')) === '') {
            $type = 'missing_meta_description';
            $priority = 95;
            $issue = 'Die Seite hat keine Meta Description, obwohl sie Suchimpressionen erzeugt.';
            $action = 'Eine konkrete Meta Description ergaenzen, die Suchintention und Nutzen klar verbindet.';
        } elseif ($impressions >= 50 && $ctr < 0.02 && $position <= 20) {
            $type = 'low_ctr';
            $priority = 85;
            $issue = 'Die Suchanfrage hat viele Impressionen, aber eine niedrige Klickrate.';
            $action = 'Title und Description staerker auf die Suchintention zuschneiden und den konkreten Nutzen sichtbarer machen.';
        } elseif ($position >= 4 && $position <= 20 && $impressions >= 20) {
            $type = 'striking_distance';
            $priority = 75;
            $issue = 'Die Seite rankt in Reichweite, aber noch nicht stabil auf den oberen Positionen.';
            $action = 'Den Abschnitt zur Suchanfrage ausbauen, interne Links setzen und die wichtigsten Begriffe in H1/H2/Intro pruefen.';
        } elseif (!$queryCovered && $impressions >= 20) {
            $type = 'content_gap';
            $priority = 70;
            $issue = 'Die Suchanfrage kommt in den wichtigsten Seitensignalen nicht klar genug vor.';
            $action = 'Einen kurzen, hilfreichen Abschnitt zur Suchanfrage aufnehmen und semantisch passende Begriffe einarbeiten.';
        }

        if ($type === '') {
            return null;
        }

        $proposedTitle = $this->buildProposedTitle($query, $snapshot);
        $proposedDescription = $this->buildProposedDescription($query);

        return [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'page_uid' => (int)($snapshot['page_uid'] ?? 0),
            'page_url' => $pageUrl,
            'query_text' => $query,
            'recommendation_type' => $type,
            'priority' => $priority,
            'status' => 'draft',
            'issue' => $issue,
            'recommendation' => $action,
            'proposed_seo_title' => $proposedTitle,
            'proposed_description' => $proposedDescription,
            'evidence_json' => json_encode([
                'clicks' => (float)($candidate['clicks_sum'] ?? 0),
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position' => $position,
                'query_covered' => $queryCovered,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ai_model' => '',
            'dedupe_hash' => hash('sha256', implode('|', [$type, $pageUrl, $query])),
            'approved_at' => 0,
            'applied_at' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function calculateCtr(array $candidate): float
    {
        $impressions = (float)($candidate['impressions_sum'] ?? 0);
        if ($impressions <= 0) {
            return 0.0;
        }

        return (float)($candidate['clicks_sum'] ?? 0) / $impressions;
    }

    private function textCoversQuery(string $text, string $query): bool
    {
        $text = mb_strtolower($text);
        $query = mb_strtolower($query);
        if ($query !== '' && str_contains($text, $query)) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significantTokens = array_filter($tokens, static fn(string $token): bool => mb_strlen($token) >= 5);
        if ($significantTokens === []) {
            return false;
        }

        $matches = 0;
        foreach ($significantTokens as $token) {
            if (str_contains($text, $token)) {
                $matches++;
            }
        }

        return $matches >= max(1, (int)ceil(count($significantTokens) / 2));
    }

    /**
     * @param array<string,mixed>|null $snapshot
     */
    private function buildProposedTitle(string $query, ?array $snapshot): string
    {
        $baseTitle = trim((string)($snapshot['seo_title'] ?? ''));
        if ($baseTitle === '') {
            $baseTitle = trim((string)($snapshot['title'] ?? ''));
        }

        $query = trim($query);
        $title = $query !== '' ? ucfirst($query) . ' | WALDBYTE' : $baseTitle;
        if ($baseTitle !== '' && $this->textCoversQuery($baseTitle, $query)) {
            $title = $baseTitle;
        }

        return mb_substr($title, 0, 60);
    }

    private function buildProposedDescription(string $query): string
    {
        $description = 'WALDBYTE zeigt, worauf es bei ' . trim($query)
            . ' ankommt und wie Unternehmen in der Region Karlsruhe mehr Sichtbarkeit und qualifizierte Anfragen gewinnen.';

        return mb_substr($description, 0, 155);
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function storeRecommendation(array $recommendation): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE);
        $existing = $connection->createQueryBuilder()
            ->select('uid', 'status')
            ->from(self::RECOMMENDATION_TABLE)
            ->where('dedupe_hash = :dedupeHash')
            ->setParameter('dedupeHash', (string)$recommendation['dedupe_hash'])
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'page_uid' => Connection::PARAM_INT,
            'priority' => Connection::PARAM_INT,
            'approved_at' => Connection::PARAM_INT,
            'applied_at' => Connection::PARAM_INT,
        ];

        if (is_array($existing) && (int)$existing['uid'] > 0) {
            if ((string)$existing['status'] !== 'draft') {
                return 0;
            }
            unset($recommendation['crdate']);
            unset($recommendation['status']);
            unset($types['crdate']);
            $connection->update(self::RECOMMENDATION_TABLE, $recommendation, ['uid' => (int)$existing['uid']], $types);
            return 1;
        }

        $connection->insert(self::RECOMMENDATION_TABLE, $recommendation, $types);
        return 1;
    }
}
