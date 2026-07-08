<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationService
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const IMPACT_TABLE = 'tx_seoassistant_impact_evaluation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly OpenAiRecommendationService $openAiRecommendationService,
        private readonly AiRunHistoryService $aiRunHistoryService,
    ) {}

    /**
     * @return array{evaluated:int,renderedEvaluated:int,stored:int,aiUsed:int,aiConfigured:bool,generationMode:string,fallbackUsed:bool}
     */
    public function generate(int $minImpressions = 20, int $limit = 100, bool $useAi = true, int $aiLimit = 10): array
    {
        $snapshots = $this->fetchSnapshotsByUrl();
        $currentPageUrls = array_fill_keys(array_keys($snapshots), true);
        $renderedSnapshots = $this->fetchRenderedSnapshotsByUrl($currentPageUrls);
        $candidates = $this->fetchGscCandidates($minImpressions, $limit, $currentPageUrls);
        $stored = 0;
        $aiUsed = 0;
        $fallbackUsed = false;

        if ($useAi && $this->openAiRecommendationService->isConfigured()) {
            $recentRuns = $this->aiRunHistoryService->getRecentRuns();
            $impactLearning = $this->fetchImpactLearningContext();
            $aiResult = $this->generateAiFirstRecommendations($candidates, $snapshots, $renderedSnapshots, $aiLimit, $recentRuns, $impactLearning);
            $stored = $aiResult['stored'];
            $aiUsed = $aiResult['aiUsed'];

            if ($aiResult['generated'] > 0) {
                return [
                    'evaluated' => count($candidates),
                    'renderedEvaluated' => count($renderedSnapshots),
                    'stored' => $stored,
                    'aiUsed' => $aiUsed,
                    'aiConfigured' => true,
                    'generationMode' => 'ai',
                    'fallbackUsed' => false,
                ];
            }

            $fallbackUsed = true;
        }

        $stored = $this->generateRuleBasedRecommendations($candidates, $snapshots, $renderedSnapshots);
        $stored += $this->generateRenderedRecommendations($renderedSnapshots, $snapshots);

        return [
            'evaluated' => count($candidates),
            'renderedEvaluated' => count($renderedSnapshots),
            'stored' => $stored,
            'aiUsed' => $aiUsed,
            'aiConfigured' => $this->openAiRecommendationService->isConfigured(),
            'generationMode' => 'rule',
            'fallbackUsed' => $fallbackUsed,
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
     * @return array<string,array<string,mixed>>
     */
    private function fetchRenderedSnapshotsByUrl(array $currentPageUrls): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE);
        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $snapshots = [];
        foreach ($rows as $row) {
            $normalizedUrl = $this->urlNormalizer->normalize((string)($row['url'] ?? ''));
            if (!isset($currentPageUrls[$normalizedUrl])) {
                continue;
            }
            $snapshots[$normalizedUrl] = $row;
        }

        return $snapshots;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchGscCandidates(int $minImpressions, int $limit, array $currentPageUrls): array
    {
        if ($currentPageUrls === []) {
            return [];
        }

        $connection = $this->connectionPool->getConnectionForTable(self::GSC_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('page_url', 'query_text')
            ->addSelectLiteral('SUM(clicks) AS clicks_sum')
            ->addSelectLiteral('SUM(impressions) AS impressions_sum')
            ->addSelectLiteral('AVG(position) AS avg_position')
            ->from(self::GSC_TABLE)
            ->where('page_url <> :empty')
            ->andWhere('query_text <> :empty')
            ->groupBy('page_url', 'query_text')
            ->having('SUM(impressions) >= :minImpressions')
            ->orderBy('impressions_sum', 'DESC')
            ->addOrderBy('avg_position', 'ASC')
            ->setMaxResults(max(1, min(5000, $limit * 20)))
            ->setParameter('empty', '')
            ->setParameter('minImpressions', max(1, $minImpressions), Connection::PARAM_INT);

        $candidates = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $normalizedUrl = $this->urlNormalizer->normalize((string)($row['page_url'] ?? ''));
            if (!isset($currentPageUrls[$normalizedUrl])) {
                continue;
            }
            $candidates[] = $row;
            if (count($candidates) >= max(1, $limit)) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param array<string,array<string,mixed>> $snapshots
     * @param array<string,array<string,mixed>> $renderedSnapshots
     * @param list<array<string,mixed>> $recentRuns
     * @param list<array<string,mixed>> $impactLearning
     * @return array{stored:int,aiUsed:int,generated:int}
     */
    private function generateAiFirstRecommendations(array $candidates, array $snapshots, array $renderedSnapshots, int $aiLimit, array $recentRuns, array $impactLearning): array
    {
        $stored = 0;
        $aiUsed = 0;
        $generated = 0;
        $storedRecommendations = [];
        $contexts = $this->buildAiPageContexts($candidates, $snapshots, $renderedSnapshots, max(1, $aiLimit), $recentRuns, $impactLearning);

        foreach ($contexts as $context) {
            $aiRecommendations = $this->openAiRecommendationService->createRecommendations($context, 4);
            if ($aiRecommendations === []) {
                continue;
            }

            $aiUsed++;
            $generated += count($aiRecommendations);
            foreach ($aiRecommendations as $aiRecommendation) {
                $recommendation = $this->buildRecommendationFromAi($aiRecommendation, $context);
                $storedRecommendations[] = $recommendation;
                $stored += $this->storeRecommendation($recommendation);
            }
        }

        $this->aiRunHistoryService->recordRun(
            $this->openAiRecommendationService->getModel(),
            'ai',
            count($contexts),
            $generated,
            $stored,
            $contexts,
            $storedRecommendations
        );

        return [
            'stored' => $stored,
            'aiUsed' => $aiUsed,
            'generated' => $generated,
        ];
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param array<string,array<string,mixed>> $snapshots
     * @param array<string,array<string,mixed>> $renderedSnapshots
     * @param list<array<string,mixed>> $recentRuns
     * @param list<array<string,mixed>> $impactLearning
     * @return list<array<string,mixed>>
     */
    private function buildAiPageContexts(array $candidates, array $snapshots, array $renderedSnapshots, int $limit, array $recentRuns, array $impactLearning): array
    {
        $grouped = [];
        foreach ($candidates as $candidate) {
            $pageUrl = (string)($candidate['page_url'] ?? '');
            if ($pageUrl === '') {
                continue;
            }

            $normalizedUrl = $this->urlNormalizer->normalize($pageUrl);
            $grouped[$normalizedUrl]['page_url'] ??= $pageUrl;
            $grouped[$normalizedUrl]['queries'][] = [
                'query' => (string)($candidate['query_text'] ?? ''),
                'clicks' => (float)($candidate['clicks_sum'] ?? 0),
                'impressions' => (float)($candidate['impressions_sum'] ?? 0),
                'ctr' => $this->calculateCtr($candidate),
                'position' => (float)($candidate['avg_position'] ?? 0),
            ];
        }

        foreach ($renderedSnapshots as $normalizedUrl => $renderedSnapshot) {
            $issues = json_decode((string)($renderedSnapshot['issues_json'] ?? '[]'), true);
            if ($issues === [] || !is_array($issues)) {
                continue;
            }
            $grouped[$normalizedUrl]['page_url'] ??= (string)($renderedSnapshot['url'] ?? '');
            $grouped[$normalizedUrl]['has_rendered_issues'] = true;
        }

        $contexts = [];
        foreach ($grouped as $normalizedUrl => $group) {
            $pageUrl = (string)($group['page_url'] ?? '');
            if ($pageUrl === '') {
                continue;
            }

            $cmsSnapshot = $snapshots[$normalizedUrl] ?? null;
            $renderedSnapshot = $renderedSnapshots[$normalizedUrl] ?? null;
            $queries = $group['queries'] ?? [];
            if (is_array($queries)) {
                usort($queries, static fn(array $a, array $b): int => (int)($b['impressions'] <=> $a['impressions']));
            }

            $contexts[] = [
                'mode' => 'ai_first_seo_recommendation',
                'page_url' => $pageUrl,
                'page_uid' => (int)($cmsSnapshot['page_uid'] ?? 0),
                'business_context' => [
                    'brand' => 'WALDBYTE',
                    'market' => 'Deutschland',
                    'local_focus' => 'Region Karlsruhe',
                    'website_type' => 'TYPO3 agency website',
                ],
                'search_console_queries' => array_slice(array_values($queries), 0, 8),
                'cms_page' => $this->buildCmsSnapshotContext($cmsSnapshot),
                'rendered_page' => $this->buildRenderedSnapshotContext($renderedSnapshot),
                'recent_ai_run_memory' => $recentRuns,
                'impact_learning_memory' => $impactLearning,
                'instruction' => 'Find the highest impact SEO actions for this page. Prefer concrete metadata, content section, internal link, image alt, structured data, or technical indexing recommendations. Use impact_learning_memory to avoid repeating declined/neutral patterns and to favor action types that previously improved comparable pages. Return no recommendation if no useful action exists.',
            ];

            if (count($contexts) >= $limit) {
                break;
            }
        }

        return $contexts;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>
     */
    private function buildCmsSnapshotContext(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        return [
            'title' => (string)($snapshot['title'] ?? ''),
            'seo_title' => (string)($snapshot['seo_title'] ?? ''),
            'description' => (string)($snapshot['description'] ?? ''),
            'slug' => (string)($snapshot['slug'] ?? ''),
            'h1' => (string)($snapshot['h1'] ?? ''),
            'robots' => (string)($snapshot['robots'] ?? ''),
            'canonical_url' => (string)($snapshot['canonical_url'] ?? ''),
            'word_count' => (int)($snapshot['word_count'] ?? 0),
            'content_excerpt' => mb_substr((string)($snapshot['content_text'] ?? ''), 0, 3500),
        ];
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>
     */
    private function buildRenderedSnapshotContext(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $headings = json_decode((string)($snapshot['headings_json'] ?? '[]'), true);
        $images = json_decode((string)($snapshot['images_json'] ?? '[]'), true);
        $issues = json_decode((string)($snapshot['issues_json'] ?? '[]'), true);

        return [
            'http_status' => (int)($snapshot['http_status'] ?? 0),
            'html_title' => (string)($snapshot['html_title'] ?? ''),
            'meta_description' => (string)($snapshot['meta_description'] ?? ''),
            'canonical_url' => (string)($snapshot['canonical_url'] ?? ''),
            'robots' => (string)($snapshot['robots'] ?? ''),
            'word_count' => (int)($snapshot['word_count'] ?? 0),
            'h1_count' => (int)($snapshot['h1_count'] ?? 0),
            'image_count' => (int)($snapshot['image_count'] ?? 0),
            'missing_alt_count' => (int)($snapshot['missing_alt_count'] ?? 0),
            'internal_link_count' => (int)($snapshot['internal_link_count'] ?? 0),
            'external_link_count' => (int)($snapshot['external_link_count'] ?? 0),
            'headings' => is_array($headings) ? array_slice($headings, 0, 24) : [],
            'images_missing_alt_sample' => $this->filterMissingAltImages(is_array($images) ? $images : []),
            'issues' => is_array($issues) ? $issues : [],
            'visible_text_excerpt' => mb_substr((string)($snapshot['visible_text'] ?? ''), 0, 3500),
        ];
    }

    /**
     * @param list<mixed> $images
     * @return list<mixed>
     */
    private function filterMissingAltImages(array $images): array
    {
        $missing = [];
        foreach ($images as $image) {
            if (is_array($image) && !empty($image['missing_alt'])) {
                $missing[] = $image;
            }
            if (count($missing) >= 12) {
                break;
            }
        }

        return $missing;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchImpactLearningContext(int $limit = 30): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::IMPACT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::IMPACT_TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();

        $learning = [];
        foreach ($rows as $row) {
            $evidence = json_decode((string)($row['evidence_json'] ?? '{}'), true);
            $recommendation = [];
            if (is_array($evidence)
                && is_array($evidence['context'] ?? null)
                && is_array($evidence['context']['recommendation'] ?? null)
            ) {
                $recommendation = $evidence['context']['recommendation'];
            }

            $learning[] = [
                'evaluated_at' => date('Y-m-d', (int)($row['crdate'] ?? 0)),
                'evaluation_stage' => (string)($row['evaluation_stage'] ?? '') !== ''
                    ? (string)$row['evaluation_stage']
                    : $this->evaluationStage((int)($row['window_days'] ?? 0), (int)($row['buffer_days'] ?? 0)),
                'impact_status' => (string)($row['impact_status'] ?? ''),
                'confidence' => (string)($row['confidence'] ?? ''),
                'page_url' => (string)($row['page_url'] ?? ''),
                'query' => (string)($row['query_text'] ?? ''),
                'recommendation_type' => (string)($recommendation['type'] ?? ''),
                'action_type' => (string)($recommendation['action_type'] ?? ''),
                'apply_capability' => (string)($recommendation['apply_capability'] ?? ''),
                'summary' => mb_substr(trim((string)($row['ai_summary'] ?? '')), 0, 320),
                'next_action' => mb_substr(trim((string)($row['ai_next_action'] ?? '')), 0, 260),
                'delta' => [
                    'clicks' => (float)($row['clicks_delta'] ?? 0),
                    'impressions' => (float)($row['impressions_delta'] ?? 0),
                    'ctr' => (float)($row['ctr_delta'] ?? 0),
                    'position' => (float)($row['position_delta'] ?? 0),
                ],
            ];
        }

        return $learning;
    }

    private function evaluationStage(int $windowDays, int $bufferDays): string
    {
        $totalDays = $windowDays + $bufferDays;
        if ($totalDays <= 14) {
            return 'early_signal_14d';
        }
        if ($totalDays <= 35) {
            return 'first_evaluation_35d';
        }
        if ($totalDays <= 63) {
            return 'stronger_evaluation_63d';
        }

        return 'final_evaluation_90d';
    }

    /**
     * @param array{recommendation_type:string,action_type:string,action_payload:array<string,mixed>,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int} $aiRecommendation
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildRecommendationFromAi(array $aiRecommendation, array $context): array
    {
        $pageUrl = (string)($context['page_url'] ?? '');
        $type = $this->normalizeRecommendationType($aiRecommendation['recommendation_type']);
        $actionType = $this->normalizeActionType($aiRecommendation['action_type']);
        $actionPayload = $this->normalizeActionPayload(
            $aiRecommendation['action_payload'],
            $actionType,
            (int)($context['page_uid'] ?? 0),
            $aiRecommendation['proposed_seo_title'],
            $aiRecommendation['proposed_description']
        );
        $query = $aiRecommendation['query_text'];

        return [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'page_uid' => (int)($context['page_uid'] ?? 0),
            'page_url' => $pageUrl,
            'query_text' => $query,
            'recommendation_type' => 'ai_' . $type,
            'priority' => $aiRecommendation['priority'],
            'status' => 'draft',
            'issue' => $aiRecommendation['issue'],
            'recommendation' => $aiRecommendation['recommendation'],
            'action_type' => $actionType,
            'action_payload_json' => $this->json($actionPayload),
            'apply_capability' => $this->applyCapabilityForAction($actionType, $actionPayload),
            'proposed_seo_title' => $aiRecommendation['proposed_seo_title'],
            'proposed_description' => $aiRecommendation['proposed_description'],
            'evidence_json' => json_encode([
                'generation_mode' => 'ai',
                'model' => $this->openAiRecommendationService->getModel(),
                'page_url' => $pageUrl,
                'query_text' => $query,
                'search_console_queries' => $context['search_console_queries'] ?? [],
                'rendered_issues' => $context['rendered_page']['issues'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ai_model' => $this->openAiRecommendationService->getModel(),
            'dedupe_hash' => hash('sha256', implode('|', [
                'ai',
                $type,
                $pageUrl,
                $query,
                mb_substr($aiRecommendation['issue'], 0, 160),
            ])),
            'applied_changes_json' => '',
            'verification_status' => 'not_checked',
            'verification_json' => '',
            'approved_at' => 0,
            'applied_at' => 0,
            'verified_at' => 0,
        ];
    }

    private function normalizeRecommendationType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/[^a-z0-9_]+/', '_', $type) ?? 'recommendation';
        $type = trim($type, '_');

        return $type !== '' ? mb_substr($type, 0, 48) : 'recommendation';
    }

    private function normalizeActionType(string $actionType): string
    {
        $actionType = strtolower(trim($actionType));
        $allowed = [
            'metadata_update',
            'content_gap_brief',
            'internal_link_suggestion',
            'image_alt_suggestion',
            'structured_data_suggestion',
            'technical_indexing_issue',
            'manual_review',
        ];

        return in_array($actionType, $allowed, true) ? $actionType : 'manual_review';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeActionPayload(array $payload, string $actionType, int $pageUid, string $proposedTitle = '', string $proposedDescription = ''): array
    {
        $targetTable = trim((string)($payload['target_table'] ?? ''));
        if ($actionType === 'metadata_update' && $targetTable === '') {
            $targetTable = 'pages';
        }
        $targetUid = (int)($payload['target_uid'] ?? 0);
        if ($targetUid <= 0) {
            $targetUid = $pageUid;
        }

        return [
            'target_table' => $targetTable,
            'target_uid' => max(0, $targetUid),
            'seo_title' => mb_substr(trim((string)($payload['seo_title'] ?? $proposedTitle)), 0, 60),
            'description' => mb_substr(trim((string)($payload['description'] ?? $proposedDescription)), 0, 155),
            'content_brief' => mb_substr(trim((string)($payload['content_brief'] ?? '')), 0, 1800),
            'content_element_header' => mb_substr(trim((string)($payload['content_element_header'] ?? '')), 0, 120),
            'content_body_html' => mb_substr(trim((string)($payload['content_body_html'] ?? '')), 0, 5000),
            'suggested_headings' => $this->normalizeStringList($payload['suggested_headings'] ?? [], 8, 120),
            'suggested_links' => $this->normalizeSuggestionRows($payload['suggested_links'] ?? [], ['source_url', 'target_url', 'anchor_text', 'reason'], 8),
            'image_alt_suggestions' => $this->normalizeSuggestionRows($payload['image_alt_suggestions'] ?? [], ['src', 'alt_text', 'reason'], 12),
            'structured_data_type' => mb_substr(trim((string)($payload['structured_data_type'] ?? '')), 0, 80),
            'structured_data_preview' => mb_substr(trim((string)($payload['structured_data_preview'] ?? '')), 0, 2200),
            'technical_steps' => $this->normalizeStringList($payload['technical_steps'] ?? [], 8, 220),
        ];
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function normalizeStringList($items, int $limit, int $itemLength): array
    {
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            $value = mb_substr(trim((string)$item), 0, $itemLength);
            if ($value !== '') {
                $values[] = $value;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    /**
     * @param mixed $items
     * @param list<string> $columns
     * @return list<array<string,string>>
     */
    private function normalizeSuggestionRows($items, array $columns, int $limit): array
    {
        if (!is_array($items)) {
            return [];
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            $hasUsefulValue = false;
            foreach ($columns as $column) {
                $limitLength = str_contains($column, 'url') || $column === 'src' ? 2048 : 260;
                $value = mb_substr(trim((string)($item[$column] ?? '')), 0, $limitLength);
                $row[$column] = $value;
                $hasUsefulValue = $hasUsefulValue || $value !== '';
            }
            if ($hasUsefulValue) {
                $rows[] = $row;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function applyCapabilityForAction(string $actionType, array $payload): string
    {
        if (
            $actionType === 'metadata_update'
            && (string)($payload['target_table'] ?? '') === 'pages'
            && ((string)($payload['seo_title'] ?? '') !== '' || (string)($payload['description'] ?? '') !== '')
        ) {
            return 'safe_metadata';
        }

        if (
            $actionType === 'content_gap_brief'
            && (
                (string)($payload['content_body_html'] ?? '') !== ''
                || (string)($payload['content_brief'] ?? '') !== ''
                || ($payload['suggested_headings'] ?? []) !== []
            )
        ) {
            return 'content_draft';
        }

        if (
            $actionType === 'image_alt_suggestion'
            && $this->hasImageAltSuggestions($payload)
        ) {
            return 'image_alt';
        }

        return 'manual';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasImageAltSuggestions(array $payload): bool
    {
        foreach ((array)($payload['image_alt_suggestions'] ?? []) as $suggestion) {
            if (
                is_array($suggestion)
                && trim((string)($suggestion['src'] ?? '')) !== ''
                && trim((string)($suggestion['alt_text'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param array<string,array<string,mixed>> $snapshots
     * @param array<string,array<string,mixed>> $renderedSnapshots
     */
    private function generateRuleBasedRecommendations(array $candidates, array $snapshots, array $renderedSnapshots): int
    {
        $stored = 0;
        foreach ($candidates as $candidate) {
            $pageUrl = (string)($candidate['page_url'] ?? '');
            $query = trim((string)($candidate['query_text'] ?? ''));
            if ($pageUrl === '' || $query === '') {
                continue;
            }

            $normalizedUrl = $this->urlNormalizer->normalize($pageUrl);
            $recommendation = $this->buildRuleBasedRecommendation(
                $candidate,
                $snapshots[$normalizedUrl] ?? null,
                $renderedSnapshots[$normalizedUrl] ?? null
            );
            if ($recommendation !== null) {
                $stored += $this->storeRecommendation($recommendation);
            }
        }

        return $stored;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed>|null $snapshot
     * @param array<string,mixed>|null $renderedSnapshot
     * @return array<string,mixed>|null
     */
    private function buildRuleBasedRecommendation(array $candidate, ?array $snapshot, ?array $renderedSnapshot): ?array
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
            (string)($renderedSnapshot['html_title'] ?? ''),
            (string)($renderedSnapshot['meta_description'] ?? ''),
            (string)($renderedSnapshot['visible_text'] ?? ''),
        ]);
        $queryCovered = $this->textCoversQuery($pageText, $query);
        $descriptionMissing = trim((string)($snapshot['description'] ?? '')) === ''
            && trim((string)($renderedSnapshot['meta_description'] ?? '')) === '';

        $type = '';
        $priority = 50;
        $issue = '';
        $action = '';

        if (($snapshot !== null || $renderedSnapshot !== null) && $descriptionMissing) {
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

        $pageUid = (int)($snapshot['page_uid'] ?? 0);
        $proposedTitle = '';
        $proposedDescription = '';
        $actionType = 'content_gap_brief';
        $actionPayload = [
            'target_table' => '',
            'target_uid' => $pageUid,
            'seo_title' => '',
            'description' => '',
            'content_brief' => $this->buildContentBrief($query, $type, $position, $impressions, $ctr),
            'content_element_header' => '',
            'content_body_html' => '',
            'suggested_headings' => $this->buildSuggestedHeadings($query),
            'suggested_links' => [],
            'image_alt_suggestions' => [],
            'structured_data_type' => '',
            'structured_data_preview' => '',
            'technical_steps' => [],
        ];

        if (in_array($type, ['missing_meta_description', 'low_ctr'], true)) {
            $actionType = 'metadata_update';
            $proposedTitle = $type === 'low_ctr' ? $this->buildProposedTitle($query, $snapshot, $renderedSnapshot) : '';
            $proposedDescription = $this->buildProposedDescription($query);
            $actionPayload = $this->normalizeActionPayload(
                [
                    'target_table' => 'pages',
                    'target_uid' => $pageUid,
                    'seo_title' => $proposedTitle,
                    'description' => $proposedDescription,
                ],
                $actionType,
                $pageUid,
                $proposedTitle,
                $proposedDescription
            );
        }

        return [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'page_uid' => $pageUid,
            'page_url' => $pageUrl,
            'query_text' => $query,
            'recommendation_type' => $type,
            'priority' => $priority,
            'status' => 'draft',
            'issue' => $issue,
            'recommendation' => $action,
            'action_type' => $actionType,
            'action_payload_json' => $this->json($actionPayload),
            'apply_capability' => $this->applyCapabilityForAction($actionType, $actionPayload),
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
            'applied_changes_json' => '',
            'verification_status' => 'not_checked',
            'verification_json' => '',
            'approved_at' => 0,
            'applied_at' => 0,
            'verified_at' => 0,
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
     * @param array<string,mixed>|null $renderedSnapshot
     */
    private function buildProposedTitle(string $query, ?array $snapshot, ?array $renderedSnapshot = null): string
    {
        $baseTitle = trim((string)($snapshot['seo_title'] ?? ''));
        if ($baseTitle === '') {
            $baseTitle = trim((string)($snapshot['title'] ?? ''));
        }
        if ($baseTitle === '') {
            $baseTitle = trim((string)($renderedSnapshot['html_title'] ?? ''));
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

    private function buildContentBrief(string $query, string $type, float $position, float $impressions, float $ctr): string
    {
        $brief = 'Redaktionell pruefen: Die Seite erzeugt Sichtbarkeit fuer "' . $query . '", beantwortet die Suchintention aber noch nicht klar genug.';
        if ($type === 'striking_distance') {
            $brief = 'Striking-distance Chance: Die Seite rankt fuer "' . $query . '" im erreichbaren Bereich. Einen konkreten Abschnitt ergaenzen, der Zielgruppe, Problem, Loesungsweg und naechsten Schritt klar beschreibt.';
        }
        if ($type === 'content_gap') {
            $brief = 'Content Gap: Die Anfrage "' . $query . '" ist in Title, Description, H1/H2 oder sichtbarem Text nicht klar genug abgedeckt. Einen hilfreichen Abschnitt mit natuerlicher Begriffsnutzung ergaenzen.';
        }

        return $brief . ' Daten: Position ' . round($position, 1) . ', Impressionen ' . (int)$impressions . ', CTR ' . round($ctr * 100, 2) . '%.';
    }

    /**
     * @return list<string>
     */
    private function buildSuggestedHeadings(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $label = ucfirst($query);
        return [
            $label . ': worauf Unternehmen achten sollten',
            'Wann sich ' . $query . ' lohnt',
            'Wie WALDBYTE bei ' . $query . ' unterstuetzt',
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $renderedSnapshots
     * @param array<string,array<string,mixed>> $cmsSnapshots
     */
    private function generateRenderedRecommendations(array $renderedSnapshots, array $cmsSnapshots): int
    {
        $stored = 0;
        foreach ($renderedSnapshots as $normalizedUrl => $renderedSnapshot) {
            $issues = json_decode((string)($renderedSnapshot['issues_json'] ?? '[]'), true);
            if (!is_array($issues) || $issues === []) {
                continue;
            }

            $cmsSnapshot = $cmsSnapshots[$normalizedUrl] ?? null;
            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $recommendation = $this->buildRenderedRecommendation($renderedSnapshot, $cmsSnapshot, $issue);
                if ($recommendation !== null) {
                    $stored += $this->storeRecommendation($recommendation);
                }
            }
        }

        return $stored;
    }

    /**
     * @param array<string,mixed> $renderedSnapshot
     * @param array<string,mixed>|null $cmsSnapshot
     * @param array<string,mixed> $issue
     * @return array<string,mixed>|null
     */
    private function buildRenderedRecommendation(array $renderedSnapshot, ?array $cmsSnapshot, array $issue): ?array
    {
        $code = (string)($issue['code'] ?? '');
        $url = (string)($renderedSnapshot['url'] ?? '');
        if ($code === '' || $url === '') {
            return null;
        }

        $map = [
            'http_error' => [99, 'Rendered URL returns an HTTP error.', 'Check routing, redirects, publication state and server response for this URL.'],
            'fetch_failed' => [99, 'Rendered URL could not be fetched.', 'Check whether the URL is reachable from the server and not blocked by routing or TLS issues.'],
            'noindex' => [98, 'Rendered robots meta contains noindex.', 'Remove noindex if this page should be visible in Google.'],
            'missing_title' => [96, 'Rendered page has no title tag.', 'Set a clear SEO title for this page.'],
            'missing_meta_description' => [94, 'Rendered page has no meta description.', 'Add a specific meta description that explains the page value and search intent.'],
            'missing_h1' => [90, 'Rendered page has no H1.', 'Add one clear primary heading in the relevant content element/template.'],
            'multiple_h1' => [68, 'Rendered page has multiple H1 headings.', 'Keep one primary H1 and convert secondary headings to H2/H3.'],
            'missing_image_alt' => [72, 'Rendered page has images without alt text.', 'Add descriptive alt text for content images; decorative images should be handled intentionally.'],
            'thin_content' => [64, 'Rendered page has thin visible text.', 'Add useful, query-focused content so the page can answer client questions directly.'],
            'long_title' => [55, 'Rendered title is too long.', 'Shorten the SEO title so the main phrase and brand remain clear.'],
            'long_meta_description' => [52, 'Rendered meta description is too long.', 'Shorten the description to keep the important value proposition visible in search results.'],
            'missing_canonical' => [42, 'Rendered page has no canonical URL.', 'Add or verify canonical handling for this page.'],
            'missing_structured_data' => [35, 'Rendered page has no JSON-LD structured data.', 'Add appropriate Organization, WebSite, Article, Breadcrumb or LocalBusiness schema where relevant.'],
        ];

        if (!isset($map[$code])) {
            return null;
        }

        [$priority, $defaultIssue, $action] = $map[$code];
        $issueText = trim((string)($issue['message'] ?? ''));
        if ($issueText === '') {
            $issueText = $defaultIssue;
        }

        $pageUid = (int)($cmsSnapshot['page_uid'] ?? 0);
        $proposedTitle = $code === 'missing_title' ? mb_substr((string)($cmsSnapshot['title'] ?? 'WALDBYTE'), 0, 60) : '';
        $proposedDescription = $code === 'missing_meta_description'
            ? 'WALDBYTE entwickelt TYPO3 Websites, Webdesign und Onlineshops fuer Unternehmen in der Region Karlsruhe.'
            : '';
        $actionType = match ($code) {
            'missing_title', 'missing_meta_description', 'long_title', 'long_meta_description' => 'metadata_update',
            'missing_image_alt' => 'image_alt_suggestion',
            'missing_structured_data' => 'structured_data_suggestion',
            'thin_content', 'missing_h1', 'multiple_h1' => 'content_gap_brief',
            default => 'technical_indexing_issue',
        };
        if ($code === 'long_title') {
            $proposedTitle = $this->shortenMetadata((string)($renderedSnapshot['html_title'] ?? ''), 60);
        }
        if ($code === 'long_meta_description') {
            $proposedDescription = $this->shortenMetadata((string)($renderedSnapshot['meta_description'] ?? ''), 155);
        }
        $actionPayload = $this->buildRenderedActionPayload(
            $code,
            $actionType,
            $pageUid,
            $url,
            $proposedTitle,
            $proposedDescription,
            $renderedSnapshot
        );

        return [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'page_uid' => $pageUid,
            'page_url' => $url,
            'query_text' => '',
            'recommendation_type' => 'rendered_' . $code,
            'priority' => $priority,
            'status' => 'draft',
            'issue' => $issueText,
            'recommendation' => $action,
            'action_type' => $actionType,
            'action_payload_json' => $this->json($actionPayload),
            'apply_capability' => $this->applyCapabilityForAction($actionType, $actionPayload),
            'proposed_seo_title' => $proposedTitle,
            'proposed_description' => $proposedDescription,
            'evidence_json' => json_encode([
                'rendered_url' => $url,
                'http_status' => (int)($renderedSnapshot['http_status'] ?? 0),
                'word_count' => (int)($renderedSnapshot['word_count'] ?? 0),
                'h1_count' => (int)($renderedSnapshot['h1_count'] ?? 0),
                'image_count' => (int)($renderedSnapshot['image_count'] ?? 0),
                'missing_alt_count' => (int)($renderedSnapshot['missing_alt_count'] ?? 0),
                'issue' => $issue,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ai_model' => '',
            'dedupe_hash' => hash('sha256', implode('|', ['rendered', $code, $url])),
            'applied_changes_json' => '',
            'verification_status' => 'not_checked',
            'verification_json' => '',
            'approved_at' => 0,
            'applied_at' => 0,
            'verified_at' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $renderedSnapshot
     * @return array<string,mixed>
     */
    private function buildRenderedActionPayload(
        string $code,
        string $actionType,
        int $pageUid,
        string $url,
        string $proposedTitle,
        string $proposedDescription,
        array $renderedSnapshot,
    ): array {
        $payload = [
            'target_table' => $actionType === 'metadata_update' ? 'pages' : '',
            'target_uid' => $pageUid,
            'seo_title' => $proposedTitle,
            'description' => $proposedDescription,
            'content_brief' => '',
            'content_element_header' => '',
            'content_body_html' => '',
            'suggested_headings' => [],
            'suggested_links' => [],
            'image_alt_suggestions' => [],
            'structured_data_type' => '',
            'structured_data_preview' => '',
            'technical_steps' => [],
        ];

        if ($actionType === 'content_gap_brief') {
            $payload['content_brief'] = match ($code) {
                'missing_h1' => 'Eine eindeutige H1 fuer die Seite ergaenzen. Sie sollte Thema, Leistung und Suchintention klar zusammenfassen.',
                'multiple_h1' => 'Headings pruefen: eine Haupt-H1 behalten und weitere Hauptueberschriften in H2/H3 umwandeln.',
                default => 'Sichtbaren Hauptinhalt ausbauen. Die Seite hat aktuell wenig Text und braucht konkrete Nutzenargumente, Leistungsdetails, Ablauf oder FAQ-Antworten.',
            };
            $title = trim((string)($renderedSnapshot['html_title'] ?? ''));
            $payload['suggested_headings'] = $title !== '' ? [$this->shortenMetadata($title, 90)] : [];
            $payload['content_element_header'] = $payload['suggested_headings'][0] ?? '';
        }

        if ($actionType === 'image_alt_suggestion') {
            $payload['content_brief'] = 'Fehlende Alt-Texte fuer Inhaltsbilder im TYPO3 Backend pruefen. Dekorative Bilder sollten bewusst leer bleiben, Inhaltsbilder brauchen beschreibende Alt-Texte.';
            $payload['image_alt_suggestions'] = $this->buildMissingAltPayload($renderedSnapshot);
        }

        if ($actionType === 'structured_data_suggestion') {
            $schemaType = $this->guessStructuredDataType($url);
            $payload['structured_data_type'] = $schemaType;
            $payload['structured_data_preview'] = 'Schema-Vorschlag ueber den SitePackage StructuredDataRenderer umsetzen: ' . $schemaType . ' fuer ' . $url . '. Vor Ausgabe JSON-LD validieren.';
        }

        if ($actionType === 'technical_indexing_issue') {
            $payload['technical_steps'] = match ($code) {
                'http_error' => ['HTTP Status pruefen', 'TYPO3 Routing und Seitensichtbarkeit pruefen', 'Redirect oder Veroeffentlichungsstatus korrigieren'],
                'fetch_failed' => ['Server-Erreichbarkeit pruefen', 'TLS/Firewall/Crawler-Zugriff pruefen', 'URL nach Korrektur erneut rendern'],
                'noindex' => ['Klaeren, ob die Seite indexiert werden soll', 'no_index im Seitenrecord oder Template entfernen', 'Nach Deployment gerenderten Robots-Meta erneut pruefen'],
                'missing_canonical' => ['Canonical-Ausgabe im Template pruefen', 'Kanonsche URL fuer diese Seite bestimmen', 'Nach Korrektur gerenderte Seite erneut snapshotten'],
                default => ['Technisches SEO-Problem im Template oder Seitenrecord pruefen', 'Korrektur deployen', 'Rendered Snapshot erneut ausfuehren'],
            };
        }

        return $this->normalizeActionPayload($payload, $actionType, $pageUid, $proposedTitle, $proposedDescription);
    }

    /**
     * @param array<string,mixed> $renderedSnapshot
     * @return list<array<string,string>>
     */
    private function buildMissingAltPayload(array $renderedSnapshot): array
    {
        $images = json_decode((string)($renderedSnapshot['images_json'] ?? '[]'), true);
        if (!is_array($images)) {
            return [];
        }

        $suggestions = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['missing_alt'])) {
                continue;
            }
            $suggestions[] = [
                'src' => (string)($image['src'] ?? ''),
                'alt_text' => '',
                'reason' => 'Rendered image has an empty alt attribute. Review the image context before writing alt text.',
            ];
            if (count($suggestions) >= 12) {
                break;
            }
        }

        return $suggestions;
    }

    private function guessStructuredDataType(string $url): string
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        if (str_contains($path, 'blog') || str_contains($path, 'artikel')) {
            return 'BlogPosting';
        }
        if (str_contains($path, 'leistungen') || str_contains($path, 'technologien')) {
            return 'Service';
        }

        return 'WebPage';
    }

    private function shortenMetadata(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 1))) . '...';
    }

    /**
     * @param mixed $value
     */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
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
            'verified_at' => Connection::PARAM_INT,
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
